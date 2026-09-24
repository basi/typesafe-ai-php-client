<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * The probability of a "yes" or "true" answer.
 *
 * The API does not currently send a `confidence` for noul answers, but {@see self::confidence()}
 * exposes one defensively in case a future server version adds it: it returns null until then.
 */
final readonly class NoulAnswer implements AnswerInterface
{
    /**
     * @param array<array-key, mixed> $raw
     */
    private function __construct(
        private float $noul,
        private ?float $confidence,
        private array $raw,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        if (!array_key_exists('noul', $data)) {
            throw ResponseFormatException::missingField("{$path}.noul");
        }
        if (!is_int($data['noul']) && !is_float($data['noul'])) {
            throw ResponseFormatException::expectedType("{$path}.noul", 'a number', $data['noul']);
        }

        $confidence = null;
        if (array_key_exists('confidence', $data) && $data['confidence'] !== null) {
            if (!is_int($data['confidence']) && !is_float($data['confidence'])) {
                throw ResponseFormatException::expectedType("{$path}.confidence", 'a number', $data['confidence']);
            }
            $confidence = (float) $data['confidence'];
        }

        return new self((float) $data['noul'], $confidence, $data);
    }

    public function type(): string
    {
        return 'noul';
    }

    /**
     * Probability of a "yes" answer or a "true" statement, from 0 to 1.
     */
    public function noul(): float
    {
        return $this->noul;
    }

    /**
     * Confidence in this answer, from 0 to 1, if the server sent one. Not currently part of the
     * documented API response for noul answers; see the class docblock.
     */
    public function confidence(): ?float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type(),
            'noul' => $this->noul,
        ];

        if ($this->confidence !== null) {
            $data['confidence'] = $this->confidence;
        }

        return $data;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
