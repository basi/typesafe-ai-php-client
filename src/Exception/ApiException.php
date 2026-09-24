<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * An error response from the typesafe.ai API (any non-2xx HTTP status).
 */
class ApiException extends \RuntimeException implements TypesafeAiException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $requestId = null,
        private readonly ?string $errorType = null,
        private readonly string $rawBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * The HTTP status code of the response. Same value as {@see self::getCode()}.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The `x-typesafe-request-id` response header, if the server sent one.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * The `detail.error_type` from the response body, if present.
     */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /**
     * The exact response body, for callers that need more detail than {@see self::getMessage()}.
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}
