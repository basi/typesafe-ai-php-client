<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Testing;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Testing\MockHttpClient;

final class MockHttpClientTest extends TestCase
{
    #[Test]
    public function replaysQueuedResponsesInOrder(): void
    {
        $first = JsonResponse::fromArray(200, ['n' => 1]);
        $second = JsonResponse::fromArray(500, ['n' => 2]);
        $mock = new MockHttpClient([$first, $second]);

        $response1 = $mock->sendRequest(new Request('GET', 'https://api.typesafe.ai/v1/models'));
        $response2 = $mock->sendRequest(new Request('GET', 'https://api.typesafe.ai/v1/models'));

        self::assertSame($first, $response1);
        self::assertSame($second, $response2);
    }

    #[Test]
    public function throwsTheQueuedThrowableInsteadOfReturningIt(): void
    {
        $exception = new \RuntimeException('connection refused');
        $mock = new MockHttpClient([$exception]);

        $this->expectExceptionObject($exception);

        $mock->sendRequest(new Request('GET', 'https://api.typesafe.ai/v1/models'));
    }

    #[Test]
    public function recordsEveryRequestSent(): void
    {
        $mock = new MockHttpClient([
            JsonResponse::fromArray(200, []),
            JsonResponse::fromArray(200, []),
        ]);

        $first = new Request('GET', 'https://api.typesafe.ai/v1/models');
        $second = new Request('POST', 'https://api.typesafe.ai/v1/systemone');
        $mock->sendRequest($first);
        $mock->sendRequest($second);

        self::assertSame([$first, $second], $mock->requests());
        self::assertSame($second, $mock->lastRequest());
    }

    #[Test]
    public function lastRequestIsNullBeforeAnyRequestIsSent(): void
    {
        $mock = new MockHttpClient();

        self::assertNull($mock->lastRequest());
        self::assertSame([], $mock->requests());
    }

    #[Test]
    public function throwsAClearExceptionWhenTheQueueIsEmpty(): void
    {
        $mock = new MockHttpClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/queue is empty/');

        $mock->sendRequest(new Request('GET', 'https://api.typesafe.ai/v1/models'));
    }

    #[Test]
    public function queueAppendsAdditionalResponses(): void
    {
        $mock = new MockHttpClient();
        $response = JsonResponse::fromArray(200, []);

        $mock->queue($response);

        self::assertSame(1, $mock->remaining());
        self::assertSame($response, $mock->sendRequest(new Request('GET', 'https://api.typesafe.ai/v1/models')));
        self::assertSame(0, $mock->remaining());
    }
}
