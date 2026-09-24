<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\TimeoutException;
use TypesafeAi\Exception\TransportException;
use TypesafeAi\Http\Redactor;
use TypesafeAi\Http\Transport;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Testing\MockHttpClient;

final class TransportTest extends TestCase
{
    private static function transport(MockHttpClient $mock, ?Redactor $redactor = null): Transport
    {
        $factory = new HttpFactory();

        return new Transport($mock, $factory, $factory, $redactor);
    }

    #[Test]
    public function buildsTheRequestLineHeadersAndBody(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, ['ok' => true])]);
        $transport = self::transport($mock);

        $response = $transport->send(
            'POST',
            'https://api.typesafe.ai/v1/systemone',
            ['Authorization' => 'Bearer test-key', 'Content-Type' => 'application/json'],
            '{"state":"hi"}',
        );

        self::assertSame(200, $response->getStatusCode());

        $request = $mock->lastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.typesafe.ai/v1/systemone', (string) $request->getUri());
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"state":"hi"}', (string) $request->getBody());
    }

    #[Test]
    public function sendsNoBodyWhenNoneIsGiven(): void
    {
        $mock = new MockHttpClient([JsonResponse::fromArray(200, ['ok' => true])]);
        $transport = self::transport($mock);

        $transport->send('GET', 'https://api.typesafe.ai/v1/models', ['Authorization' => 'Bearer test-key']);

        $request = $mock->lastRequest();
        self::assertNotNull($request);
        self::assertSame('', (string) $request->getBody());
    }

    #[Test]
    public function convertsANetworkExceptionIntoATransportException(): void
    {
        $mock = new MockHttpClient([
            new ConnectException(
                'Could not resolve host: api.typesafe.ai',
                new Request('GET', 'https://api.typesafe.ai/v1/models'),
            ),
        ]);
        $transport = self::transport($mock);

        try {
            $transport->send('GET', 'https://api.typesafe.ai/v1/models', []);
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertNotInstanceOf(TimeoutException::class, $exception);
            self::assertSame(0, $exception->getCode());
            self::assertStringContainsString('Could not resolve host', $exception->getMessage());
        }
    }

    #[Test]
    public function convertsACurlTimeoutIntoATimeoutException(): void
    {
        $mock = new MockHttpClient([
            new ConnectException(
                'cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received',
                new Request('POST', 'https://api.typesafe.ai/v1/systemone'),
            ),
        ]);
        $transport = self::transport($mock);

        try {
            $transport->send('POST', 'https://api.typesafe.ai/v1/systemone', [], '{}');
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $exception) {
            self::assertSame(0, $exception->getCode());
        }
    }

    #[Test]
    public function aMessageMentioningTimedOutIsAlsoTreatedAsATimeout(): void
    {
        $mock = new MockHttpClient([
            new \RuntimeException('The request timed out waiting for a response'),
        ]);
        $transport = self::transport($mock);

        $this->expectException(TimeoutException::class);

        $transport->send('GET', 'https://api.typesafe.ai/v1/models', []);
    }

    #[Test]
    public function anyOtherThrowableFromTheHttpLayerBecomesATransportException(): void
    {
        $mock = new MockHttpClient([new \RuntimeException('unexpected failure')]);
        $transport = self::transport($mock);

        try {
            $transport->send('GET', 'https://api.typesafe.ai/v1/models', []);
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getCode());
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString(\RuntimeException::class, $exception->getMessage());
            self::assertStringContainsString('unexpected failure', $exception->getMessage());
        }
    }

    #[Test]
    public function theApiKeyIsScrubbedFromAnyExceptionMessage(): void
    {
        $mock = new MockHttpClient([
            new \RuntimeException('Failed while sending header Authorization: Bearer sk-super-secret-value'),
        ]);
        $transport = self::transport($mock, new Redactor('sk-super-secret-value'));

        try {
            $transport->send(
                'GET',
                'https://api.typesafe.ai/v1/models',
                ['Authorization' => 'Bearer sk-super-secret-value'],
            );
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertStringNotContainsString('sk-super-secret-value', $exception->getMessage());
            self::assertStringContainsString('***', $exception->getMessage());
        }
    }

    #[Test]
    public function everyFormOfTheApiKeyIsRedactedAndThePreviousExceptionIsDropped(): void
    {
        $apiKey = 'sk/te"st';
        $bearer = 'Bearer ' . $apiKey;
        $jsonEscaped = substr(json_encode($apiKey, JSON_THROW_ON_ERROR), 1, -1);
        $urlEncoded = rawurlencode($apiKey);

        $original = new ConnectException(
            sprintf(
                'Connection reset. raw=%s bearer=%s json=%s url=%s',
                $apiKey,
                $bearer,
                $jsonEscaped,
                $urlEncoded,
            ),
            new Request('GET', 'https://api.typesafe.ai/v1/models'),
        );
        $mock = new MockHttpClient([$original]);
        $transport = self::transport($mock, new Redactor($apiKey));

        try {
            $transport->send('GET', 'https://api.typesafe.ai/v1/models', []);
            self::fail('Expected a TransportException.');
        } catch (TransportException $exception) {
            self::assertNotInstanceOf(TimeoutException::class, $exception);

            $message = $exception->getMessage();
            self::assertStringNotContainsString($apiKey, $message);
            self::assertStringNotContainsString($bearer, $message);
            self::assertStringNotContainsString($jsonEscaped, $message);
            self::assertStringNotContainsString($urlEncoded, $message);
            self::assertStringContainsString('***', $message);
            self::assertStringContainsString(ConnectException::class, $message);
            self::assertNull($exception->getPrevious());
        }
    }
}
