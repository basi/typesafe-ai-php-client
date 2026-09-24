<?php

declare(strict_types=1);

namespace TypesafeAi\Response;

use TypesafeAi\Exception\ResponseFormatException;
use TypesafeAi\Request\Question\QuestionInterface;
use TypesafeAi\Request\Questions;
use TypesafeAi\Response\Answer\AnswerInterface;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;

/**
 * A {@see SystemOneResponse} strictly decoded against a known, ordered set of questions: every
 * question must have a matching answer of the same kind, and the answers are then reachable as a
 * plain, positionally-ordered list — a tuple, in all but name:
 *
 * ```php
 * $typed = TypedSystemOneResponse::decode($response, $questions);
 * [$category, $urgent, $severity] = $typed->answers();
 * ```
 *
 * Built by {@see \TypesafeAi\Client::evaluate()}, or directly via {@see self::decode()} for a
 * response whose request was not built with {@see Questions}.
 */
final class TypedSystemOneResponse
{
    /**
     * @param list<string> $names Question names, in declaration order.
     * @param list<AnswerInterface> $answers Answers, in the same order as $names.
     */
    private function __construct(
        private readonly SystemOneResponse $response,
        private readonly array $names,
        private readonly array $answers,
    ) {
    }

    /**
     * Strictly decode $response against $questions: every question must have an answer, and that
     * answer's {@see AnswerInterface::type()} must match the question's
     * {@see QuestionInterface::type()} (an {@see \TypesafeAi\Response\Answer\UnknownAnswer} never
     * matches, since its type is whatever the server sent). An answer present in $response but not
     * requested by $questions is ignored here, though it remains reachable via
     * {@see self::response()}.
     *
     * @param Questions|array<array-key, QuestionInterface> $questions The questions $response is
     *     expected to answer, in declaration order. Pass the {@see Questions} instance used to
     *     build the request, or, if the request was assembled by hand, the same
     *     `name => question` map that was passed to {@see \TypesafeAi\Request\SystemOneRequest} —
     *     in the same order.
     *
     * @throws ResponseFormatException if a question has no matching answer, or the answer's type
     *     does not match the question's.
     */
    public static function decode(SystemOneResponse $response, Questions|array $questions): self
    {
        $ordered = $questions instanceof Questions ? $questions->toArray() : $questions;

        $names = [];
        $answers = [];

        foreach ($ordered as $rawName => $question) {
            $name = (string) $rawName;

            if (!$response->hasAnswer($name)) {
                throw ResponseFormatException::missingField("answers.{$name}");
            }

            $answer = $response->answer($name);
            $expectedType = $question->type();

            if ($answer->type() !== $expectedType) {
                throw ResponseFormatException::unexpectedAnswerType("answers.{$name}", $expectedType, $answer->type());
            }

            $names[] = $name;
            $answers[] = $answer;
        }

        return new self($response, $names, $answers);
    }

    /**
     * @return list<AnswerInterface> Answers in question declaration order. Being a plain list,
     *     this supports positional destructuring:
     *
     *     ```php
     *     [$category, $urgent, $severity] = $typed->answers();
     *     ```
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /**
     * @param int|string $key A 0-based position in declaration order, or a question name.
     *
     * @throws \OutOfBoundsException if $key does not identify a decoded answer.
     */
    public function answer(int|string $key): AnswerInterface
    {
        if (is_int($key)) {
            if (!array_key_exists($key, $this->answers)) {
                throw new \OutOfBoundsException(sprintf('No answer at position %d in this response.', $key));
            }

            return $this->answers[$key];
        }

        $position = array_search($key, $this->names, true);
        if ($position === false) {
            throw new \OutOfBoundsException(sprintf('No answer named "%s" in this response.', $key));
        }

        return $this->answers[$position];
    }

    /**
     * @throws \LogicException if the answer at $key is not a {@see NoulAnswer}.
     */
    public function noul(int|string $key): NoulAnswer
    {
        $answer = $this->answer($key);

        if (!$answer instanceof NoulAnswer) {
            throw $this->wrongKind($key, $answer, 'noul');
        }

        return $answer;
    }

    /**
     * @throws \LogicException if the answer at $key is not a {@see ChoiceAnswer}.
     */
    public function choice(int|string $key): ChoiceAnswer
    {
        $answer = $this->answer($key);

        if (!$answer instanceof ChoiceAnswer) {
            throw $this->wrongKind($key, $answer, 'choice');
        }

        return $answer;
    }

    /**
     * @throws \LogicException if the answer at $key is not a {@see ScoreAnswer}.
     */
    public function score(int|string $key): ScoreAnswer
    {
        $answer = $this->answer($key);

        if (!$answer instanceof ScoreAnswer) {
            throw $this->wrongKind($key, $answer, 'score');
        }

        return $answer;
    }

    private function wrongKind(int|string $key, AnswerInterface $answer, string $expectedType): \LogicException
    {
        return new \LogicException(sprintf(
            'Answer %s is a "%s" answer, not a "%s" answer.',
            is_int($key) ? "at position {$key}" : sprintf('"%s"', $key),
            $answer->type(),
            $expectedType,
        ));
    }

    /**
     * @return list<string> Question names in declaration order (positions align with
     *     {@see self::answers()}).
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * Name of the model that answered the questions. May differ from an alias supplied in the
     * request.
     */
    public function model(): string
    {
        return $this->response->model();
    }

    public function usage(): Usage
    {
        return $this->response->usage();
    }

    /**
     * The `x-typesafe-request-id` response header, if available.
     */
    public function requestId(): ?string
    {
        return $this->response->requestId();
    }

    /**
     * The exact, unparsed response body as received from the server.
     */
    public function raw(): string
    {
        return $this->response->raw();
    }

    /**
     * The underlying response this was decoded from, including any answer not requested by the
     * questions passed to {@see self::decode()}.
     */
    public function response(): SystemOneResponse
    {
        return $this->response;
    }
}
