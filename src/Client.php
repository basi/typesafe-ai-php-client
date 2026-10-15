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
use TypesafeAi\Http\SystemOneBody;
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

    private const string JSON_CONTENT_TYPE = 'application/json';

    private const string RETRY_COUNT_HEADER = 'X-TypeSafe-Retry-Count';

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
            : $request->withModel($this->options->defaultModel);

        // The body is built, and every image read, before the first attempt: an image that cannot
        // be read throws here, with nothing sent, and every retry sends the very same bytes.
        [$contentType, $body] = SystemOneBody::encode($effectiveRequest);

        return $this->sendWithRetry(
            'POST',
            $this->options->baseUrl . '/v1/systemone',
            $this->requestHeaders($effectiveRequest->extraHeaders(), $contentType),
            $body,
            static fn (string $responseBody, ?string $requestId): SystemOneResponse
                => SystemOneResponse::fromJson($responseBody, $requestId),
        );
    }

    /**
     * Builder entry point: evaluate $questions against $state in a single request, and strictly
     * decode the answers back out as a tuple in $questions' declaration order.
     *
     * @see Questions for building $questions.
     * @see TypedSystemOneResponse for how the answers are decoded and accessed.
     *
     * @param array<array-key, mixed> $extraHeaders Headers for this request only; see
     *     {@see SystemOneRequest::__construct()}.
     *
     * @throws TypesafeAiException
     */
    public function evaluate(
        mixed $state,
        Questions $questions,
        ?string $model = null,
        array $extraHeaders = [],
    ): TypedSystemOneResponse {
        return TypedSystemOneResponse::decode(
            $this->systemOne($questions->toRequest($state, $model, $extraHeaders)),
            $questions,
        );
    }

    public function models(): array
    {
        return $this->sendWithRetry(
            'GET',
            $this->options->baseUrl . '/v1/models',
            $this->requestHeaders([], null),
            null,
            static fn (string $responseBody): array => ModelCard::listFromJson($responseBody),
        );
    }

    /**
     * @template T
     *
     * @param array<string, string> $headers Headers of every attempt, as built by
     *     {@see self::requestHeaders()}; the retry count is added here, per attempt.
     * @param \Closure(string, ?string): T $hydrate Builds the return value from a 2xx response's
     *     raw body and request id.
     *
     * @return T
     */
    private function sendWithRetry(string $method, string $url, array $headers, ?string $body, \Closure $hydrate): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->transport->send(
                    $method,
                    $url,
                    $attempt > 0 ? [...$headers, self::RETRY_COUNT_HEADER => (string) $attempt] : $headers,
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
     * The headers of one call, built once and reused by every attempt of it.
     *
     * Mirrors the official SDK (0.7.5). Header names are compared without regard to case, and
     * the later of these wins: the client-wide {@see ClientOptions::$headers}; then the call's
     * own headers; then the headers this client always sets (`Authorization`, `Accept`,
     * `User-Agent`, `X-TypeSafe-SDK` and `X-TypeSafe-Runtime`), so that a caller cannot replace
     * the credentials or the client's identification; then `Content-Type`, when the call has a
     * body. A `X-TypeSafe-Retry-Count` supplied by the caller is dropped, because the client adds
     * the real one to each retry itself.
     *
     * @param array<string, string> $extra The call's own headers.
     * @param ?string $contentType The content type of the call's body; null for a call without one.
     *
     * @return array<string, string>
     */
    private function requestHeaders(array $extra, ?string $contentType): array
    {
        /** @var array<string, array{string, string}> $merged Lower-case name to [name, value]. */
        $merged = [];

        foreach ([$this->options->headers, $extra] as $callerHeaders) {
            foreach ($callerHeaders as $name => $value) {
                $merged[strtolower($name)] = [$name, $value];
            }
        }

        unset($merged[strtolower(self::RETRY_COUNT_HEADER)]);

        $clientHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => self::JSON_CONTENT_TYPE,
            'User-Agent' => self::userAgent(),
            'X-TypeSafe-SDK' => self::userAgent(),
            'X-TypeSafe-Runtime' => 'php/' . PHP_VERSION,
        ];

        if ($contentType !== null) {
            $clientHeaders['Content-Type'] = $contentType;
        }

        foreach ($clientHeaders as $name => $value) {
            $merged[strtolower($name)] = [$name, $value];
        }

        $headers = [];
        foreach ($merged as [$name, $value]) {
            $headers[$name] = $value;
        }

        return $headers;
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
