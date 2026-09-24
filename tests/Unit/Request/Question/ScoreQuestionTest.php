<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request\Question;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Limits;
use TypesafeAi\Request\Question\ScoreQuestion;

final class ScoreQuestionTest extends TestCase
{
    #[Test]
    public function typeIsScore(): void
    {
        self::assertSame('score', (new ScoreQuestion('Urgency?', ['Can wait', 'Urgent']))->type());
    }

    #[Test]
    public function encodesCriteriaAsAnArray(): void
    {
        $question = new ScoreQuestion('How urgent is this message?', [
            'Can wait',
            'Needs attention this week',
            'Needs attention today',
        ]);

        $encoded = json_encode($question, JSON_THROW_ON_ERROR);

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "score",
                "instructions": "How urgent is this message?",
                "criteria": ["Can wait", "Needs attention this week", "Needs attention today"]
            }
            JSON,
            $encoded,
        );
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsList($decoded['criteria']);
    }

    #[Test]
    public function rejectsEmptyInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScoreQuestion(' ', ['Low', 'High']);
    }

    #[Test]
    public function rejectsANonListArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScoreQuestion('Urgency?', [1 => 'Low', 2 => 'High']);
    }

    #[Test]
    public function rejectsFewerThanMinimumLevels(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScoreQuestion('Urgency?', ['Only one level']);
    }

    #[Test]
    public function rejectsMoreThanMaximumLevels(): void
    {
        $levels = [];
        for ($i = 0; $i < Limits::MAX_SCORE_LEVELS + 1; $i++) {
            $levels[] = "Level {$i}";
        }

        $this->expectException(\InvalidArgumentException::class);

        new ScoreQuestion('Urgency?', $levels);
    }

    #[Test]
    public function allowsTheMinimumAndMaximumLevelCounts(): void
    {
        self::assertCount(
            Limits::MIN_SCORE_LEVELS,
            (array) (new ScoreQuestion('Urgency?', ['Low', 'High']))->jsonSerialize()['criteria'],
        );

        $levels = [];
        for ($i = 0; $i < Limits::MAX_SCORE_LEVELS; $i++) {
            $levels[] = "Level {$i}";
        }

        self::assertCount(
            Limits::MAX_SCORE_LEVELS,
            (array) (new ScoreQuestion('Urgency?', $levels))->jsonSerialize()['criteria'],
        );
    }
}
