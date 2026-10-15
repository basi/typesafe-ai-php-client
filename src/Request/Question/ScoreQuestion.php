<?php

declare(strict_types=1);

namespace TypesafeAi\Request\Question;

use TypesafeAi\Limits;

/**
 * A question that assigns a score using an ordered rubric.
 */
final readonly class ScoreQuestion implements QuestionInterface
{
    /**
     * $levels is validated at runtime (must be a list of non-empty strings, arrays or objects
     * within the allowed count) rather than declared as `list<string|array|object>`, so that
     * validation does not appear dead to static analysis.
     *
     * Mirrors the official SDK (0.7.5), whose question fields take JSON content rather than only
     * text: a string, a list or map of values (nested to any depth), or an object that encodes as
     * JSON, such as a {@see \JsonSerializable}. A string must not be empty; an array or an object
     * is sent as it is, and the API decides whether it makes sense.
     *
     * @param string|array<array-key, mixed>|object $instructions What the model should rate.
     * @param array<array-key, mixed> $levels Ordered level descriptions; the first level scores 0.
     *     Must be a list of non-empty strings, arrays or objects with between
     *     {@see Limits::MIN_SCORE_LEVELS} and {@see Limits::MAX_SCORE_LEVELS} entries.
     */
    public function __construct(
        private string|array|object $instructions,
        private array $levels,
    ) {
        if (is_string($instructions) && trim($instructions) === '') {
            throw new \InvalidArgumentException('instructions must not be empty.');
        }

        if (!array_is_list($levels)) {
            throw new \InvalidArgumentException('levels must be a list (sequential integer keys starting at 0).');
        }

        $count = count($levels);
        if ($count < Limits::MIN_SCORE_LEVELS || $count > Limits::MAX_SCORE_LEVELS) {
            throw new \InvalidArgumentException(sprintf(
                'levels must contain between %d and %d entries, got %d.',
                Limits::MIN_SCORE_LEVELS,
                Limits::MAX_SCORE_LEVELS,
                $count,
            ));
        }

        foreach ($levels as $level) {
            $isContent = is_array($level) || is_object($level) || (is_string($level) && trim($level) !== '');
            if (!$isContent) {
                throw new \InvalidArgumentException('levels entries must be non-empty strings, arrays, or objects.');
            }
        }
    }

    public function type(): string
    {
        return 'score';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'instructions' => $this->instructions,
            'criteria' => array_values($this->levels),
        ];
    }
}
