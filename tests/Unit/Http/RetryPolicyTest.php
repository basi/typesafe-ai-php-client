<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\TimeoutException;
use TypesafeAi\Exception\TransportException;
use TypesafeAi\Http\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    private static function noJitter(): RetryPolicy
    {
        return new RetryPolicy(random: static fn (): float => 0.0);
    }

    private static function maxJitter(): RetryPolicy
    {
        // A random() implementation is documented to return a value in [0, 1); 1.0 itself is out
        // of range, but using it here proves the jitter never exceeds the documented 25% ceiling.
        return new RetryPolicy(random: static fn (): float => 1.0);
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function statusCodeProvider(): iterable
    {
        yield '200 is not retryable' => [200, false];
        yield '400 is not retryable' => [400, false];
        yield '401 is not retryable' => [401, false];
        yield '403 is not retryable' => [403, false];
        yield '404 is not retryable' => [404, false];
        yield '408 is retryable' => [408, true];
        yield '422 is not retryable' => [422, false];
        yield '429 is retryable' => [429, true];
        yield '500 is retryable' => [500, true];
        yield '502 is retryable' => [502, true];
        yield '503 is retryable' => [503, true];
        yield '504 is retryable' => [504, true];
        yield '529 is retryable' => [529, true];
        yield '599 is retryable' => [599, true];
    }

    #[Test]
    #[DataProvider('statusCodeProvider')]
    public function isRetryableStatusCodeMatchesTheOfficialSdk(int $statusCode, bool $expected): void
    {
        self::assertSame($expected, self::noJitter()->isRetryableStatusCode($statusCode));
    }

    #[Test]
    public function transportExceptionIsRetryable(): void
    {
        self::assertTrue(self::noJitter()->isRetryableException(new TransportException('boom')));
    }

    #[Test]
    public function timeoutExceptionIsRetryable(): void
    {
        self::assertTrue(self::noJitter()->isRetryableException(new TimeoutException('boom')));
    }

    #[Test]
    public function anUnrelatedExceptionIsNotRetryable(): void
    {
        self::assertFalse(self::noJitter()->isRetryableException(new \RuntimeException('boom')));
    }

    #[Test]
    public function backoffDoublesFromFiveHundredMillisecondsWithoutJitter(): void
    {
        $policy = self::noJitter();

        self::assertSame(500, $policy->delayMilliseconds(0));
        self::assertSame(1000, $policy->delayMilliseconds(1));
        self::assertSame(2000, $policy->delayMilliseconds(2));
        self::assertSame(4000, $policy->delayMilliseconds(3));
    }

    #[Test]
    public function backoffIsCappedAtFiveSeconds(): void
    {
        $policy = self::noJitter();

        self::assertSame(5000, $policy->delayMilliseconds(4));
        self::assertSame(5000, $policy->delayMilliseconds(10));
    }

    #[Test]
    public function jitterNeverReducesTheDelayByMoreThanTwentyFivePercent(): void
    {
        $policy = self::maxJitter();

        // 500ms base for attempt 0, minus the maximum 25% jitter.
        self::assertSame(375, $policy->delayMilliseconds(0));
        // 5000ms cap for a high attempt count, minus the maximum 25% jitter.
        self::assertSame(3750, $policy->delayMilliseconds(10));
    }

    #[Test]
    public function jitterFractionIsAppliedProportionally(): void
    {
        $policy = new RetryPolicy(random: static fn (): float => 0.5);

        // 500ms base, minus 12.5% (half of the 25% ceiling).
        self::assertSame(438, $policy->delayMilliseconds(0));
    }

    #[Test]
    public function retryAfterMsHeaderTakesPrecedenceOverEverything(): void
    {
        $policy = self::noJitter();

        $delay = $policy->delayMilliseconds(0, ['retry-after-ms' => '1500', 'retry-after' => '30']);

        self::assertSame(1500, $delay);
    }

    #[Test]
    public function retryAfterInSecondsIsConvertedToMilliseconds(): void
    {
        $policy = self::noJitter();

        self::assertSame(3000, $policy->delayMilliseconds(0, ['retry-after' => '3']));
    }

    #[Test]
    public function retryAfterAsAnHttpDateIsResolvedAgainstTheInjectedClock(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $policy = new RetryPolicy(random: static fn (): float => 0.0, clock: static fn (): \DateTimeImmutable => $now);

        $target = $now->modify('+5 seconds')->format('D, d M Y H:i:s') . ' GMT';

        self::assertSame(5000, $policy->delayMilliseconds(0, ['retry-after' => $target]));
    }

    #[Test]
    public function retryAfterAsAPastHttpDateNeverGoesNegative(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $policy = new RetryPolicy(random: static fn (): float => 0.0, clock: static fn (): \DateTimeImmutable => $now);

        $target = $now->modify('-5 seconds')->format('D, d M Y H:i:s') . ' GMT';

        self::assertSame(0, $policy->delayMilliseconds(0, ['retry-after' => $target]));
    }

    #[Test]
    public function serverDelaysAboveSixtySecondsAreIgnoredInFavourOfBackoff(): void
    {
        $policy = self::noJitter();

        // 61 seconds is over the 60s ceiling, so this must fall back to plain backoff for
        // attempt 0 (500ms), not honour the header.
        self::assertSame(500, $policy->delayMilliseconds(0, ['retry-after' => '61']));
    }

    #[Test]
    public function aServerDelayOfExactlySixtySecondsIsHonoured(): void
    {
        $policy = self::noJitter();

        self::assertSame(60_000, $policy->delayMilliseconds(0, ['retry-after' => '60']));
    }

    #[Test]
    public function retryAfterMsIsIgnoredWhenNotAPlainNonNegativeInteger(): void
    {
        $policy = self::noJitter();

        self::assertSame(500, $policy->delayMilliseconds(0, ['retry-after-ms' => 'soon']));
    }

    #[Test]
    public function noHeadersFallsBackToBackoff(): void
    {
        self::assertSame(500, self::noJitter()->delayMilliseconds(0, []));
    }

    #[Test]
    public function sleepInvokesTheInjectedSleeperWithTheExactValue(): void
    {
        $calls = [];
        $policy = new RetryPolicy(sleeper: static function (int $milliseconds) use (&$calls): void {
            $calls[] = $milliseconds;
        });

        $policy->sleep(1234);
        $policy->sleep(0);

        self::assertSame([1234, 0], $calls);
    }

    #[Test]
    public function defaultConstructorArgumentsWorkWithoutInjection(): void
    {
        $policy = new RetryPolicy();

        $delay = $policy->delayMilliseconds(0);

        self::assertGreaterThanOrEqual(375, $delay);
        self::assertLessThanOrEqual(500, $delay);
    }
}
