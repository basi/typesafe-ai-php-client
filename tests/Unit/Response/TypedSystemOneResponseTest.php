<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\ResponseFormatException;
use TypesafeAi\Request\Questions;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;
use TypesafeAi\Response\SystemOneResponse;
use TypesafeAi\Response\TypedSystemOneResponse;

final class TypedSystemOneResponseTest extends TestCase
{
    private static function questions(): Questions
    {
        return Questions::create()
            ->choice('Category?', ['billing' => null, 'technical' => null], name: 'topic')
            ->noul('Is this urgent?')
            ->score('Severity?', ['low', 'high']);
    }

    /**
     * @param array<string, mixed> $answerOverrides Merged over the default answers, keyed by name.
     */
    private static function response(array $answerOverrides = []): SystemOneResponse
    {
        $answers = [
            'topic' => [
                'type' => 'choice',
                'choice' => 'billing',
                'confidence' => 0.9,
                'probabilities' => ['billing' => 0.9, 'technical' => 0.1],
            ],
            'question_1' => ['type' => 'noul', 'noul' => 0.7],
            'question_2' => [
                'type' => 'score',
                'score' => 1.0,
                'confidence' => 0.8,
                'legend' => ['0' => 'low', '1' => 'high'],
                'probabilities' => ['0' => 0.2, '1' => 0.8],
            ],
            ...$answerOverrides,
        ];

        $body = json_encode([
            'model' => 'jev-latest',
            'answers' => $answers,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], JSON_THROW_ON_ERROR);

        return SystemOneResponse::fromJson($body, 'req_xyz');
    }

    #[Test]
    public function decodesAnswersInDeclarationOrder(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        [$topic, $urgent, $severity] = $typed->answers();
        self::assertInstanceOf(ChoiceAnswer::class, $topic);
        self::assertInstanceOf(NoulAnswer::class, $urgent);
        self::assertInstanceOf(ScoreAnswer::class, $severity);
        self::assertSame(['topic', 'question_1', 'question_2'], $typed->names());
    }

    #[Test]
    public function answerIsAccessibleByPosition(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        self::assertInstanceOf(ChoiceAnswer::class, $typed->answer(0));
        self::assertInstanceOf(NoulAnswer::class, $typed->answer(1));
        self::assertInstanceOf(ScoreAnswer::class, $typed->answer(2));
    }

    #[Test]
    public function answerIsAccessibleByName(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        self::assertInstanceOf(ChoiceAnswer::class, $typed->answer('topic'));
        self::assertInstanceOf(NoulAnswer::class, $typed->answer('question_1'));
    }

    #[Test]
    public function typedAccessorsReturnTheRightAnswerKind(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        self::assertSame('billing', $typed->choice('topic')->choice());
        self::assertSame(0.7, $typed->noul('question_1')->noul());
        self::assertSame(1.0, $typed->score('question_2')->score());
    }

    #[Test]
    public function confidenceIsReadableOnChoiceAndScoreAnswers(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        self::assertSame(0.9, $typed->choice('topic')->confidence());
        self::assertSame(0.8, $typed->score('question_2')->confidence());
    }

    #[Test]
    public function passesThroughModelUsageRequestIdRawAndResponse(): void
    {
        $response = self::response();
        $typed = TypedSystemOneResponse::decode($response, self::questions());

        self::assertSame('jev-latest', $typed->model());
        self::assertSame(10, $typed->usage()->inputTokens());
        self::assertSame(5, $typed->usage()->outputTokens());
        self::assertSame('req_xyz', $typed->requestId());
        self::assertSame($response->raw(), $typed->raw());
        self::assertSame($response, $typed->response());
    }

    #[Test]
    public function decodingAPlainArrayOfQuestionsIsEquivalentToUsingTheBuilder(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions()->toArray());

        self::assertSame(['topic', 'question_1', 'question_2'], $typed->names());
    }

    #[Test]
    public function missingAnswerThrowsResponseFormatException(): void
    {
        $body = json_encode([
            'model' => 'jev-latest',
            'answers' => [
                'topic' => [
                    'type' => 'choice',
                    'choice' => 'billing',
                    'confidence' => 0.9,
                    'probabilities' => ['billing' => 0.9],
                ],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage('answers.question_1: field is required but missing.');

        TypedSystemOneResponse::decode(SystemOneResponse::fromJson($body), self::questions());
    }

    #[Test]
    public function answerTypeMismatchThrowsResponseFormatExceptionNamingExpectedAndActual(): void
    {
        $response = self::response(['question_1' => [
            'type' => 'score',
            'score' => 1.0,
            'confidence' => 0.5,
            'legend' => ['0' => 'a', '1' => 'b'],
            'probabilities' => ['0' => 0.5, '1' => 0.5],
        ]]);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage('answers.question_1: expected a "noul" answer, got "score".');

        TypedSystemOneResponse::decode($response, self::questions());
    }

    #[Test]
    public function unknownAnswerTypeIsTreatedAsAMismatch(): void
    {
        $response = self::response(['question_1' => ['type' => 'future-type', 'future_field' => 1]]);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage('answers.question_1: expected a "noul" answer, got "future-type".');

        TypedSystemOneResponse::decode($response, self::questions());
    }

    #[Test]
    public function typedAccessorOnTheWrongKindThrowsLogicException(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        $this->expectException(\LogicException::class);

        $typed->score('topic');
    }

    #[Test]
    public function answerByUnknownPositionThrowsOutOfBoundsException(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        $this->expectException(\OutOfBoundsException::class);

        $typed->answer(99);
    }

    #[Test]
    public function answerByUnknownNameThrowsOutOfBoundsException(): void
    {
        $typed = TypedSystemOneResponse::decode(self::response(), self::questions());

        $this->expectException(\OutOfBoundsException::class);

        $typed->answer('nope');
    }

    #[Test]
    public function extraAnswersNotInQuestionsAreIgnoredButStillReachableViaResponse(): void
    {
        $response = self::response(['bonus' => ['type' => 'noul', 'noul' => 0.5]]);

        $typed = TypedSystemOneResponse::decode($response, self::questions());

        self::assertSame(['topic', 'question_1', 'question_2'], $typed->names());
        self::assertTrue($typed->response()->hasAnswer('bonus'));
    }
}
