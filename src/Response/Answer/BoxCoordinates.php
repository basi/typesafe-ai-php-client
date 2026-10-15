<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * The edges of a box in an image, as fractions of the image's size: `top` and `bottom` of its
 * height, counted from the top, and `left` and `right` of its width, counted from the left.
 *
 * Mirrors the official SDK (0.7.5)'s `BoxCoordinates`. The API sends values from 0 to 1, and this
 * client does not check that they are.
 */
final readonly class BoxCoordinates
{
    private function __construct(
        private float $top,
        private float $bottom,
        private float $left,
        private float $right,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        $edges = [];
        foreach (['top', 'bottom', 'left', 'right'] as $edge) {
            if (!array_key_exists($edge, $data)) {
                throw ResponseFormatException::missingField("{$path}.{$edge}");
            }
            if (!is_int($data[$edge]) && !is_float($data[$edge])) {
                throw ResponseFormatException::expectedType("{$path}.{$edge}", 'a number', $data[$edge]);
            }
            $edges[$edge] = (float) $data[$edge];
        }

        return new self($edges['top'], $edges['bottom'], $edges['left'], $edges['right']);
    }

    /**
     * The top edge, as a fraction of the image's height from the top.
     */
    public function top(): float
    {
        return $this->top;
    }

    /**
     * The bottom edge, as a fraction of the image's height from the top.
     */
    public function bottom(): float
    {
        return $this->bottom;
    }

    /**
     * The left edge, as a fraction of the image's width from the left.
     */
    public function left(): float
    {
        return $this->left;
    }

    /**
     * The right edge, as a fraction of the image's width from the left.
     */
    public function right(): float
    {
        return $this->right;
    }

    /**
     * @return array{top: float, bottom: float, left: float, right: float}
     */
    public function toArray(): array
    {
        return [
            'top' => $this->top,
            'bottom' => $this->bottom,
            'left' => $this->left,
            'right' => $this->right,
        ];
    }
}
