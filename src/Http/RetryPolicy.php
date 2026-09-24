<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

use TypesafeAi\Exception\TransportException;

/**
 * Generic HTTP retry mechanics: which statuses and exceptions are worth retrying in principle,
 * and how long to wait before the next attempt.
 *
 * This class does not decide how many retries a given request is allowed — see
 * {@see \TypesafeAi\ClientOptions} and {@see \TypesafeAi\Client} for that policy, which differs by
 * HTTP method. It only answers two generic questions: "is this kind of failure retryable at all",
 * and "how long should the next attempt wait".
 *
 * Mirrors the official SDK: retryable statuses are 408, 429, and 5xx (including 529
 * "overloaded"); retryable exceptions are connection and timeout failures, represented here by
 * {@see TransportException} (and its subclass {@see \TypesafeAi\Exception\TimeoutException}). The
 * wait prefers the server's `retry-after-ms` response header, then `Retry-After` (either an
 * integer number of seconds or an HTTP-date), unless that asks for more than 60 seconds — in which
 * case exponential backoff is used instead: 0.5s doubling on each attempt up to a 5s cap, minus up
 * to 25% random jitter.
 */
final class RetryPolicy
{
    private const float INITIAL_DELAY_SECONDS = 0.5;
    private const float MAX_DELAY_SECONDS = 5.0;
    private const float MAX_JITTER_FRACTION = 0.25;
    private const int MAX_SERVER_DELAY_MILLISECONDS = 60_000;

    private readonly \Closure $sleeper;

    private readonly \Closure $random;

    private readonly \Closure $clock;

    /**
     * @param ?\Closure(int): void $sleeper Sleeps for the given number of milliseconds. Defaults
     *     to a real `usleep()`.
     * @param ?\Closure(): float $random Returns a random float in the range [0, 1). Defaults to
     *     `mt_rand()`-based randomness.
     * @param ?\Closure(): \DateTimeImmutable $clock Returns the current time, used to resolve an
     *     HTTP-date `Retry-After` header. Defaults to the real current time.
     */
    public function __construct(?\Closure $sleeper = null, ?\Closure $random = null, ?\Closure $clock = null)
    {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
        $this->random = $random ?? static fn (): float => mt_rand() / mt_getrandmax();
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Whether this HTTP status code is, in principle, worth retrying.
     */
    public function isRetryableStatusCode(int $statusCode): bool
    {
        return $statusCode === 408 || $statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599);
    }

    /**
     * Whether this exception is, in principle, worth retrying. Both connection failures
     * ({@see TransportException}) and timeouts (its subclass
     * {@see \TypesafeAi\Exception\TimeoutException}) qualify.
     */
    public function isRetryableException(\Throwable $exception): bool
    {
        return $exception instanceof TransportException;
    }

    /**
     * Milliseconds to wait before the next attempt.
     *
     * @param int $attempt Zero-based count of attempts already made.
     * @param array<string, string> $lowercaseHeaders Response headers from the failed attempt,
     *     keyed by lower-case header name. Empty when the failure did not produce a response (a
     *     connection or timeout exception).
     */
    public function delayMilliseconds(int $attempt, array $lowercaseHeaders = []): int
    {
        $serverDelay = $this->parseServerDelayMilliseconds($lowercaseHeaders);
        if ($serverDelay !== null && $serverDelay <= self::MAX_SERVER_DELAY_MILLISECONDS) {
            return $serverDelay;
        }

        return $this->backoffMilliseconds($attempt);
    }

    /**
     * Sleeps for the given number of milliseconds, using the injected sleeper.
     */
    public function sleep(int $milliseconds): void
    {
        ($this->sleeper)($milliseconds);
    }

    private function backoffMilliseconds(int $attempt): int
    {
        $delaySeconds = min(self::INITIAL_DELAY_SECONDS * (2 ** max(0, $attempt)), self::MAX_DELAY_SECONDS);
        $jitterFraction = ($this->random)() * self::MAX_JITTER_FRACTION;

        return (int) round($delaySeconds * (1.0 - $jitterFraction) * 1000);
    }

    /**
     * @param array<string, string> $lowercaseHeaders
     */
    private function parseServerDelayMilliseconds(array $lowercaseHeaders): ?int
    {
        $retryAfterMs = isset($lowercaseHeaders['retry-after-ms']) ? trim($lowercaseHeaders['retry-after-ms']) : null;
        if ($retryAfterMs !== null && $retryAfterMs !== '' && ctype_digit($retryAfterMs)) {
            return (int) $retryAfterMs;
        }

        $retryAfter = isset($lowercaseHeaders['retry-after']) ? trim($lowercaseHeaders['retry-after']) : null;
        if ($retryAfter === null || $retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return ((int) $retryAfter) * 1000;
        }

        $target = strtotime($retryAfter);
        if ($target === false) {
            return null;
        }

        $now = ($this->clock)()->getTimestamp();

        return max(0, ($target - $now) * 1000);
    }
}
