<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

use TypesafeAi\Http\Headers;
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
     * @var array<string, string>
     */
    private readonly array $extraHeaders;

    /**
     * $state, $questions and $extraHeaders are validated at runtime rather than typed narrowly,
     * so that validation does not appear dead to static analysis: $state must be a string, array,
     * or object (native `mixed` lets a caller pass anything, including the int/null this
     * constructor rejects); $questions' values must implement QuestionInterface, and PHP
     * normalises a numeric-looking string key (e.g. "1") to an integer, so a single-question map
     * keyed "1" is legitimate and its key genuinely is an int by the time this constructor sees
     * it.
     *
     * @param mixed $state Content all questions in this request refer to. An {@see Image} anywhere
     *     in it, or in a question, is uploaded with the request.
     * @param array<array-key, mixed> $questions Non-empty map of question name to question. The
     *     response uses these names to identify the answers.
     * @param ?string $model Model name or alias. Omitted from the request payload when null; a
     *     client sending this request is expected to fill in its own default in that case.
     * @param array<array-key, mixed> $extraHeaders Header name to header value, added to this
     *     request only; they are not part of the request payload. Mirrors the official SDK
     *     (0.7.5)'s per-call `extra_headers`. A header here replaces one of the same name in
     *     {@see \TypesafeAi\ClientOptions::$headers} (names are compared without regard to case),
     *     while the headers the client always sets — see that option — replace it in turn. Each
     *     name must be an HTTP token and each value a string without line breaks or other control
     *     characters, or this constructor throws an `\InvalidArgumentException`.
     */
    public function __construct(mixed $state, array $questions, ?string $model = null, array $extraHeaders = [])
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
        $this->extraHeaders = Headers::validate($extraHeaders, 'extraHeaders');
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
     * @return array<string, string> Headers for this request only, validated at construction.
     */
    public function extraHeaders(): array
    {
        return $this->extraHeaders;
    }

    /**
     * A copy of this request that asks for $model, with everything else — the state, the
     * questions and the headers — unchanged. A client uses it to fill in its default model.
     *
     * @throws \InvalidArgumentException if $model is empty.
     */
    public function withModel(string $model): self
    {
        return new self($this->state, $this->questions, $model, extraHeaders: $this->extraHeaders);
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
