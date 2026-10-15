<?php

declare(strict_types=1);

namespace TypesafeAi;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TypesafeAi\Contract\ClientInterface;
use TypesafeAi\Exception\ApiExceptionFactory;
use TypesafeAi\Exception\TransportException;
use TypesafeAi\Exception\TypesafeAiException;
use TypesafeAi\Http\GuzzleTransportFactory;
use TypesafeAi\Http\Redactor;
use TypesafeAi\Http\RetryPolicy;
use TypesafeAi\Http\Transport;
use TypesafeAi\Request\Questions;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Response\ModelCard;
use TypesafeAi\Response\SystemOneResponse;
use TypesafeAi\Response\TypedSystemOneResponse;

/**
 * Default client for the typesafe.ai System One API, over any PSR-18 HTTP client.
 *
 * Use {@see self::withGuzzle()} for the common case of talking to the real API with Guzzle. The
 * main constructor accepts any PSR-18 client, which is how
 * {@see \TypesafeAi\Testing\MockHttpClient} gets wired in for tests.
 */
final class Client implements ClientInterface
{
    /**
     * Current version of this package.
     *
     * The release workflow bumps this with a regular expression that expects exactly this
     * declaration shape — `public const VERSION = '<major>.<minor>.<patch>';`, without a type —
     * so do not add a `string` type to this constant.
     */
    public const VERSION = '0.1.6';

    /**
     * Name of the request header that turns images on. The API requires it, with the value `true`,
     * on every request that contains an image; this client adds it on every attempt (retries
     * included) of a request with assets, see {@see SystemOneRequest::assets()}, and never
     * otherwise.
     */
    public const string HEADER_DANGER_ZONE_IMAGES = 'TypeSafe-Danger-Zone-Images';

    private readonly string $apiKey;

    private readonly Redactor $redactor;

    private readonly Transport $transport;

    private readonly RetryPolicy $retryPolicy;

    /**
     * @param string $apiKey typesafe.ai API key. Leading and trailing ASCII space characters
     *     (0x20) are trimmed; tabs, newlines, and other control characters are not, since a key
     *     that still contains one after that is more likely truncated or corrupted than padded,
     *     and this validates as invalid rather than silently stripping it. The trimmed result
     *     must be a non-empty string of printable ASCII characters with no interior whitespace,
     *     or the constructor throws an `\InvalidArgumentException`. The trimmed value is what is
     *     sent as the `Authorization: Bearer` header and is what {@see Redactor} scrubs out of
     *     exception messages this client builds from upstream text.
     */
    public function __construct(
        string $apiKey,
        HttpClientInterface $httpClient,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ClientOptions $options = new ClientOptions(),
    ) {
        $apiKey = trim($apiKey, ' ');

        // The /D modifier is required so that $ anchors strictly to the end of the string: without
        // it, PCRE lets $ match just before a trailing "\n", which would let a key with a trailing
        // newline slip through as if it had been trimmed.
        if ($apiKey === '' || preg_match('/^[\x21-\x7E]+$/D', $apiKey) !== 1) {
            throw new \InvalidArgumentException(
                'apiKey must be a non-empty ASCII string without whitespace or control characters.',
            );
        }

        $this->apiKey = $apiKey;
        $this->redactor = new Redactor($apiKey);
        $this->transport = new Transport(
            $httpClient,
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            $this->redactor,
        );
        $this->retryPolicy = new RetryPolicy(maxServerDelayMilliseconds: $options->maxRetryDelayMs);
    }

    /**
     * Build a client backed by Guzzle, discovering PSR-17 factories automatically.
     */
    public static function withGuzzle(string $apiKey, ClientOptions $options = new ClientOptions()): self
    {
        return new self($apiKey, GuzzleTransportFactory::create($options), options: $options);
    }

    public function systemOne(SystemOneRequest $request): SystemOneResponse
    {
        $effectiveRequest = $request->model() !== null
            ? $request
            : new SystemOneRequest(
                $request->state(),
                $request->questions(),
                $this->options->defaultModel,
                $request->assets(),
            );

        $body = json_encode($effectiveRequest, JSON_THROW_ON_ERROR);

        return $this->sendWithRetry(
            'POST',
            $this->options->baseUrl . '/v1/systemone',
            $body,
            static fn (string $responseBody, ?string $requestId): SystemOneResponse
                => SystemOneResponse::fromJson($responseBody, $requestId),
            $effectiveRequest->assets() === [] ? [] : [self::HEADER_DANGER_ZONE_IMAGES => 'true'],
        );
    }

