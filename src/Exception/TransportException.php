<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * The request could not be completed due to a network or transport-level failure (no HTTP
 * response was received). Always carries {@see \Exception::getCode()} `=== 0`, since there is no
 * HTTP status to report.
 */
class TransportException extends \RuntimeException implements TypesafeAiException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
