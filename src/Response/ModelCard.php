<?php

declare(strict_types=1);

namespace TypesafeAi\Response;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * A model or model alias available to the authenticated account, as returned by GET /v1/models.
 */
final readonly class ModelCard
{
    /**
     * @param array<array-key, mixed> $raw
     */
    private function __construct(
        private string $name,
        private string $description,
        private string $releaseDate,
        private array $raw,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        return new self(
            self::readString($data, 'name', $path),
            self::readString($data, 'description', $path),
            self::readString($data, 'release_date', $path),
            $data,
        );
    }

    /**
     * Decode a GET /v1/models response body (a `ModelMetadataList`) into a list of ModelCard.
     *
     * @return list<self>
     */
    public static function listFromJson(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ResponseFormatException('response body is not valid JSON.', previous: $exception);
        }

        if (!is_array($decoded) || !array_key_exists('models', $decoded)) {
            throw ResponseFormatException::missingField('models');
        }
        if (!is_array($decoded['models'])) {
            throw ResponseFormatException::expectedType('models', 'an array', $decoded['models']);
        }

        $cards = [];
        foreach (array_values($decoded['models']) as $index => $entry) {
            if (!is_array($entry)) {
                throw ResponseFormatException::expectedType("models.{$index}", 'an object', $entry);
            }
            $cards[] = self::fromArray($entry, "models.{$index}");
        }

        return $cards;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function readString(array $data, string $field, string $path): string
    {
        if (!array_key_exists($field, $data)) {
            throw ResponseFormatException::missingField("{$path}.{$field}");
        }
        if (!is_string($data[$field])) {
            throw ResponseFormatException::expectedType("{$path}.{$field}", 'a string', $data[$field]);
        }

        return $data[$field];
    }

    /**
     * Model name or alias accepted by a request's `model` field.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Human-readable description of the model and its capabilities.
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Model release date, formatted as YYYY-MM-DD.
     */
    public function releaseDate(): string
    {
        return $this->releaseDate;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