    /**
     * Builder entry point: evaluate $questions against $state in a single request, and strictly
     * decode the answers back out as a tuple in $questions' declaration order.
     *
     * @param array<array-key, mixed> $assets Map of reference string to
     *     {@see \TypesafeAi\Request\Asset\ImageAsset}, passed on to the request; see
     *     {@see SystemOneRequest::__construct()}.
     *
     * @see Questions for building $questions.
     * @see TypedSystemOneResponse for how the answers are decoded and accessed.
     *
     * @throws TypesafeAiException
     */
    public function evaluate(
        mixed $state,
        Questions $questions,
        ?string $model = null,
        array $assets = [],
    ): TypedSystemOneResponse {
        return TypedSystemOneResponse::decode(
            $this->systemOne($questions->toRequest($state, $model, $assets)),
            $questions,
        );
    }

    public function models(): array
    {
        return $this->sendWithRetry(
            'GET',
            $this->options->baseUrl . '/v1/models',
            null,
            static fn (string $responseBody): array => ModelCard::listFromJson($responseBody),
        );
    }

    /**
     * @template T
     *
     * @param \Closure(string, ?string): T $hydrate Builds the return value from a 2xx response's
     *     raw body and request id.
     * @param array<string, string> $extraHeaders Sent on every attempt, retries included, in
     *     addition to the standard headers.
     *
     * @return T
     */
    private function sendWithRetry(
        string $method,
        string $url,
        ?string $body,
        \Closure $hydrate,
        array $extraHeaders = [],
    ): mixed {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send(
                    $method,
                    $url,
                    $this->headersForAttempt($method, $attempt, $extraHeaders),
                    $body,
                );
            } catch (TransportException $exception) {
                if ($this->canRetry($method, $attempt, null, $exception)) {
                    $this->retryPolicy->sleep($this->retryPolicy->delayMilliseconds($attempt));
                    $attempt++;
                    continue;
                }

                throw $exception;
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return $hydrate((string) $response->getBody(), $this->requestId($response));
            }

            $lowercaseHeaders = $this->lowercaseHeaders($response);

            if ($this->canRetry($method, $attempt, $statusCode, null)) {
                $this->retryPolicy->sleep($this->retryPolicy->delayMilliseconds($attempt, $lowercaseHeaders));
                $attempt++;
                continue;
            }

            throw ApiExceptionFactory::fromResponse(
                $statusCode,
                (string) $response->getBody(),
                $lowercaseHeaders,
                $this->requestId($response),
                $this->redactor->redact(...),
            );
        }
    }

    private function canRetry(string $method, int $attempt, ?int $statusCode, ?\Throwable $exception): bool
    {
        if ($attempt >= $this->maxRetriesFor($method, $statusCode)) {
            return false;
        }

        if ($exception !== null) {
            return $this->retryPolicy->isRetryableException($exception);
        }

        return $statusCode !== null && $this->retryPolicy->isRetryableStatusCode($statusCode);
    }

    /**
     * POST /v1/systemone defaults to $options->maxRetries = 0, since a retried POST can be billed
     * twice for a probabilistic answer. The one exception is a 429: it means the request was
     * rejected before it was processed, so it is safe to retry (at least once) when
     * $options->retryRateLimitedPost is true, even though $maxRetries is 0. GET /v1/models has no
     * such caveat: it is read-only, so it simply uses $options->modelsMaxRetries.
     */
    private function maxRetriesFor(string $method, ?int $statusCode): int
    {
        if ($method !== 'POST') {
            return $this->options->modelsMaxRetries;
        }

        if ($statusCode === 429 && $this->options->retryRateLimitedPost) {
            return max($this->options->maxRetries, 1);
        }

        return $this->options->maxRetries;
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, string>
     */
    private function headersForAttempt(string $method, int $attempt, array $extraHeaders = []): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => self::userAgent(),
            'X-TypeSafe-SDK' => self::userAgent(),
            'X-TypeSafe-Runtime' => 'php/' . PHP_VERSION,
        ];

        if ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
        }

        if ($attempt > 0) {
            $headers['X-TypeSafe-Retry-Count'] = (string) $attempt;
        }

        return [...$headers, ...$extraHeaders];
    }

    private static function userAgent(): string
    {
        return sprintf('typesafe-ai-php-client/%s', self::VERSION);
    }

    private function requestId(ResponseInterface $response): ?string
    {
        $values = $response->getHeader('x-typesafe-request-id');

        return $values[0] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function lowercaseHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }
}
