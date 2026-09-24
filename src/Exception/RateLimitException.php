<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * The request was rejected because the caller exceeded a rate limit (HTTP 429).
 */
final class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        int $statusCode,
        private readonly ?int $retryAfterMs = null,
        ?string $requestId = null,
        ?string $errorType = null,
        string $rawBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $requestId, $errorType, $rawBody, $previous);
    }

    /**
     * Milliseconds to wait before retrying, derived from the `retry-after-ms` or `Retry-After`
     * response header, if the server sent one.
     */
    public function getRetryAfterMs(): ?int
    {
        return $this->retryAfterMs;
    }
}
