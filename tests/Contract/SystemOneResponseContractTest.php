<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;
use TypesafeAi\Response\SystemOneResponse;

/**
 * Hydrates recorded API responses and a synthetic fixture built from the OpenAPI spec, to guard
 * against regressions in response parsing against real payload shapes.
 */
final class SystemOneResponseContractTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function recordedFixtureProvider(): iterable
    {
        $paths = glob(__DIR__ . '/fixtures/recorded/*.json');
        self::assertNotFalse($paths);
        sort($paths);

        foreach ($paths as $path) {
            yield basename($path) => [$path];
        }
    }

    #[Test]
    #[DataProvider('recordedFixtureProvider')]
    public function recordedResponseHydrates(string $path): void
    {
        $body = file_get_contents($path);
        self::assertNotFalse($body);

        $response = SystemOneResponse::fromJson($body);

        self::assertNotSame('', $response->model());
        self::assertNotEmpty($response->answers());

        foreach ($response->answers() as $name => $answer) {
            self::assertTrue(
                $answer instanceof NoulAnswer || $answer instanceof ScoreAnswer,
                sprintf(
                    'answer "%s" in %s should be a NoulAnswer or ScoreAnswer, got %s',
                    $name,
                    basename($path),
                    $answer::class,
                ),
            );

            if ($answer instanceof NoulAnswer) {
                // noul()'s native `float` return type already guarantees the runtime type;
                // gettype() (a plain string comparison, unlike assertIsFloat()) still exercises
                // that guarantee without PHPStan treating the check itself as dead code.
                self::assertSame('double', gettype($answer->noul()));
            }

            if ($answer instanceof ScoreAnswer) {
                self::assertSame('double', gettype($answer->score()));
                self::assertSame('double', gettype($answer->confidence()));

                // Probability and legend keys are level indices ("0", "1", ...). PHP normalises
                // numeric-looking string array keys to integers, so a literal string key is not
                // achievable here; what matters is that both lookup forms reach the same value.
                foreach ($answer->probabilities() as $key => $probability) {
                    self::assertSame('double', gettype($probability));
                    self::assertArrayHasKey((string) $key, $answer->probabilities());
                    self::assertArrayHasKey((int) $key, $answer->probabilities());
                }

                self::assertNotEmpty($answer->legend());
            }
        }

        self::assertGreaterThanOrEqual(0, $response->usage()->inputTokens());
        self::assertGreaterThanOrEqual(0, $response->usage()->outputTokens());
    }

    #[Test]
    public function syntheticChoiceResponseHydrates(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/synthetic/choice-response.json');
        self::assertNotFalse($body);

        $response = SystemOneResponse::fromJson($body);

        self::assertNotSame('', $response->model());

        foreach ($response->answers() as $answer) {
            self::assertInstanceOf(ChoiceAnswer::class, $answer);
            self::assertSame('double', gettype($answer->confidence()));

            foreach ($answer->probabilities() as $key => $probability) {
                self::assertSame('string', gettype($key));
                self::assertSame('double', gettype($probability));
            }
        }

        self::assertGreaterThanOrEqual(0, $response->usage()->inputTokens());
        self::assertGreaterThanOrEqual(0, $response->usage()->outputTokens());
    }
}
