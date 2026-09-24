<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * An expected score, its rubric legend, probabilities, and the model's confidence.
 *
 * `confidence` is the most important signal on this answer: it tells you how sure the model was
 * about {@see self::score()}. Always required by this client — route low-confidence answers to a
 * human instead of trusting them blindly.
 */
final readonly class ScoreAnswer implements AnswerInterface
{
    /**
     * @param array<string, float> $probabilities
     * @param array<string, string> $legend
     * @param array<array-key, mixed> $raw
     */
    private function __construct(
        private float $score,
        private float $confidence,
        private array $legend,
        private array $probabilities,
        private array $raw,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        if (!array_key_exists('score', $data)) {
            throw ResponseFormatException::missingField("{$path}.score");
        }
        if (!is_int($data['score']) && !is_float($data['score'])) {
            throw ResponseFormatException::expectedType("{$path}.score", 'a number', $data['score']);
        }

        if (!array_key_exists('confidence', $data)) {
            throw ResponseFormatException::missingField("{$path}.confidence");
        }
        if (!is_int($data['confidence']) && !is_float($data['confidence'])) {
            throw ResponseFormatException::expectedType("{$path}.confidence", 'a number', $data['confidence']);
        }

        if (!array_key_exists('legend', $data)) {
            throw ResponseFormatException::missingField("{$path}.legend");
        }
        if (!is_array($data['legend'])) {
            throw ResponseFormatException::expectedType("{$path}.legend", 'an object', $data['legend']);
        }

        $legend = [];
        foreach ($data['legend'] as $key => $value) {
            if (!is_string($value)) {
                throw ResponseFormatException::expectedType("{$path}.legend.{$key}", 'a string', $value);
            }
            $legend[(string) $key] = $value;
        }

        if (!array_key_exists('probabilities', $data)) {
            throw ResponseFormatException::missingField("{$path}.probabilities");
        }
        if (!is_array($data['probabilities'])) {
            throw ResponseFormatException::expectedType("{$path}.probabilities", 'an object', $data['probabilities']);
        }

        $probabilities = [];
        foreach ($data['probabilities'] as $key => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw ResponseFormatException::expectedType("{$path}.probabilities.{$key}", 'a number', $value);
            }
            $probabilities[(string) $key] = (float) $value;
        }

        return new self(
            (float) $data['score'],
            (float) $data['confidence'],
            $legend,
            $probabilities,
            $data,
        );
    }

    public function type(): string
    {
        return 'score';
    }

    /**
     * Expected score: the probability-weighted average of the rubric levels.
     */
    public function score(): float
    {
        return $this->score;
    }

    /**
     * Confidence in {@see self::score()}, from 0 to 1. This is the primary reliability signal for
     * this answer.
     */
    public function confidence(): float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, float> Probability of each score level, keyed by level index as a
     *     string.
     */
    public function probabilities(): array
    {
        return $this->probabilities;
    }

    /**
     * @return array<string, string> The requested level descriptions, keyed by level index as a
     *     string, in the order the server sent them.
     */
    public function legend(): array
    {
        return $this->legend;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'score' => $this->score,
            'confidence' => $this->confidence,
            'legend' => $this->legend,
            'probabilities' => $this->probabilities,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
