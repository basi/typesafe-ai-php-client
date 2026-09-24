<?php

declare(strict_types=1);

namespace TypesafeAi\Testing;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ResponseInterface;

/**
 * Convenience factory for building PSR-7 JSON responses in tests, without wiring up PSR-17
 * factories by hand. Used together with {@see MockHttpClient}:
 *
 * ```php
 * $mock = new MockHttpClient([
 *     JsonResponse::fromArray(200, ['model' => 'jev-latest', 'answers' => [...], 'usage' => [...]]),
 * ]);
 * ```
 */
final class JsonResponse
{
    private function __construct()
    {
    }

    /**
     * @param array<string, string> $headers Extra headers to add. `content-type` defaults to
     *     `application/json` and can be overridden here.
     */
    public static function create(int $statusCode, string $jsonBody, array $headers = []): ResponseInterface
    {
        $response = Psr17FactoryDiscovery::findResponseFactory()
            ->createResponse($statusCode)
            ->withHeader('content-type', 'application/json')
            ->withBody(Psr17FactoryDiscovery::findStreamFactory()->createStream($jsonBody));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $headers
     */
    public static function fromArray(int $statusCode, array $data, array $headers = []): ResponseInterface
    {
        return self::create($statusCode, json_encode($data, JSON_THROW_ON_ERROR), $headers);
    }
}
