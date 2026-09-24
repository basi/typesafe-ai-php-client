<?php

declare(strict_types=1);

namespace TypesafeAi\Request\Question;

use TypesafeAi\Limits;

/**
 * A question that selects one option from a set of named choices.
 */
final readonly class ChoiceQuestion implements QuestionInterface
{
    /**
     * PHP normalises a numeric-looking string key (e.g. "1") to an integer, so $options is typed
     * `array<array-key, ?string>` rather than `array<string, ?string>`: a caller naming an option
     * "1" is legitimate, and its key genuinely is an int by the time this constructor sees it.
     *
     * @param string $instructions What the model should decide when choosing an option.
     * @param array<array-key, ?string> $options Option name to description, 1 to
     *     {@see Limits::MAX_CHOICE_OPTIONS} entries. A null description means the option is
     *     interpreted by its name alone.
     */
    public function __construct(
        private string $instructions,
        private array $options,
    ) {
        if (trim($instructions) === '') {
            throw new \InvalidArgumentException('instructions must not be empty.');
        }

        $count = count($options);
        if ($count < 1) {
            throw new \InvalidArgumentException('options must contain at least one entry.');
        }
        if ($count > Limits::MAX_CHOICE_OPTIONS) {
            throw new \InvalidArgumentException(sprintf(
                'options must contain at most %d entries, got %d.',
                Limits::MAX_CHOICE_OPTIONS,
                $count,
            ));
        }

        foreach (array_keys($options) as $name) {
            if (trim((string) $name) === '') {
                throw new \InvalidArgumentException('options keys must be non-empty strings.');
            }
        }
    }

    public function type(): string
    {
        return 'choice';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type(),
            'instructions' => $this->instructions,
            // Cast to object: a caller may legitimately name options with numeric-looking strings
            // (e.g. "1"), which PHP stores as integer array keys. Left as an array, json_encode()
            // would then emit a JSON array instead of the object the API requires.
            'criteria' => (object) $this->options,
        ];
    }
}
