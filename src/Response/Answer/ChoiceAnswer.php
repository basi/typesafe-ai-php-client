<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

use TypesafeAi\Exception\ResponseFormatException;

/**
 * The selected choice, its probabilities, and the model's confidence for a choice question.
 *
 * `confidence` is the most important signal on this answer: it tells you how sure the model was
 * about {@see self::choice()}. Always required by this client — route low-confidence answers to a
 * human instead of trusting them blindly.
 */
final readonly class ChoiceAnswer implements AnswerInterface
{
    /**
     * @param array<string, float> $probabilities
     * @param array<array-key, mixed> $raw
     */
    private function __construct(
        private string $choice,
        private float $confidence,
        private array $probabilities,
        private array $raw,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $path): self
    {
        if (!array_key_exists('choice', $data)) {
            throw ResponseFormatException::missingField("{$path}.choice");
        }
        if (!is_string($data['choice'])) {
            throw ResponseFormatException::expectedType("{$path}.choice", 'a string', $data['choice']);
        }

        if (!array_key_exists('confidence', $data)) {
            throw ResponseFormatException::missingField("{$path}.confidence");
        }
        if (!is_int($data['confidence']) && !is_float($data['confidence'])) {
            throw ResponseFormatException::expectedType("{$path}.confidence", 'a number', $data['confidence']);
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

        return new self($data['choice'], (float) $data['confidence'], $probabilities, $data);
    }

    public function type(): string
    {
        return 'choice';
    }

    /**
     * The name of the selected choice.
     */
    public function choice(): string
    {
        return $this->choice;
    }

    /**
     * Confidence in {@see self::choice()}, from 0 to 1. This is the primary reliability signal
     * for this answer.
     */
    public function confidence(): float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, float> Probability of each option, keyed by option name.
     */
    public function probabilities(): array
    {
        return $this->probabilities;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'choice' => $this->choice,
            'confidence' => $this->confidence,
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
