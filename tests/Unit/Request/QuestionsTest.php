<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Questions;

final class QuestionsTest extends TestCase
{
    #[Test]
    public function startsEmpty(): void
    {
        $questions = Questions::create();

        self::assertCount(0, $questions);
        self::assertSame([], $questions->names());
        self::assertSame([], $questions->toArray());
    }

    #[Test]
    public function unnamedQuestionsGetPositionalNames(): void
    {
        $questions = Questions::create()
            ->noul('Is this urgent?')
            ->choice('Category?', ['a' => null, 'b' => null])
            ->score('Severity?', ['low', 'high']);

        self::assertSame(['question_0', 'question_1', 'question_2'], $questions->names());
        self::assertCount(3, $questions);
    }

    #[Test]
    public function explicitNameDoesNotShiftPositionalNumbering(): void
    {
        $questions = Questions::create()
            ->noul('Is this urgent?')
            ->choice('Category?', ['a' => null, 'b' => null], name: 'topic')
            ->score('Severity?', ['low', 'high']);

        self::assertSame(['question_0', 'topic', 'question_2'], $questions->names());
    }

    #[Test]
    public function toArrayPreservesDeclarationOrder(): void
    {
        $noul = new NoulQuestion('Is this urgent?');
        $choice = new ChoiceQuestion('Category?', ['a' => null]);

        $questions = Questions::create()->add($noul, 'first')->add($choice, 'second');

        self::assertSame(['first' => $noul, 'second' => $choice], $questions->toArray());
    }

    #[Test]
    public function rejectsADuplicateExplicitName(): void
    {
        $questions = Questions::create()->noul('Is this urgent?', name: 'urgent');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question name "urgent" is already used.');

        $questions->noul('Is this spam?', name: 'urgent');
    }

    #[Test]
    public function rejectsAnEmptyExplicitName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Questions::create()->noul('Is this urgent?', name: '');
    }

    #[Test]
    public function rejectsAWhitespaceOnlyExplicitName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Questions::create()->noul('Is this urgent?', name: '   ');
    }

    #[Test]
    public function anExplicitNameCollidingWithALaterPositionalNameIsRejected(): void
    {
        $questions = Questions::create()->noul('Is this urgent?', name: 'question_1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question name "question_1" is already used.');

        $questions->noul('Is this spam?');
    }

    #[Test]
    public function toRequestThrowsWhenEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Questions::create()->toRequest('some state');
    }

    #[Test]
    public function toRequestOmitsModelWhenNull(): void
    {
        $request = Questions::create()->noul('Is this urgent?')->toRequest('some state');

        $decoded = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('model', $decoded);
    }

    #[Test]
    public function toRequestIncludesModelWhenGiven(): void
    {
        $request = Questions::create()->noul('Is this urgent?')->toRequest('some state', 'jev-preview');

        $decoded = json_decode(json_encode($request, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('jev-preview', $decoded['model']);
    }

    #[Test]
    public function toRequestEncodesQuestionsAsAnObjectInDeclarationOrder(): void
    {
        $questions = Questions::create()
            ->choice('Category?', ['billing' => null, 'technical' => null], name: 'topic')
            ->noul('Is this urgent?')
            ->score('Severity?', ['low', 'high']);

        $encoded = json_encode($questions->toRequest('some state'), JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['questions']);
        self::assertSame(['topic', 'question_1', 'question_2'], array_keys($decoded['questions']));
    }

    #[Test]
    public function reusingABaseBuilderDoesNotLeakQuestionsBetweenBranches(): void
    {
        $base = Questions::create()->noul('Is this urgent?');

        $withChoice = $base->choice('Category?', ['a' => null]);
        $withScore = $base->score('Severity?', ['low', 'high']);

        self::assertCount(1, $base);
        self::assertCount(2, $withChoice);
        self::assertCount(2, $withScore);
        self::assertSame(['question_0'], $base->names());
        self::assertSame(['question_0', 'question_1'], $withChoice->names());
        self::assertSame(['question_0', 'question_1'], $withScore->names());
    }
}
