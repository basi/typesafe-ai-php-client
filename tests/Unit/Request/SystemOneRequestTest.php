<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\ScoreQuestion;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Tests\Support\Samples;

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

    #[Test]
    public function hasNoExtraHeadersByDefault(): void
    {
        $request = new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame([], $request->extraHeaders());
    }

    #[Test]
    public function keepsTheExtraHeadersOutOfThePayload(): void
    {
        $request = new SystemOneRequest(
            'state',
            ['q' => new NoulQuestion('Is this spam?')],
            extraHeaders: ['X-Tenant' => '42'],
        );

        self::assertSame(['X-Tenant' => '42'], $request->extraHeaders());
        self::assertStringNotContainsString('X-Tenant', json_encode($request, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function rejectsAnInvalidExtraHeaderName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^extraHeaders keys must be valid HTTP header names/');

        new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')], extraHeaders: ['Bad Name' => 'v']);
    }

    #[Test]
    public function rejectsAnExtraHeaderValueWithALineBreak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^extraHeaders values must be strings without control characters/');

        new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')], extraHeaders: ['X-A' => "1\n2"]);
    }

    #[Test]
    public function withModelReturnsACopyThatKeepsEverythingElse(): void
    {
        $question = new NoulQuestion('Is this spam?');
        $photo = Image::fromBytes(Samples::PNG);
        $request = new SystemOneRequest(
            ['a' => 1],
            ['q' => $question],
            'jev-latest',
            assets: ['<photo>' => $photo],
            extraHeaders: ['X-Tenant' => '42'],
        );

        $copy = $request->withModel('jev-preview');

        self::assertNotSame($request, $copy);
        self::assertSame('jev-preview', $copy->model());
        self::assertSame('jev-latest', $request->model());
        self::assertSame(['a' => 1], $copy->state());
        self::assertSame(['q' => $question], $copy->questions());
        self::assertSame(['<photo>' => $photo], $copy->assets());
        self::assertSame(['X-Tenant' => '42'], $copy->extraHeaders());
    }

    #[Test]
    public function withModelFillsInAMissingModel(): void
    {
        $request = new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame('jev-preview', $request->withModel('jev-preview')->model());
    }

    #[Test]
    public function withModelRejectsAnEmptyModel(): void
    {
        $request = new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')]);

        $this->expectException(\InvalidArgumentException::class);

        $request->withModel('  ');
    }

    #[Test]
    public function hasNoNamedImagesByDefault(): void
    {
        $request = new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame([], $request->assets());
    }

    #[Test]
    public function keepsTheNamedImagesInTheOrderGiven(): void
    {
        $first = Image::fromBytes(Samples::PNG);
        $second = Image::fromBytes(Samples::JPEG);

        $request = new SystemOneRequest(
            'state',
            ['q' => new NoulQuestion('Is this spam?')],
            assets: ['<second>' => $second, '<first>' => $first],
        );

        self::assertSame(['<second>' => $second, '<first>' => $first], $request->assets());
    }

    /**
     * @return iterable<string, array{0: array<array-key, Image>}>
     */
    public static function integerNameProvider(): iterable
    {
        yield 'a list' => [[Image::fromBytes(Samples::PNG)]];
        yield 'the integer 1' => [[1 => Image::fromBytes(Samples::PNG)]];
        yield 'the integer 0' => [[0 => Image::fromBytes(Samples::PNG)]];
        // PHP turns a numeric string that is a canonical integer into an integer key.
        yield 'the string "1"' => [['1' => Image::fromBytes(Samples::PNG)]];
        yield 'the string "-5"' => [['-5' => Image::fromBytes(Samples::PNG)]];
        yield 'one integer among names' => [['<photo>' => Image::fromBytes(Samples::PNG), 7 => Image::fromBytes('x')]];
    }

    /**
     * @param array<array-key, Image> $assets
     */
    #[Test]
    #[DataProvider('integerNameProvider')]
    public function rejectsAnAssetNameThatPhpMadeAnIntegerKey(array $assets): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'assets keys must be non-empty strings; PHP turns a numeric string such as "1" into an integer key, '
            . 'so use a name that is not a number, such as "<photo>".',
        );

        new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')], assets: $assets);
    }

    #[Test]
    public function acceptsANameThatOnlyLooksLikeANumberAndPhpKeepsAsAString(): void
    {
        $first = Image::fromBytes('first');
        $second = Image::fromBytes('second');
        $third = Image::fromBytes('third');

        // None of these is a canonical integer, so PHP leaves the keys alone.
        $request = new SystemOneRequest(
            'state',
            ['q' => new NoulQuestion('Is this spam?')],
            assets: ['007' => $first, '1.5' => $second, '<1>' => $third],
        );

        self::assertSame(['007', '1.5', '<1>'], array_keys($request->assets()));
    }

    #[Test]
    public function rejectsAnEmptyAssetName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assets keys must be non-empty strings.');

        new SystemOneRequest(
            'state',
            ['q' => new NoulQuestion('Is this spam?')],
            assets: ['' => Image::fromBytes(Samples::PNG)],
        );
    }

    #[Test]
    public function rejectsABlankAssetName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assets keys must be non-empty strings.');

        new SystemOneRequest(
            'state',
            ['q' => new NoulQuestion('Is this spam?')],
            assets: ['   ' => Image::fromBytes(Samples::PNG)],
        );
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function notAnImageProvider(): iterable
    {
        yield 'the bytes of an image' => [Samples::PNG, 'string'];
        yield 'a path' => ['photo.png', 'string'];
        yield 'an array' => [['type' => 'image', 'data' => Samples::PNG], 'array'];
        yield 'null' => [null, 'null'];
        yield 'another object' => [new \stdClass(), 'stdClass'];
    }

    #[Test]
    #[DataProvider('notAnImageProvider')]
    public function rejectsAnAssetThatIsNotAnImage(mixed $value, string $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('assets values must be TypesafeAi\Request\Image instances, got %s.', $type),
        );

        new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')], assets: ['<photo>' => $value]);
    }

    #[Test]
    public function leavesTheNamedImagesOutOfThePayloadAndEncodesAPlacedOneAsItsReference(): void
    {
        $photo = Image::fromBytes(Samples::PNG);
        $request = new SystemOneRequest(
            ['photo' => $photo],
            ['q' => new NoulQuestion('Is this spam?')],
            assets: ['<photo>' => $photo],
        );

        // The client replaces the reference by the name when it builds the body.
        self::assertSame(
            sprintf(
                '{"state":{"photo":"%s"},"questions":{"q":{"type":"noul","instructions":"Is this spam?"}}}',
                $photo,
            ),
            json_encode($request, JSON_THROW_ON_ERROR),
        );
    }
}
