<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\ResponseFormatException;
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
}
