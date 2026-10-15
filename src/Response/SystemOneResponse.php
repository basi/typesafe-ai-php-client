<?php

declare(strict_types=1);

namespace TypesafeAi\Response;

use TypesafeAi\Exception\ResponseFormatException;
use TypesafeAi\Response\Answer\AnswerInterface;
use TypesafeAi\Response\Answer\BoxAnswer;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;
use TypesafeAi\Response\Answer\UnknownAnswer;

/**
 * A decoded response body for POST /v1/systemone.
 */
final class SystemOneResponse
{
    /**
     * @param array<string, AnswerInterface> $answers
     */
    private function __construct(
        private readonly string $model,
        private readonly array $answers,
        private readonly Usage $usage,
        private readonly ?string $requestId,
        private readonly string $raw,
    ) {
    }

    /**
     * @param ?string $requestId The `x-typesafe-request-id` response header, if available.
     */
    public static function fromJson(string $body, ?string $requestId = null): self
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ResponseFormatException('response body is not valid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw ResponseFormatException::expectedType('$', 'an object', $decoded);
        }

        return self::fromArray($decoded, $requestId, $body);
    }

    /**
     * @param array<array-key, mixed> $data Decoded response body.
     * @param string $raw The exact, unparsed response body $data was decoded from.
     */
    public static function fromArray(array $data, ?string $requestId, string $raw): self
    {
        if (!array_key_exists('model', $data)) {
            throw ResponseFormatException::missingField('model');
        }
        if (!is_string($data['model'])) {
            throw ResponseFormatException::expectedType('model', 'a string', $data['model']);
        }

        if (!array_key_exists('answers', $data)) {
            throw ResponseFormatException::missingField('answers');
        }
        if (!is_array($data['answers'])) {
            throw ResponseFormatException::expectedType('answers', 'an object', $data['answers']);
        }

        $answers = [];
        foreach ($data['answers'] as $name => $answerData) {
            $path = "answers.{$name}";
            if (!is_array($answerData)) {
                throw ResponseFormatException::expectedType($path, 'an object', $answerData);
            }
            $answers[(string) $name] = self::hydrateAnswer($answerData, $path);
        }

        if (!array_key_exists('usage', $data)) {
            throw ResponseFormatException::missingField('usage');
        }
        if (!is_array($data['usage'])) {
            throw ResponseFormatException::expectedType('usage', 'an object', $data['usage']);
        }

        return new self(
            $data['model'],
            $answers,
            Usage::fromArray($data['usage'], 'usage'),
            $requestId,
            $raw,
        );
    }

    /**
     * @template T of AnswerInterface
     *
     * @param class-string<T> $class
     *
     * @return array<string, T>
     */
    private function answersOfType(string $class): array
    {
        $matching = [];
        foreach ($this->answers as $name => $answer) {
            if ($answer instanceof $class) {
                $matching[$name] = $answer;
            }
        }

        return $matching;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function hydrateAnswer(array $data, string $path): AnswerInterface
    {
        if (!array_key_exists('type', $data)) {
            throw ResponseFormatException::missingField("{$path}.type");
        }
        if (!is_string($data['type'])) {
            throw ResponseFormatException::expectedType("{$path}.type", 'a string', $data['type']);
        }

        return match ($data['type']) {
            'noul' => NoulAnswer::fromArray($data, $path),
            'choice' => ChoiceAnswer::fromArray($data, $path),
            'score' => ScoreAnswer::fromArray($data, $path),
            'box' => BoxAnswer::fromArray($data, $path),
            default => new UnknownAnswer($data['type'], $data),
        };
    }

    /**
     * Name of the model that answered the questions. May differ from an alias supplied in the
     * request.
     */
    public function model(): string
    {
        return $this->model;
    }

    public function answer(string $name): AnswerInterface
    {
        if (!array_key_exists($name, $this->answers)) {
            throw new \OutOfBoundsException(sprintf('No answer named "%s" in this response.', $name));
        }

        return $this->answers[$name];
    }

    /**
     * @return array<string, AnswerInterface>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    public function hasAnswer(string $name): bool
    {
        return array_key_exists($name, $this->answers);
    }

    /**
     * The yes/no answers, by question name, in the order of {@see self::answers()}. Mirrors the
     * official SDK (0.7.5)'s `nouls`. They are the same objects as in {@see self::answers()}.
     *
     * @return array<string, NoulAnswer>
     */
    public function nouls(): array
    {
        return $this->answersOfType(NoulAnswer::class);
    }

    /**
     * The choice answers, by question name; see {@see self::nouls()}. Mirrors the official SDK
     * (0.7.5)'s `choices`.
     *
     * @return array<string, ChoiceAnswer>
     */
    public function choices(): array
    {
        return $this->answersOfType(ChoiceAnswer::class);
    }

    /**
     * The score answers, by question name; see {@see self::nouls()}. Mirrors the official SDK
     * (0.7.5)'s `scores`.
     *
     * @return array<string, ScoreAnswer>
     */
    public function scores(): array
    {
        return $this->answersOfType(ScoreAnswer::class);
    }

    /**
     * The box answers, by question name; see {@see self::nouls()}. Mirrors the official SDK
     * (0.7.5)'s `boxes`.
     *
     * @return array<string, BoxAnswer>
     */
    public function boxes(): array
    {
        return $this->answersOfType(BoxAnswer::class);
    }

    public function usage(): Usage
    {
        return $this->usage;
    }

    /**
     * The `x-typesafe-request-id` response header, if available.
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * The exact, unparsed response body as received from the server.
     */
    public function raw(): string
    {
        return $this->raw;
    }
}
