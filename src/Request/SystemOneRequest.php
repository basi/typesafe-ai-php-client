<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

use TypesafeAi\Request\Question\QuestionInterface;

/**
 * A request to POST /v1/systemone: content to evaluate together with one or more named questions.
 */
final class SystemOneRequest implements \JsonSerializable
{
    private readonly mixed $state;

    /**
     * @var array<array-key, QuestionInterface>
     */
    private readonly array $questions;

    private readonly ?string $model;

    /**
     * $state and $questions are validated at runtime rather than typed narrowly, so that
     * validation does not appear dead to static analysis: $state must be a string, array, or
     * object (native `mixed` lets a caller pass anything, including the int/null this constructor
     * rejects); $questions' values must implement QuestionInterface, and PHP normalises a
     * numeric-looking string key (e.g. "1") to an integer, so a single-question map keyed "1" is
     * legitimate and its key genuinely is an int by the time this constructor sees it.
     *
     * @param mixed $state Content all questions in this request refer to.
     * @param array<array-key, mixed> $questions Non-empty map of question name to question. The
     *     response uses these names to identify the answers.
     * @param ?string $model Model name or alias. Omitted from the request payload when null; a
     *     client sending this request is expected to fill in its own default in that case.
     */
    public function __construct(mixed $state, array $questions, ?string $model = null)
    {
        if (!is_string($state) && !is_array($state) && !is_object($state)) {
            throw new \InvalidArgumentException(sprintf(
                'state must be a string, array, or object, got %s.',
                get_debug_type($state),
            ));
        }

        if ($questions === []) {
            throw new \InvalidArgumentException('questions must not be empty.');
        }

        $validatedQuestions = [];
        foreach ($questions as $name => $question) {
            if (trim((string) $name) === '') {
                throw new \InvalidArgumentException('questions keys must be non-empty strings.');
            }
            if (!$question instanceof QuestionInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'questions values must implement %s, got %s.',
                    QuestionInterface::class,
                    get_debug_type($question),
                ));
            }
            $validatedQuestions[$name] = $question;
        }

        if ($model !== null && trim($model) === '') {
            throw new \InvalidArgumentException('model must not be empty when provided.');
        }

        $this->state = $state;
        $this->questions = $validatedQuestions;
        $this->model = $model;
    }

    public function state(): mixed
    {
        return $this->state;
    }

    /**
     * @return array<array-key, QuestionInterface>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'state' => $this->state,
            // Cast to object: a single-entry (or numerically-named) questions map would otherwise
            // risk being emitted as a JSON array instead of the object the API requires.
            'questions' => (object) $this->questions,
        ];

        if ($this->model !== null) {
            $data['model'] = $this->model;
        }

        return $data;
    }
}
