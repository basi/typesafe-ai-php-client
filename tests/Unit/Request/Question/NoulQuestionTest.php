<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request\Question;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Question\NoulQuestion;

final class NoulQuestionTest extends TestCase
{
    #[Test]
    public function typeIsNoul(): void
    {
        self::assertSame('noul', (new NoulQuestion('Is this spam?'))->type());
    }

    #[Test]
    public function encodesBothSidesAsAnObject(): void
    {
        $question = new NoulQuestion(
            'Is this spam?',
            whenTrue: 'Unsolicited advertising',
            whenFalse: 'A legitimate message',
        );

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "noul",
                "instructions": "Is this spam?",
                "criteria": {
                    "true": "Unsolicited advertising",
                    "false": "A legitimate message"
                }
            }
            JSON,
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function omitsFalseSideWhenOnlyTrueIsSet(): void
    {
        $question = new NoulQuestion('Is this spam?', whenTrue: 'Unsolicited advertising');

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "noul",
                "instructions": "Is this spam?",
                "criteria": {
                    "true": "Unsolicited advertising"
                }
            }
            JSON,
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function omitsTrueSideWhenOnlyFalseIsSet(): void
    {
        $question = new NoulQuestion('Is this spam?', whenFalse: 'A legitimate message');

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "noul",
                "instructions": "Is this spam?",
                "criteria": {
                    "false": "A legitimate message"
                }
            }
            JSON,
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function omitsCriteriaEntirelyWhenNeitherSideIsSet(): void
    {
        $question = new NoulQuestion('Is this spam?');

        $decoded = json_decode(json_encode($question, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('criteria', $decoded);
    }

    #[Test]
    public function rejectsEmptyInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NoulQuestion('   ');
    }
}
