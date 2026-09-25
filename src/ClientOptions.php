<?php

declare(strict_types=1);

namespace TypesafeAi;

/**
 * Configuration for a typesafe.ai API client.
 */
final class ClientOptions
{
    public readonly string $baseUrl;

    /**
     * All arguments are validated at runtime (non-empty $baseUrl/$defaultModel, minimum
     * timeouts, non-negative retry counts, non-negative delay cap), so their declared types are
     * intentionally the wide native types rather than a narrower PHPDoc type — narrowing them
     * would make the validation below appear dead to static analysis.
     *
     * @param string $baseUrl Absolute base URL of the API, non-empty. A trailing slash is trimmed.
     * @param string $defaultModel Model name or alias used when a request does not specify one,
     *     non-empty.
     * @param int $timeoutSeconds Total request timeout, in seconds. Must be at least 1: Guzzle
     *     treats a timeout of 0 as "wait indefinitely", which would remove any upper bound on how
     *     long a single call can take.
     * @param int $connectTimeoutSeconds Connection timeout, in seconds. Must be at least 1, for
     *     the same reason as $timeoutSeconds.
     * @param int $maxRetries Retries for POST /v1/systemone, must not be negative. Defaults to 0:
     *     a retried request can be billed twice, and because answers are probabilistic a retry is
     *     not guaranteed to match the original response.
     * @param int $modelsMaxRetries Retries for the read-only GET /v1/models request. Must not be
     *     negative.
     * @param bool $retryRateLimitedPost Whether a 429 response to POST /v1/systemone may be
     *     retried even when $maxRetries is 0. A 429 means the request was rejected before it was
     *     processed, so retrying it does not risk double billing.
     * @param ?int $maxRetryDelayMs Upper bound, in milliseconds, on a server-specified retry delay
     *     (`retry-after-ms` / `Retry-After`) this client will honour. A server-specified delay
     *     longer than this is not waited out; exponential backoff is used instead. Must not be
     *     negative when given. `0` means a server-specified delay is never honoured — backoff,
     *     which is still applied, always wins. `null` (the default) keeps today's behaviour:
     *     server delays are honoured up to {@see \TypesafeAi\Http\RetryPolicy}'s own 60-second
     *     ceiling. Useful for a caller with a hard upper bound on how long one call may take, such
     *     as a queue worker with a job timeout — see the README's "Options" section for how to
     *     size it.
     */
    public function __construct(
        string $baseUrl = 'https://api.typesafe.ai',
        public readonly string $defaultModel = Limits::DEFAULT_MODEL,
        public readonly int $timeoutSeconds = 30,
        public readonly int $connectTimeoutSeconds = 5,
        public readonly int $maxRetries = 0,
        public readonly int $modelsMaxRetries = 2,
        public readonly bool $retryRateLimitedPost = true,
        public readonly ?int $maxRetryDelayMs = null,
    ) {
        if (trim($baseUrl) === '') {
            throw new \InvalidArgumentException('baseUrl must not be empty.');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(sprintf('baseUrl must be an absolute URL, got "%s".', $baseUrl));
        }

        if ($defaultModel === '') {
            throw new \InvalidArgumentException('defaultModel must not be empty.');
        }

        $minimumOneInts = [
            'timeoutSeconds' => $timeoutSeconds,
            'connectTimeoutSeconds' => $connectTimeoutSeconds,
        ];
        foreach ($minimumOneInts as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must be at least 1, got %d. Guzzle treats a timeout of 0 as "wait indefinitely".',
                    $name,
                    $value,
                ));
            }
        }

        $nonNegativeInts = [
            'maxRetries' => $maxRetries,
            'modelsMaxRetries' => $modelsMaxRetries,
        ];
        foreach ($nonNegativeInts as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('%s must not be negative, got %d.', $name, $value));
            }
        }

        if ($maxRetryDelayMs !== null && $maxRetryDelayMs < 0) {
            throw new \InvalidArgumentException(
                sprintf('maxRetryDelayMs must not be negative, got %d.', $maxRetryDelayMs),
            );
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }
}
