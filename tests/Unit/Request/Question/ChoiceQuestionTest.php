<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request\Question;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Limits;
use TypesafeAi\Request\Question\ChoiceQuestion;

final class ChoiceQuestionTest extends TestCase
{
    #[Test]
    public function typeIsChoice(): void
    {
        self::assertSame('choice', (new ChoiceQuestion('Tone?', ['calm' => null]))->type());
    }

    #[Test]
    public function encodesCriteriaAsAnObject(): void
    {
        $question = new ChoiceQuestion('What is the tone of this message?', [
            'angry' => 'An upset or hostile message',
            'calm' => 'A neutral or polite message',
        ]);

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "choice",
                "instructions": "What is the tone of this message?",
                "criteria": {
                    "angry": "An upset or hostile message",
                    "calm": "A neutral or polite message"
                }
            }
            JSON,
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function encodesCriteriaAsAnObjectEvenWithNumericLookingKeys(): void
    {
        // PHP coerces numeric-looking string keys ("1") to integers, which would make the
        // criteria array a JSON array (["a description"]) instead of an object unless cast.
        $question = new ChoiceQuestion('Pick one.', ['1' => 'first option', 'other' => 'second option']);

        $encoded = json_encode($question, JSON_THROW_ON_ERROR);

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "choice",
                "instructions": "Pick one.",
                "criteria": {
                    "1": "first option",
                    "other": "second option"
                }
            }
            JSON,
            $encoded,
        );
        self::assertStringNotContainsString('[', $encoded);
    }

    #[Test]
    public function allowsANullDescription(): void
    {
        $question = new ChoiceQuestion('Pick one.', ['calm' => null]);

        self::assertJsonStringEqualsJsonString(
            '{"type":"choice","instructions":"Pick one.","criteria":{"calm":null}}',
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function rejectsEmptyInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChoiceQuestion('  ', ['calm' => null]);
    }

    #[Test]
    public function rejectsEmptyOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChoiceQuestion('Pick one.', []);
    }

    #[Test]
    public function rejectsAnEmptyOptionKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChoiceQuestion('Pick one.', ['' => 'description']);
    }

    #[Test]
    public function rejectsTooManyOptions(): void
    {
        $options = [];
        for ($i = 0; $i < Limits::MAX_CHOICE_OPTIONS + 1; $i++) {
            $options["option-{$i}"] = null;
        }

        $this->expectException(\InvalidArgumentException::class);

        new ChoiceQuestion('Pick one.', $options);
    }

    #[Test]
    public function allowsTheMaximumNumberOfOptions(): void
    {
        $options = [];
        for ($i = 0; $i < Limits::MAX_CHOICE_OPTIONS; $i++) {
            $options["option-{$i}"] = null;
        }

        $question = new ChoiceQuestion('Pick one.', $options);

        self::assertCount(Limits::MAX_CHOICE_OPTIONS, (array) $question->jsonSerialize()['criteria']);
    }
}
