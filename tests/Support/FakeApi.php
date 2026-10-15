<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Support;

use PHPUnit\Framework\Assert;
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Response\SystemOneResponse;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Testing\MockHttpClient;
use TypesafeAi\Testing\RequestBody;

/**
 * A {@see Client} wired to a {@see MockHttpClient}, for tests that look at what was sent.
 */
final class FakeApi
{
    public readonly MockHttpClient $http;

    public readonly Client $client;

    public function __construct(ClientOptions $options = new ClientOptions())
    {
        $this->http = new MockHttpClient();
        $this->client = new Client('test-key', $this->http, options: $options);
    }

    /**
     * Queues $count successful answers for the requests that are about to be sent.
     */
    public function answering(int $count = 1): self
    {
        for ($index = 0; $index < $count; $index++) {
            $this->http->queue(JsonResponse::fromArray(200, [
                'model' => 'vision',
                'answers' => ['q' => ['type' => 'noul', 'noul' => 0.5]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]));
        }

        return $this;
    }

    /**
     * A request whose only question is a noul question named `q`.
     */
    public static function request(mixed $state): SystemOneRequest
    {
        return new SystemOneRequest($state, ['q' => new NoulQuestion('Is it damaged?')]);
    }

    /**
     * Sends {@see self::request()} for $state.
     */
    public function ask(mixed $state): SystemOneResponse
    {
        return $this->client->systemOne(self::request($state));
    }

    /**
     * The decoded body of the request sent at position $index, counting from the end when it is
     * negative: the last request by default.
     *
     * @return array<array-key, mixed>
     */
    public function body(int $index = -1): array
    {
        $requests = $this->http->requests();
        $request = $requests[$index < 0 ? count($requests) + $index : $index] ?? null;
        if ($request === null) {
            throw new \OutOfBoundsException(sprintf('No request was sent at position %d.', $index));
        }

        return RequestBody::decode($request);
    }

    /**
     * The `assets` of the body of the request sent at position $index, as {@see self::body()}
     * decodes them: by name, each with its `type`, `content_type` and `data`.
     *
     * @return array<array-key, array<array-key, mixed>>
     */
    public function assets(int $index = -1): array
    {
        $assets = $this->body($index)['assets'] ?? [];
        Assert::assertIsArray($assets);
        foreach ($assets as $asset) {
            Assert::assertIsArray($asset);
        }

        /** @var array<array-key, array<array-key, mixed>> $assets */
        return $assets;
    }

    /**
     * The bytes sent for the asset $name in the request at position $index.
     */
    public function assetData(string $name, int $index = -1): string
    {
        $data = $this->assets($index)[$name]['data'] ?? null;
        Assert::assertIsString($data, "The request sent no data for the asset {$name}.");

        return $data;
    }
}
