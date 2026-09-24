<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\ScoreQuestion;
use TypesafeAi\Request\SystemOneRequest;

final class SystemOneRequestTest extends TestCase
{
    #[Test]
    public function encodesAMultiQuestionRequestMatchingTheGoldenFixture(): void
    {
        $request = new SystemOneRequest(
            state: [
                'subject' => 'Duplicate charge',
                'body' => 'I was charged twice. Please help.',
            ],
            questions: [
                'billing' => new NoulQuestion(
                    'Is this message about billing?',
                    whenTrue: 'A billing or payment issue',
                    whenFalse: 'Not related to billing',
                ),
                'urgency' => new ScoreQuestion('How urgent is this message?', [
                    'Can wait',
                    'Needs attention this week',
                    'Needs attention today',
                ]),
            ],
            model: 'jev-latest',
        );

        $expected = file_get_contents(__DIR__ . '/fixtures/expected-request.json');
        self::assertNotFalse($expected);

        self::assertJsonStringEqualsJsonString($expected, json_encode($request, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function encodesQuestionsAsAnObjectEvenForASingleNumericLookingKey(): void
    {
        // A one-entry map whose key is "0" is, on its own, indistinguishable from a PHP list and
        // must still be cast to an object so it round-trips as {"0": {...}}, not [{...}].
        $request = new SystemOneRequest('state', ['0' => new NoulQuestion('Is this spam?')]);

        $encoded = json_encode($request, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"questions":{"0":', $encoded);

        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['questions']);
        self::assertArrayHasKey('0', $decoded['questions']);
    }

    #[Test]
    public function omitsModelWhenNull(): void
    {
        $request = new SystemOneRequest('state', ['billing' => new NoulQuestion('Is this about billing?')]);

        $decoded = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('model', $decoded);
        self::assertNull($request->model());
    }

    #[Test]
    public function rejectsEmptyQuestions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SystemOneRequest('state', []);
    }

    #[Test]
    public function rejectsAnEmptyQuestionKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SystemOneRequest('state', ['' => new NoulQuestion('Is this spam?')]);
    }

    #[Test]
    public function acceptsAStringState(): void
    {
        $request = new SystemOneRequest('plain text', ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame('plain text', $request->state());
    }

    #[Test]
    public function acceptsAnArrayState(): void
    {
        $request = new SystemOneRequest(['a' => 1], ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame(['a' => 1], $request->state());
    }

    #[Test]
    public function acceptsAnObjectState(): void
    {
        $state = (object) ['a' => 1];
        $request = new SystemOneRequest($state, ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame($state, $request->state());
    }

    #[Test]
    public function rejectsAnIntegerState(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SystemOneRequest(42, ['q' => new NoulQuestion('Is this spam?')]);
    }

    #[Test]
    public function rejectsANullState(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SystemOneRequest(null, ['q' => new NoulQuestion('Is this spam?')]);
    }
}
