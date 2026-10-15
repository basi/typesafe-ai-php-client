<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TypesafeAi\ClientOptions;
use TypesafeAi\Http\GuzzleTransportFactory;

final class GuzzleTransportFactoryTest extends TestCase
{
    #[Test]
    public function usesTheTimeoutsOfTheOptionsAndReturnsErrorStatusesAsResponses(): void
    {
        $client = GuzzleTransportFactory::create(new ClientOptions(timeoutSeconds: 12, connectTimeoutSeconds: 3));

        self::assertSame(12, $client->getConfig('timeout'));
        self::assertSame(3, $client->getConfig('connect_timeout'));
        self::assertFalse($client->getConfig('http_errors'));
        self::assertFalse($client->getConfig('allow_redirects'));
    }

    #[Test]
    public function turnsOffExpectContinue(): void
    {
        $client = GuzzleTransportFactory::create(new ClientOptions());

        self::assertFalse($client->getConfig('expect'));
    }

    #[Test]
    public function expectContinueIsWhatGuzzleWouldOtherwiseAddToALargeBody(): void
    {
        $body = str_repeat('x', 2 * 1024 * 1024);

        // Guzzle sends "Expect: 100-Continue" with a body this big and waits for the server to
        // agree, unless the option is turned off, as the factory does. The official SDK's HTTP
        // client never sends the header.
        self::assertSame('100-Continue', self::sentThrough(['expect' => true], $body)->getHeaderLine('Expect'));
        self::assertSame('100-Continue', self::sentThrough([], $body)->getHeaderLine('Expect'));
        self::assertFalse(self::sentThrough(['expect' => false], $body)->hasHeader('Expect'));
    }

    /**
     * Sends a POST with $body through a client with the Expect option $options, whose handler
     * records the request as it would reach the network, after Guzzle's own middleware ran.
     *
     * @param array{expect?: bool} $options
     */
    private static function sentThrough(array $options, string $body): RequestInterface
    {
        $sent = null;
        $handler = new MockHandler([
            static function (RequestInterface $request) use (&$sent): Response {
                $sent = $request;

                return new Response(200);
            },
        ]);

        $client = new Client([...$options, 'handler' => HandlerStack::create($handler)]);
        $client->send(new Request('POST', 'https://api.typesafe.ai/v1/systemone', [], $body));

        self::assertInstanceOf(RequestInterface::class, $sent);

        return $sent;
    }
}
