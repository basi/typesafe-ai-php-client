<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

/**
 * An answer whose `type` this client version does not recognise.
 *
 * The API may add new question and answer types over time. Rather than failing to parse a
 * response that contains one, this client preserves the answer verbatim so callers can still
 * inspect it via {@see self::raw()}.
 */
final readonly class UnknownAnswer implements AnswerInterface
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        private string $type,
        private array $raw,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
