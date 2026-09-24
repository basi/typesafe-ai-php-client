<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * The request body failed validation (HTTP 422).
 */
final class ValidationException extends ApiException
{
    /**
     * @param list<array{loc: list<string|int>, msg: string, type: string}> $errors
     */
    public function __construct(
        string $message,
        int $statusCode,
        private readonly array $errors,
        ?string $requestId = null,
        ?string $errorType = null,
        string $rawBody = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $requestId, $errorType, $rawBody, $previous);
    }

    /**
     * @return list<array{loc: list<string|int>, msg: string, type: string}>
     */
    public function getValidationErrors(): array
    {
        return $this->errors;
    }
}
