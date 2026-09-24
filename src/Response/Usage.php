<?php

declare(strict_types=1);

namespace TypesafeAi\Response;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * Token accounting for a systemone request.
 */
final readonly class Usage
{
    private function __construct(
        private int $inputTokens,
        private int $outputTokens,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        return new self(
            self::readInt($data, 'input_tokens', $path),
            self::readInt($data, 'output_tokens', $path),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function readInt(array $data, string $field, string $path): int
    {
        if (!array_key_exists($field, $data)) {
            throw ResponseFormatException::missingField("{$path}.{$field}");
        }
        if (!is_int($data[$field])) {
            throw ResponseFormatException::expectedType("{$path}.{$field}", 'an integer', $data[$field]);
        }

        return $data[$field];
    }

    /**
     * Number of billable input tokens used to evaluate the request.
     */
    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Number of output tokens used to answer the questions.
     */
    public function outputTokens(): int
    {
        return $this->outputTokens;
    }
}
