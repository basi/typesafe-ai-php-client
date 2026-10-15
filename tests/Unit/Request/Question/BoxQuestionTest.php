<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request\Question;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Http\SystemOneBody;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\BoxQuestion;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Tests\Support\Samples;
use TypesafeAi\Testing\RequestBody;

final class BoxQuestionTest extends TestCase
{
    #[Test]
    public function typeIsBox(): void
    {
        self::assertSame('box', (new BoxQuestion())->type());
    }

    #[Test]
    public function encodesOnlyTheTypeWithoutInstructions(): void
    {
        self::assertSame('{"type":"box"}', json_encode(new BoxQuestion(), JSON_THROW_ON_ERROR));
        self::assertSame('{"type":"box"}', json_encode(new BoxQuestion(null), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function encodesTextInstructions(): void
    {
        self::assertJsonStringEqualsJsonString(
            '{"type":"box","instructions":"The dog"}',
            json_encode(new BoxQuestion('The dog'), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function encodesJsonContentAsInstructions(): void
    {
        self::assertJsonStringEqualsJsonString(
            '{"type":"box","instructions":{"find":"the dog","hints":["brown",{"note":null}]}}',
            json_encode(
                new BoxQuestion(['find' => 'the dog', 'hints' => ['brown', ['note' => null]]]),
                JSON_THROW_ON_ERROR,
            ),
        );
        self::assertJsonStringEqualsJsonString(
            '{"type":"box","instructions":["The","dog"]}',
            json_encode(new BoxQuestion(['The', 'dog']), JSON_THROW_ON_ERROR),
        );
        self::assertJsonStringEqualsJsonString(
            '{"type":"box","instructions":{"find":"the dog"}}',
            json_encode(new BoxQuestion((object) ['find' => 'the dog']), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function doesNotInspectAnArrayOrObjectThatIsEmpty(): void
    {
        self::assertSame(
            '{"type":"box","instructions":[]}',
            json_encode(new BoxQuestion([]), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function rejectsEmptyInstructions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('instructions must not be empty.');

        new BoxQuestion('   ');
    }

    #[Test]
    public function aBoxQuestionCanAskAboutAnImageInTheInstructions(): void
    {
        $example = Image::fromBytes(Samples::PNG);
        $request = new SystemOneRequest(
            Image::fromBytes(Samples::JPEG),
            ['dog' => new BoxQuestion(['The dog that looks like', $example])],
            'jev-1.14.0',
        );

        [$contentType, $body] = SystemOneBody::encode($request);
        $decoded = RequestBody::decode(new Request(
            'POST',
            'https://api.typesafe.ai/v1/systemone',
            ['Content-Type' => $contentType],
            $body,
        ));

        self::assertIsArray($decoded['questions']);
        self::assertSame(
            ['type' => 'box', 'instructions' => ['The dog that looks like', (string) $example]],
            $decoded['questions']['dog'],
        );
        self::assertIsArray($decoded['assets']);
        self::assertCount(2, $decoded['assets']);
    }
}
