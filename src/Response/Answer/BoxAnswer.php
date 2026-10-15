<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * The box that a box question located in the image, and the model's confidence in it.
 *
 * Mirrors the official SDK (0.7.5)'s `BoxAnswer`. The confidence is optional in the sense that the
 * model may not give one, in which case the API sends `null`; the field itself is always there.
 */
final readonly class BoxAnswer implements AnswerInterface
{
    /**
     * @param array<array-key, mixed> $raw
     */
    private function __construct(
        private BoxCoordinates $box,
        private ?float $confidence,
        private array $raw,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        if (!array_key_exists('box', $data)) {
            throw ResponseFormatException::missingField("{$path}.box");
        }
        if (!is_array($data['box'])) {
            throw ResponseFormatException::expectedType("{$path}.box", 'an object', $data['box']);
        }
        $box = BoxCoordinates::fromArray($data['box'], "{$path}.box");

        if (!array_key_exists('confidence', $data)) {
            throw ResponseFormatException::missingField("{$path}.confidence");
        }
        $confidence = null;
        if ($data['confidence'] !== null) {
            if (!is_int($data['confidence']) && !is_float($data['confidence'])) {
                throw ResponseFormatException::expectedType(
                    "{$path}.confidence",
                    'a number or null',
                    $data['confidence'],
                );
            }
            $confidence = (float) $data['confidence'];
        }

        return new self($box, $confidence, $data);
    }

    public function type(): string
    {
        return 'box';
    }

    /**
     * The edges of the box, as fractions of the image's height and width.
     */
    public function box(): BoxCoordinates
    {
        return $this->box;
    }

    /**
     * Confidence in the box, from 0 to 1, or null when the model did not give one.
     */
    public function confidence(): ?float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed> The answer without `confidence` when there is none.
     */
    public function toArray(): array
    {
        $data = [
            'type' => $this->type(),
            'box' => $this->box->toArray(),
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
