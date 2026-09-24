<?php

declare(strict_types=1);

namespace TypesafeAi\Testing;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 HTTP client that replays a queue of canned responses (or throwables) instead of making
 * real network calls, and records every request it was asked to send.
 *
 * Ships in `src/`, not `tests/`, so consumers testing their own integration with this package do
 * not need to install its dev dependencies. See {@see \TypesafeAi\Client} for wiring this in as
 * the PSR-18 client:
 *
 * ```php
 * $mock = new MockHttpClient([JsonResponse::fromArray(200, [...])]);
 * $client = new Client('test-key', $mock);
 * ```
 */
final class MockHttpClient implements ClientInterface
{
    /**
     * @var list<ResponseInterface|\Throwable>
     */
    private array $queue;

    /**
     * @var list<RequestInterface>
     */
    private array $requests = [];

    /**
     * @param list<ResponseInterface|\Throwable> $queue Responses (or exceptions to throw), one
     *     per call to {@see self::sendRequest()}, returned in order.
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /**
     * Queue an additional response, or throwable, to be returned by a future call.
     */
    public function queue(ResponseInterface|\Throwable $response): void
    {
        $this->queue[] = $response;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new \RuntimeException(sprintf(
                'MockHttpClient received a %s %s request but its response queue is empty. Queue a '
                    . 'response (or a throwable) for every request the code under test is expected '
                    . 'to send.',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * All requests sent so far, in order.
     *
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * The most recently sent request, or null if none has been sent yet.
     */
    public function lastRequest(): ?RequestInterface
    {
        if ($this->requests === []) {
            return null;
        }

        return $this->requests[array_key_last($this->requests)];
    }

    /**
     * Number of responses queued but not yet consumed.
     */
    public function remaining(): int
    {
        return count($this->queue);
    }
}
