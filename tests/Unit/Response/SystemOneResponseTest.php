<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\ResponseFormatException;
use TypesafeAi\Response\Answer\BoxAnswer;
use TypesafeAi\Response\Answer\ChoiceAnswer;
use TypesafeAi\Response\Answer\NoulAnswer;
use TypesafeAi\Response\Answer\ScoreAnswer;
use TypesafeAi\Response\Answer\UnknownAnswer;
use TypesafeAi\Response\SystemOneResponse;

final class SystemOneResponseTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function validData(): array
    {
        return [
            'model' => 'jev-1.13.0',
            'answers' => [
                'billing' => ['type' => 'noul', 'noul' => 0.98],
                'urgency' => [
                    'type' => 'score',
                    'score' => 1,
                    'confidence' => 1,
                    'legend' => ['0' => 'Can wait', '1' => 'Needs attention today'],
                    'probabilities' => ['0' => 0.0, '1' => 1],
                ],
                'intent' => [
                    'type' => 'choice',
                    'choice' => 'billing',
                    'confidence' => 0.81,
                    'probabilities' => ['billing' => 0.88, 'technical' => 0.12],
                ],
            ],
            'usage' => ['input_tokens' => 304, 'output_tokens' => 18],
        ];
    }

    private static function validBody(): string
    {
        return json_encode(self::validData(), JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function decodesModelAnswersAndUsage(): void
    {
        $response = SystemOneResponse::fromJson(self::validBody());

        self::assertSame('jev-1.13.0', $response->model());
        self::assertCount(3, $response->answers());
        self::assertSame(304, $response->usage()->inputTokens());
        self::assertSame(18, $response->usage()->outputTokens());
    }

    #[Test]
    public function hydratesANoulAnswer(): void
    {
        $answer = SystemOneResponse::fromJson(self::validBody())->answer('billing');

        self::assertInstanceOf(NoulAnswer::class, $answer);
        self::assertSame(0.98, $answer->noul());
    }

    #[Test]
    public function hydratesAScoreAnswerWithStringKeysAndFloats(): void
    {
        $answer = SystemOneResponse::fromJson(self::validBody())->answer('urgency');

        self::assertInstanceOf(ScoreAnswer::class, $answer);
        self::assertSame(1.0, $answer->score());
        self::assertIsFloat($answer->score());
        self::assertSame(1.0, $answer->confidence());
        self::assertIsFloat($answer->confidence());
        self::assertSame(['0' => 'Can wait', '1' => 'Needs attention today'], $answer->legend());
        self::assertSame(['0' => 0.0, '1' => 1.0], $answer->probabilities());
        // PHP normalises numeric-looking string array keys ("0", "1") to integers; there is no
        // way to store a literal string key "0" in a plain PHP array. Both lookup forms address
        // the same slot, which is what callers actually rely on.
        self::assertArrayHasKey('0', $answer->legend());
        self::assertArrayHasKey(0, $answer->legend());
        self::assertArrayHasKey('1', $answer->probabilities());
        self::assertArrayHasKey(1, $answer->probabilities());
    }

    #[Test]
    public function hydratesAScoreAnswerWhoseLegendHoldsJsonContent(): void
    {
        $data = self::validData();
        $data['answers'] = [
            'urgency' => [
                'type' => 'score',
                'score' => 1,
                'confidence' => 0.5,
                'legend' => [
                    '0' => 'Can wait',
                    '1' => ['meaning' => 'Needs attention', 'examples' => ['today', ['note' => null]]],
                    '2' => ['listed', 'level'],
                ],
                'probabilities' => ['0' => 0.1, '1' => 0.8, '2' => 0.1],
            ],
        ];

        $answer = SystemOneResponse::fromJson(json_encode($data, JSON_THROW_ON_ERROR))->answer('urgency');

        self::assertInstanceOf(ScoreAnswer::class, $answer);
        $entries = array_values($answer->legend());
        self::assertCount(3, $entries);
        self::assertSame('Can wait', $entries[0]);
        self::assertEquals(['meaning' => 'Needs attention', 'examples' => ['today', ['note' => null]]], $entries[1]);
        self::assertEquals(['listed', 'level'], $entries[2]);
        self::assertSame($answer->legend(), $answer->toArray()['legend']);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function invalidLegendEntryProvider(): iterable
    {
        yield 'integer' => [5, 'int'];
        yield 'null' => [null, 'null'];
        yield 'boolean' => [true, 'bool'];
    }

    #[Test]
    #[DataProvider('invalidLegendEntryProvider')]
    public function aLegendEntryThatIsNeitherTextNorJsonContentIsRejected(mixed $entry, string $type): void
    {
        $data = self::validData();
        $data['answers'] = [
            'urgency' => [
                'type' => 'score',
                'score' => 1,
                'confidence' => 0.5,
                'legend' => ['0' => 'Can wait', '1' => $entry],
                'probabilities' => ['0' => 0.5, '1' => 0.5],
            ],
        ];

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage(
            sprintf('answers.urgency.legend.1: expected a string, an array, or an object, got %s.', $type),
        );

        SystemOneResponse::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function hydratesABoxAnswerRatherThanKeepingItAsUnknown(): void
    {
        $data = self::validData();
        $data['answers'] = [
            'dog' => [
                'type' => 'box',
                'box' => ['top' => 0.2, 'bottom' => 0.66, 'left' => 0.19, 'right' => 0.68],
                'confidence' => 0.9,
            ],
        ];

        $answer = SystemOneResponse::fromJson(json_encode($data, JSON_THROW_ON_ERROR))->answer('dog');

        self::assertInstanceOf(BoxAnswer::class, $answer);
        self::assertSame('box', $answer->type());
        self::assertSame(0.66, $answer->box()->bottom());
        self::assertSame(0.9, $answer->confidence());
    }

    #[Test]
    public function aBoxAnswerWithoutItsBoxIsRejectedByPath(): void
    {
        $data = self::validData();
        $data['answers'] = ['dog' => ['type' => 'box', 'confidence' => null]];

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage('answers.dog.box: field is required but missing.');

        SystemOneResponse::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function hydratesAChoiceAnswer(): void
    {
        $answer = SystemOneResponse::fromJson(self::validBody())->answer('intent');

        self::assertInstanceOf(ChoiceAnswer::class, $answer);
        self::assertSame('billing', $answer->choice());
        self::assertSame(0.81, $answer->confidence());
        self::assertSame(['billing' => 0.88, 'technical' => 0.12], $answer->probabilities());
    }

    #[Test]
    public function exposesConfidenceOnANoulAnswerWhenTheServerSendsOne(): void
    {
        $body = json_encode([
            'model' => 'jev-1.13.0',
            'answers' => [
                'billing' => ['type' => 'noul', 'noul' => 0.98, 'confidence' => 1],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR);

        $answer = SystemOneResponse::fromJson($body)->answer('billing');

        self::assertInstanceOf(NoulAnswer::class, $answer);
        self::assertSame(1.0, $answer->confidence());
        self::assertIsFloat($answer->confidence());
        self::assertSame(['type' => 'noul', 'noul' => 0.98, 'confidence' => 1], $answer->raw());
    }

    #[Test]
    public function noulConfidenceIsNullWhenAbsent(): void
    {
        $answer = SystemOneResponse::fromJson(self::validBody())->answer('billing');

        self::assertInstanceOf(NoulAnswer::class, $answer);
        self::assertNull($answer->confidence());
        self::assertArrayNotHasKey('confidence', $answer->toArray());
    }

    #[Test]
    public function choiceAnswerWithoutConfidenceIsRejected(): void
    {
        $body = json_encode([
            'model' => 'jev-1.13.0',
            'answers' => [
                'intent' => [
                    'type' => 'choice',
                    'choice' => 'billing',
                    'probabilities' => ['billing' => 0.88, 'technical' => 0.12],
                ],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessageMatches('/answers\.intent\.confidence/');

        SystemOneResponse::fromJson($body);
    }

    #[Test]
    public function scoreAnswerWithoutConfidenceIsRejected(): void
    {
        $body = json_encode([
            'model' => 'jev-1.13.0',
            'answers' => [
                'urgency' => [
                    'type' => 'score',
                    'score' => 1,
                    'legend' => ['0' => 'Can wait', '1' => 'Needs attention today'],
                    'probabilities' => ['0' => 0.0, '1' => 1],
                ],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessageMatches('/answers\.urgency\.confidence/');

        SystemOneResponse::fromJson($body);
    }

    #[Test]
    public function treatsAnUnknownAnswerTypeAsUnknownAnswer(): void
    {
        $body = json_encode([
            'model' => 'jev-1.13.0',
            'answers' => ['mystery' => ['type' => 'future-type', 'future_field' => 42]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ], JSON_THROW_ON_ERROR);

        $answer = SystemOneResponse::fromJson($body)->answer('mystery');

        self::assertInstanceOf(UnknownAnswer::class, $answer);
        self::assertSame('future-type', $answer->type());
        self::assertSame(['type' => 'future-type', 'future_field' => 42], $answer->raw());
    }

    #[Test]
    public function throwsOnMissingUsage(): void
    {
        $body = json_encode([
            'model' => 'jev-1.13.0',
            'answers' => ['billing' => ['type' => 'noul', 'noul' => 0.5]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessageMatches('/^usage/');

        SystemOneResponse::fromJson($body);
    }

    #[Test]
    public function throwsOnNonNumericNoul(): void
    {
        $data = self::validData();
        $data['answers'] = ['billing' => ['type' => 'noul', 'noul' => 'high']];
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessageMatches('/answers\.billing\.noul/');

        SystemOneResponse::fromJson($body);
    }

    #[Test]
    public function throwsOnMissingAnswerType(): void
    {
        $data = self::validData();
        $data['answers'] = ['billing' => ['noul' => 0.5]];
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessageMatches('/answers\.billing\.type/');

        SystemOneResponse::fromJson($body);
    }

    #[Test]
    public function unknownAnswerNameThrowsOutOfBoundsException(): void
    {
        $response = SystemOneResponse::fromJson(self::validBody());

        $this->expectException(\OutOfBoundsException::class);

        $response->answer('missing');
    }

    #[Test]
    public function hasAnswerReflectsPresence(): void
    {
        $response = SystemOneResponse::fromJson(self::validBody());

        self::assertTrue($response->hasAnswer('billing'));
        self::assertFalse($response->hasAnswer('missing'));
    }

    #[Test]
    public function rawReturnsTheExactBody(): void
    {
        $body = self::validBody();
        $response = SystemOneResponse::fromJson($body);

        self::assertSame($body, $response->raw());
    }

    #[Test]
    public function requestIdRoundTrips(): void
    {
        $response = SystemOneResponse::fromJson(self::validBody(), 'req_abc123');

        self::assertSame('req_abc123', $response->requestId());
    }

    #[Test]
    public function requestIdIsNullByDefault(): void
    {
        $response = SystemOneResponse::fromJson(self::validBody());

        self::assertNull($response->requestId());
    }

    /**
     * @return array<string, mixed>
     */
    private static function dataWithEveryKindOfAnswer(): array
    {
        $data = self::validData();
        $data['answers'] = [
            'dog' => [
                'type' => 'box',
                'box' => ['top' => 0.1, 'bottom' => 0.9, 'left' => 0.2, 'right' => 0.8],
                'confidence' => null,
            ],
            'spam' => ['type' => 'noul', 'noul' => 0.1],
            'mystery' => ['type' => 'future-type', 'future_field' => 1],
            'tone' => [
                'type' => 'choice',
                'choice' => 'calm',
                'confidence' => 0.8,
                'probabilities' => ['calm' => 0.8, 'angry' => 0.2],
            ],
            'urgent' => ['type' => 'noul', 'noul' => 0.9],
            'severity' => [
                'type' => 'score',
                'score' => 1.0,
                'confidence' => 0.7,
                'legend' => ['0' => 'low', '1' => 'high'],
                'probabilities' => ['0' => 0.3, '1' => 0.7],
            ],
            'cat' => [
                'type' => 'box',
                'box' => ['top' => 0.0, 'bottom' => 0.5, 'left' => 0.0, 'right' => 0.5],
                'confidence' => 0.4,
            ],
        ];

        return $data;
    }

    #[Test]
    public function groupsTheAnswersByTypeKeepingTheirNamesAndTheirOrder(): void
    {
        $response = SystemOneResponse::fromJson(json_encode(self::dataWithEveryKindOfAnswer(), JSON_THROW_ON_ERROR));

        self::assertSame(['spam', 'urgent'], array_keys($response->nouls()));
        self::assertSame(['tone'], array_keys($response->choices()));
        self::assertSame(['severity'], array_keys($response->scores()));
        self::assertSame(['dog', 'cat'], array_keys($response->boxes()));
    }

    #[Test]
    public function theGroupsHoldTheSameObjectsAsAnswers(): void
    {
        $response = SystemOneResponse::fromJson(json_encode(self::dataWithEveryKindOfAnswer(), JSON_THROW_ON_ERROR));

        self::assertSame($response->answer('urgent'), $response->nouls()['urgent']);
        self::assertSame($response->answer('tone'), $response->choices()['tone']);
        self::assertSame($response->answer('severity'), $response->scores()['severity']);
        self::assertSame($response->answer('cat'), $response->boxes()['cat']);
        self::assertCount(7, $response->answers());
    }

    #[Test]
    public function anAnswerOfATypeThisClientDoesNotKnowIsInNoGroup(): void
    {
        $response = SystemOneResponse::fromJson(json_encode(self::dataWithEveryKindOfAnswer(), JSON_THROW_ON_ERROR));

        $grouped = [...$response->nouls(), ...$response->choices(), ...$response->scores(), ...$response->boxes()];

        self::assertArrayNotHasKey('mystery', $grouped);
        self::assertCount(6, $grouped);
    }

    #[Test]
    public function aGroupWithoutAnswersIsEmpty(): void
    {
        $response = SystemOneResponse::fromJson(self::validBody());

        self::assertSame([], $response->boxes());
        self::assertSame(['billing'], array_keys($response->nouls()));
    }
}
