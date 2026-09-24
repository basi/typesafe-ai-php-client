<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\QuestionInterface;
use TypesafeAi\Request\Question\ScoreQuestion;

/**
 * Packs several questions into one request without naming the set itself: each question not given
 * an explicit name gets a positional wire key (`question_0`, `question_1`, ...), and
 * {@see \TypesafeAi\Response\TypedSystemOneResponse} decodes the answers back out as a tuple in
 * the same declaration order.
 *
 * Every method that adds a question returns a new instance rather than mutating this one, so a
 * base builder can be branched more than once without one branch's questions leaking into another:
 *
 * ```php
 * $base = Questions::create()->noul('Is this urgent?');
 * $withCategory = $base->choice('Category?', ['a' => null, 'b' => null]);
 * $withScore = $base->score('Severity?', ['low', 'high']);
 * // $withCategory and $withScore each have 2 questions; $base is unaffected and still has 1.
 * ```
 */
final class Questions implements \Countable
{
    /**
     * PHP normalises a numeric-looking string name (e.g. "1") to an integer key, so this is typed
     * `array<array-key, QuestionInterface>` rather than `array<string, QuestionInterface>`: a
     * caller naming a question "1" is legitimate, and its key genuinely is an int by the time it
     * is stored here.
     *
     * @var array<array-key, QuestionInterface>
     */
    private readonly array $questions;

    /**
     * @param array<array-key, QuestionInterface> $questions
     */
    private function __construct(array $questions)
    {
        $this->questions = $questions;
    }

    public static function create(): self
    {
        return new self([]);
    }

    /**
     * Add a {@see NoulQuestion}. See {@see self::add()} for how $name is assigned.
     */
    public function noul(
        string $instructions,
        ?string $whenTrue = null,
        ?string $whenFalse = null,
        ?string $name = null,
    ): self {
        return $this->add(new NoulQuestion($instructions, $whenTrue, $whenFalse), $name);
    }

    /**
     * Add a {@see ChoiceQuestion}. See {@see self::add()} for how $name is assigned.
     *
     * @param array<string, ?string> $options
     */
    public function choice(string $instructions, array $options, ?string $name = null): self
    {
        return $this->add(new ChoiceQuestion($instructions, $options), $name);
    }

    /**
     * Add a {@see ScoreQuestion}. See {@see self::add()} for how $name is assigned.
     *
     * @param list<string> $levels
     */
    public function score(string $instructions, array $levels, ?string $name = null): self
    {
        return $this->add(new ScoreQuestion($instructions, $levels), $name);
    }

    /**
     * Add a question built some other way, e.g. to reuse a {@see QuestionInterface} instance
     * across more than one builder.
     *
     * When $name is null, the question is assigned the positional wire key `question_<n>`, where
     * `<n>` is the 0-based index of this question among ALL questions added so far, named or not —
     * an explicit name given to another question in between does not shift this numbering. For
     * example: `noul()` (unnamed, becomes `question_0`), `choice(name: 'topic')`,
     * `score()` (unnamed, becomes `question_2`, not `question_1`).
     *
     * An explicit $name must be non-empty after trimming, and must not already be used by another
     * question in this builder, whether that question was itself named explicitly or positionally.
     * This also covers an explicit name that a not-yet-added question would otherwise be assigned
     * positionally: adding the explicit name first only postpones the error to whichever add()
     * call would have produced the same key.
     *
     * @throws \InvalidArgumentException if $name is empty (after trimming) or already used.
     */
    public function add(QuestionInterface $question, ?string $name = null): self
    {
        if ($name !== null && trim($name) === '') {
            throw new \InvalidArgumentException('question name must not be empty.');
        }

        $key = $name ?? sprintf('question_%d', count($this->questions));

        if (array_key_exists($key, $this->questions)) {
            throw new \InvalidArgumentException(sprintf('question name "%s" is already used.', $key));
        }

        return new self([...$this->questions, $key => $question]);
    }

    /**
     * @return list<string> Question names in declaration order.
     */
    public function names(): array
    {
        return array_map(static fn (int|string $key): string => (string) $key, array_keys($this->questions));
    }

    /**
     * @return array<array-key, QuestionInterface> Questions in declaration order. See the property
     *     docblock for why this is not typed `array<string, QuestionInterface>`.
     */
    public function toArray(): array
    {
        return $this->questions;
    }

    /**
     * Build a {@see SystemOneRequest} from the questions declared so far.
     *
     * @throws \InvalidArgumentException if no question has been added yet ({@see SystemOneRequest}
     *     rejects an empty question map).
     */
    public function toRequest(mixed $state, ?string $model = null): SystemOneRequest
    {
        return new SystemOneRequest($state, $this->questions, $model);
    }

    public function count(): int
    {
        return count($this->questions);
    }
}
