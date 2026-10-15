<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

use GuzzleHttp\Client;
use TypesafeAi\ClientOptions;

/**
 * Builds the Guzzle HTTP client used by {@see \TypesafeAi\Client::withGuzzle()}.
 */
final class GuzzleTransportFactory
{
    private function __construct()
    {
    }

    /**
     * Timeouts come from $options; HTTP error statuses (4xx/5xx) are returned as ordinary
     * responses instead of being thrown, and redirects are never followed, since the client is
     * only ever asked to reach the exact URLs it builds itself.
     *
     * `Expect: 100-Continue` is never sent. Guzzle adds it to a request body of 1 MiB or more and
     * then waits for the server to answer before sending the body, which costs a round trip and
     * stalls when a proxy does not support it. A request with images easily reaches that size, and
     * the official SDK's HTTP client (httpx) never sends the header.
     */
    public static function create(ClientOptions $options): Client
    {
        return new Client([
            'timeout' => $options->timeoutSeconds,
            'connect_timeout' => $options->connectTimeoutSeconds,
            'http_errors' => false,
            'allow_redirects' => false,
            'expect' => false,
        ]);
    }
}
