<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * A response body from the API does not match the shape this client expects.
 */
final class ResponseFormatException extends \UnexpectedValueException implements TypesafeAiException
{
    /**
     * @param non-empty-string $path Dot-separated path to the offending value, e.g.
     *     "answers.urgent.probabilities.1".
     */
    public static function expectedType(string $path, string $expectedType, mixed $actual): self
    {
        return new self(sprintf(
            '%s: expected %s, got %s.',
            $path,
            $expectedType,
            get_debug_type($actual),
        ));
    }

    /**
     * @param non-empty-string $path Dot-separated path to the missing value.
     */
    public static function missingField(string $path): self
    {
        return new self(sprintf('%s: field is required but missing.', $path));
    }
}
