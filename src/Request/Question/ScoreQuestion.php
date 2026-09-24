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
     * $levels is validated at runtime (must be a list of non-empty strings within the allowed
     * count) rather than declared as `list<string>`, so that validation does not appear dead to
     * static analysis.
     *
     * @param string $instructions What the model should rate.
     * @param array<array-key, mixed> $levels Ordered level descriptions; the first level scores 0.
     *     Must be a list of non-empty strings with between {@see Limits::MIN_SCORE_LEVELS} and
     *     {@see Limits::MAX_SCORE_LEVELS} entries.
     */
    public function __construct(
        private string $instructions,
        private array $levels,
    ) {
        if (trim($instructions) === '') {
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
            if (!is_string($level) || trim($level) === '') {
                throw new \InvalidArgumentException('levels entries must be non-empty strings.');
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
