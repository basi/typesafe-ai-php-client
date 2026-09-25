<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\ClientOptions;
use TypesafeAi\Limits;

final class ClientOptionsTest extends TestCase
{
    #[Test]
    public function usesDocumentedDefaults(): void
    {
        $options = new ClientOptions();

        self::assertSame('https://api.typesafe.ai', $options->baseUrl);
        self::assertSame(Limits::DEFAULT_MODEL, $options->defaultModel);
        self::assertSame(30, $options->timeoutSeconds);
        self::assertSame(5, $options->connectTimeoutSeconds);
        self::assertSame(0, $options->maxRetries);
        self::assertSame(2, $options->modelsMaxRetries);
        self::assertTrue($options->retryRateLimitedPost);
        self::assertNull($options->maxRetryDelayMs);
    }

    #[Test]
    public function trimsATrailingSlashFromBaseUrl(): void
    {
        $options = new ClientOptions(baseUrl: 'https://example.test/');

        self::assertSame('https://example.test', $options->baseUrl);
    }

    #[Test]
    public function rejectsAnEmptyBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(baseUrl: '   ');
    }

    #[Test]
    public function rejectsANonAbsoluteBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(baseUrl: '/relative/path');
    }

    #[Test]
    public function rejectsAnEmptyDefaultModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(defaultModel: '');
    }

    #[Test]
    public function rejectsNegativeTimeoutSeconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(timeoutSeconds: -1);
    }

    #[Test]
    public function rejectsAZeroTimeoutSeconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(timeoutSeconds: 0);
    }

    #[Test]
    public function acceptsATimeoutSecondsOfOne(): void
    {
        $options = new ClientOptions(timeoutSeconds: 1);

        self::assertSame(1, $options->timeoutSeconds);
    }

    #[Test]
    public function rejectsAZeroConnectTimeoutSeconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(connectTimeoutSeconds: 0);
    }

    #[Test]
    public function acceptsAConnectTimeoutSecondsOfOne(): void
    {
        $options = new ClientOptions(connectTimeoutSeconds: 1);

        self::assertSame(1, $options->connectTimeoutSeconds);
    }

    #[Test]
    public function rejectsNegativeMaxRetries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(maxRetries: -1);
    }

    #[Test]
    public function rejectsANegativeMaxRetryDelayMs(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ClientOptions(maxRetryDelayMs: -1);
    }

    #[Test]
    public function acceptsAZeroMaxRetryDelayMs(): void
    {
        $options = new ClientOptions(maxRetryDelayMs: 0);

        self::assertSame(0, $options->maxRetryDelayMs);
    }
}
