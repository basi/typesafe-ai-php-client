<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TypesafeAi\Exception\TimeoutException;
use TypesafeAi\Exception\TransportException;

/**
 * Thin wrapper around a PSR-18 HTTP client: builds a request from a method, URL, header map, and
 * an optional JSON body, sends it, and converts any transport-level failure — a
 * `Psr\Http\Client\NetworkExceptionInterface` or `RequestExceptionInterface`, a Guzzle
 * `ConnectException`, or any other `\Throwable` the HTTP layer raises — into a part-1 exception:
 * {@see TimeoutException} when the failure looks like a timeout (a "cURL error 28", or a message
 * mentioning "timed out"), {@see TransportException} otherwise. Both always carry
 * {@see \Exception::getCode()} `=== 0` and never quote the `Authorization` header value.
 *
 * A non-2xx HTTP response is returned as-is, not thrown: only the caller knows which request
 * produced it and how many attempts remain, so turning it into an
 * {@see \TypesafeAi\Exception\ApiException} is the caller's job.
 */
final class Transport
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException
     */
    public function send(string $method, string $url, array $headers, ?string $body = null): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (\Throwable $exception) {
            throw $this->convert($exception);
        }
    }

    private function convert(\Throwable $exception): TransportException
    {
        $message = $this->scrubApiKey($exception->getMessage());

        if (str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false) {
            return new TimeoutException(sprintf('typesafe.ai request timed out: %s', $message), $exception);
        }

        return new TransportException(sprintf('typesafe.ai request failed: %s', $message), $exception);
    }

    private function scrubApiKey(string $message): string
    {
        return preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
    }
}
