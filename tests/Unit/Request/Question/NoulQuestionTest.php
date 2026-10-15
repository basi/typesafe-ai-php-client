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

    #[Test]
    public function acceptsJsonContentForTheInstructionsAndBothSides(): void
    {
        $question = new NoulQuestion(
            ['task' => 'Identify unsolicited advertising.', 'examples' => ['free prize', ['note' => null]]],
            whenTrue: ['meaning' => 'Advertising', 'examples' => ['buy now']],
            whenFalse: (object) ['meaning' => 'A conversation'],
        );

        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
                "type": "noul",
                "instructions": {
                    "task": "Identify unsolicited advertising.",
                    "examples": ["free prize", {"note": null}]
                },
                "criteria": {
                    "true": {"meaning": "Advertising", "examples": ["buy now"]},
                    "false": {"meaning": "A conversation"}
                }
            }
            JSON,
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function acceptsAListAsTheInstructions(): void
    {
        $question = new NoulQuestion(['Is it urgent?', 'Answer for the whole message.']);

        self::assertJsonStringEqualsJsonString(
            '{"type":"noul","instructions":["Is it urgent?","Answer for the whole message."]}',
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function acceptsAJsonSerializableObjectAsTheInstructions(): void
    {
        $content = new class implements \JsonSerializable {
            /**
             * @return array<string, string>
             */
            public function jsonSerialize(): array
            {
                return ['question' => 'Is it urgent?'];
            }
        };

        self::assertJsonStringEqualsJsonString(
            '{"type":"noul","instructions":{"question":"Is it urgent?"}}',
            json_encode(new NoulQuestion($content), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function doesNotInspectAnArrayOrObjectThatIsEmpty(): void
    {
        // Only an empty string is rejected: what an empty array or object means is the API's call.
        $question = new NoulQuestion([], whenTrue: new \stdClass());

        self::assertJsonStringEqualsJsonString(
            '{"type":"noul","instructions":[],"criteria":{"true":{}}}',
            json_encode($question, JSON_THROW_ON_ERROR),
        );
    }
}
