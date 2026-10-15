<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Limits;
use TypesafeAi\Request\Asset\ImageAsset;
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\QuestionInterface;
use TypesafeAi\Request\Question\ScoreQuestion;
use TypesafeAi\Request\SystemOneRequest;

final class SystemOneRequestTest extends TestCase
{
    private const string REFERENCE = '0b6c51aa-thumb';

    private static function anImage(): ImageAsset
    {
        return new ImageAsset('png', 'QUJD');
    }

    /**
     * A request whose only asset is {@see self::REFERENCE}, with $state as its state.
     */
    private static function requestWithState(mixed $state): SystemOneRequest
    {
        return new SystemOneRequest(
            $state,
            ['q' => new NoulQuestion('Is there a person in `photo`?')],
            assets: [self::REFERENCE => self::anImage()],
        );
    }

    /**
     * A request whose only asset is {@see self::REFERENCE}, with $question as its only question.
     */
    private static function requestWithQuestion(QuestionInterface $question): SystemOneRequest
    {
        return new SystemOneRequest('some text', ['q' => $question], assets: [self::REFERENCE => self::anImage()]);
    }

    /**
     * @return array<string, ImageAsset> Exactly $count assets, each with a reference of the maximum
     *     length.
     */
    private static function maximumLengthAssets(int $count): array
    {
        $assets = [];
        for ($index = 0; $index < $count; $index++) {
            // Starts with a letter, so that PHP does not turn the key into an integer.
            $reference = 'r' . str_pad((string) $index, Limits::MAX_ASSET_REFERENCE_BYTES - 1, '0', STR_PAD_LEFT);
            $assets[$reference] = self::anImage();
        }

        return $assets;
    }

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
    public function encodesARequestWithoutAssetsExactlyAsItWasBeforeAssetsExisted(): void
    {
        $request = new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')], 'jev-latest');

        self::assertSame([], $request->assets());
        self::assertSame(
            '{"state":"state","questions":{"q":{"type":"noul","instructions":"Is this spam?"}},'
                . '"model":"jev-latest"}',
            json_encode($request, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function omitsAssetsWhenTheGivenMapIsEmpty(): void
    {
        $request = new SystemOneRequest('state', ['q' => new NoulQuestion('Is this spam?')], assets: []);

        self::assertStringNotContainsString('assets', json_encode($request, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function encodesAssetsAsAnObjectAfterTheOtherFields(): void
    {
        $request = new SystemOneRequest(
            state: ['photo' => self::REFERENCE],
            questions: ['q' => new NoulQuestion('Is there a person in `photo`?')],
            model: 'jev-latest',
            assets: [self::REFERENCE => new ImageAsset('jpg', 'QUJD')],
        );

        self::assertSame(
            '{"state":{"photo":"0b6c51aa-thumb"},'
                . '"questions":{"q":{"type":"noul","instructions":"Is there a person in `photo`?"}},'
                . '"model":"jev-latest",'
                . '"assets":{"0b6c51aa-thumb":{"type":"image","format":"jpg","data_base64":"QUJD"}}}',
            json_encode($request, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function exposesTheAssetsItWasBuiltWith(): void
    {
        $image = self::anImage();
        $request = new SystemOneRequest(
            self::REFERENCE,
            ['q' => new NoulQuestion('Is there a person in the image?')],
            assets: [self::REFERENCE => $image],
        );

        self::assertSame([self::REFERENCE => $image], $request->assets());
    }

    #[Test]
    public function rejectsAnIntegerAssetKey(): void
    {
        // PHP turns the numeric-looking string key "123" into the integer 123.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/got the integer 123\b.*\bUUID\b/');

        new SystemOneRequest('123', ['q' => new NoulQuestion('Is this spam?')], assets: ['123' => self::anImage()]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function blankAssetKeyProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'whitespace characters' => [" \t\r\n"];
    }

    #[Test]
    #[DataProvider('blankAssetKeyProvider')]
    public function rejectsABlankAssetKey(string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty or whitespace-only');

        new SystemOneRequest(
            'state',
            ['q' => new NoulQuestion('Is this spam?')],
            assets: [$reference => self::anImage()],
        );
    }

    #[Test]
    public function rejectsAnAssetKeyLongerThanTheLimit(): void
    {
        $reference = str_repeat('a', Limits::MAX_ASSET_REFERENCE_BYTES + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at most 1024 bytes, got 1025');

        new SystemOneRequest(
            $reference,
            ['q' => new NoulQuestion('Is this spam?')],
            assets: [$reference => self::anImage()],
        );
    }

    #[Test]
    public function countsAssetKeyLengthInBytesRatherThanCharacters(): void
    {
        // 513 two-byte characters: well under 1024 characters, but 1026 bytes.
        $reference = str_repeat("\u{00E9}", intdiv(Limits::MAX_ASSET_REFERENCE_BYTES, 2) + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at most 1024 bytes, got 1026');

        new SystemOneRequest(
            $reference,
            ['q' => new NoulQuestion('Is this spam?')],
            assets: [$reference => self::anImage()],
        );
    }

    #[Test]
    public function acceptsAnAssetKeyOfExactlyTheMaximumLength(): void
    {
        $reference = str_repeat("\u{00E9}", intdiv(Limits::MAX_ASSET_REFERENCE_BYTES, 2));
        $image = self::anImage();

        $request = new SystemOneRequest(
            ['photo' => $reference],
            ['q' => new NoulQuestion('Is there a person in `photo`?')],
            assets: [$reference => $image],
        );

        self::assertSame(Limits::MAX_ASSET_REFERENCE_BYTES, strlen($reference));
        self::assertSame([$reference => $image], $request->assets());
    }

    #[Test]
    public function rejectsAssetKeysThatTogetherExceedTheLimit(): void
    {
        // 65 references of 1024 bytes each: 66560 bytes, over the 65536 allowed.
        $assets = self::maximumLengthAssets(intdiv(Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES, 1024) + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must total at most 65536 bytes');

        new SystemOneRequest(array_keys($assets), ['q' => new NoulQuestion('Is this spam?')], assets: $assets);
    }

    #[Test]
    public function acceptsAssetKeysThatTogetherReachTheLimitExactly(): void
    {
        $assets = self::maximumLengthAssets(intdiv(Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES, 1024));

        $request = new SystemOneRequest(
            array_keys($assets),
            ['q' => new NoulQuestion('Is this spam?')],
            assets: $assets,
        );

        self::assertSame($assets, $request->assets());
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function nonImageAssetProvider(): iterable
    {
        yield 'a base64 string' => ['QUJD'];
        yield 'the array an ImageAsset encodes to' => [['type' => 'image', 'format' => 'png', 'data_base64' => 'QUJD']];
        yield 'null' => [null];
        yield 'another object' => [new \stdClass()];
    }

    #[Test]
    #[DataProvider('nonImageAssetProvider')]
    public function rejectsAnAssetThatIsNotAnImageAsset(mixed $asset): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assets values must be instances of ' . ImageAsset::class);

        new SystemOneRequest(
            self::REFERENCE,
            ['q' => new NoulQuestion('Is this spam?')],
            assets: [self::REFERENCE => $asset],
        );
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function stateReferencingTheImageProvider(): iterable
    {
        $reference = self::REFERENCE;

        yield 'the state is the reference' => [$reference];
        yield 'inside a longer state string' => ["Compare with {$reference} and report."];
        yield 'a whole value' => [['photo' => $reference]];
        yield 'inside a longer value' => [['note' => "see {$reference}!"]];
        yield 'nested in lists and objects' => [['frames' => [['seconds_ago' => 2, 'frame' => $reference]]]];
        yield 'an object state' => [(object) ['photo' => (object) ['file' => $reference]]];
    }

    #[Test]
    #[DataProvider('stateReferencingTheImageProvider')]
    public function acceptsAReferenceInsideAStringValueOfTheState(mixed $state): void
    {
        $request = self::requestWithState($state);

        self::assertSame([self::REFERENCE], array_keys($request->assets()));
    }

    /**
     * @return iterable<string, array{0: QuestionInterface}>
     */
    public static function questionReferencingTheImageProvider(): iterable
    {
        $reference = self::REFERENCE;

        yield 'noul instructions' => [new NoulQuestion("Is the dog in `photo` the dog in {$reference}?")];
        yield 'noul true criterion' => [new NoulQuestion('Is it assembled?', whenTrue: "Looks like {$reference}")];
        yield 'noul false criterion' => [new NoulQuestion('Is it assembled?', whenFalse: "Looks like {$reference}")];
        yield 'choice instructions' => [new ChoiceQuestion("Which of {$reference} is it?", ['a' => null, 'b' => null])];
        yield 'choice option description' => [
            new ChoiceQuestion('Which gesture is it?', ['thumbs_up' => $reference, 'none' => null]),
        ];
        yield 'score instructions' => [new ScoreQuestion("How open is the hand in {$reference}?", ['no', 'yes'])];
        yield 'score level' => [new ScoreQuestion('How open is the hand?', ['closed', $reference, 'open'])];
    }

    #[Test]
    #[DataProvider('questionReferencingTheImageProvider')]
    public function acceptsAReferenceInAQuestionsInstructionsOrCriteria(QuestionInterface $question): void
    {
        $request = self::requestWithQuestion($question);

        self::assertSame([self::REFERENCE], array_keys($request->assets()));
    }

    #[Test]
    public function acceptsAReferenceUsedMoreThanOnce(): void
    {
        $request = new SystemOneRequest(
            ['before' => self::REFERENCE, 'after' => self::REFERENCE],
            ['q' => new NoulQuestion('Did the door open between `before` and `after`?')],
            assets: [self::REFERENCE => self::anImage()],
        );

        self::assertSame([self::REFERENCE], array_keys($request->assets()));
    }

    #[Test]
    public function acceptsSeveralAssetsReferencedInDifferentPlaces(): void
    {
        $request = new SystemOneRequest(
            ['photo' => 'a3f1c2e8-found'],
            ['q' => new NoulQuestion('Is the dog in `photo` the dog in 77b0d4a1-poster?')],
            assets: ['a3f1c2e8-found' => self::anImage(), '77b0d4a1-poster' => self::anImage()],
        );

        self::assertSame(['a3f1c2e8-found', '77b0d4a1-poster'], array_keys($request->assets()));
    }

    #[Test]
    public function matchesReferencesAgainstTheTextOfStringValuesNotTheirJsonEscapedForm(): void
    {
        // json_encode() escapes the slash and the non-ASCII character in this reference, but the
        // API reads the decoded text, so that is what a reference has to be found in.
        $reference = 'photos/"front" door é';

        $request = new SystemOneRequest(
            ['photo' => "see {$reference}"],
            ['q' => new NoulQuestion('Is there a person in `photo`?')],
            assets: [$reference => self::anImage()],
        );

        self::assertSame([$reference], array_keys($request->assets()));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function stateNotReferencingTheImageProvider(): iterable
    {
        $reference = self::REFERENCE;

        yield 'a state without the reference' => ['some text'];
        yield 'the reference only as an object key' => [[$reference => 'value']];
        yield 'the reference only as a nested object key' => [['photos' => [$reference => ['seconds_ago' => 2]]]];
        yield 'only part of the reference' => [['photo' => substr($reference, 0, -1)]];
        yield 'a state without any string value' => [['count' => 3, 'ok' => true, 'missing' => null]];
    }

    #[Test]
    #[DataProvider('stateNotReferencingTheImageProvider')]
    public function rejectsAnAssetWhoseReferenceIsNotInAStringValueOfTheState(mixed $state): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asset reference "' . self::REFERENCE . '" does not appear in any string value');

        self::requestWithState($state);
    }

    #[Test]
    public function rejectsAReferenceUsedOnlyAsAChoiceOptionName(): void
    {
        // The option names of a choice question are keys of its criteria, which are never replaced.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asset reference "' . self::REFERENCE . '" does not appear');

        self::requestWithQuestion(new ChoiceQuestion('Which gesture is it?', [self::REFERENCE => 'A thumbs up']));
    }

    #[Test]
    public function rejectsAReferenceUsedOnlyAsAQuestionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asset reference "' . self::REFERENCE . '" does not appear');

        new SystemOneRequest(
            'some text',
            [self::REFERENCE => new NoulQuestion('Is this spam?')],
            assets: [self::REFERENCE => self::anImage()],
        );
    }

    #[Test]
    public function rejectsAReferenceUsedOnlyInTheModelName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asset reference "' . self::REFERENCE . '" does not appear');

        new SystemOneRequest(
            'some text',
            ['q' => new NoulQuestion('Is this spam?')],
            self::REFERENCE,
            [self::REFERENCE => self::anImage()],
        );
    }

    #[Test]
    public function namesTheAssetThatNothingReferencesWhenOnlySomeAreReferenced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('asset reference "77b0d4a1-poster" does not appear');

        new SystemOneRequest(
            ['photo' => 'a3f1c2e8-found'],
            ['q' => new NoulQuestion('Is the dog in `photo` the dog on the poster?')],
            assets: ['a3f1c2e8-found' => self::anImage(), '77b0d4a1-poster' => self::anImage()],
        );
    }

    #[Test]
    public function rejectsAStateThatCannotBeEncodedAsJsonWhenAssetsAreGiven(): void
    {
        try {
            self::requestWithState("invalid UTF-8: \xB1\x31 " . self::REFERENCE);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('encodable as JSON', $exception->getMessage());
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function stillAcceptsAStateThatCannotBeEncodedAsJsonWhenThereAreNoAssets(): void
    {
        // Nothing is encoded before the request is sent unless assets need their references found.
        $request = new SystemOneRequest("invalid UTF-8: \xB1\x31", ['q' => new NoulQuestion('Is this spam?')]);

        self::assertSame("invalid UTF-8: \xB1\x31", $request->state());
    }

    #[Test]
    public function debugOutputOfARequestDoesNotDumpTheImagePayload(): void
    {
        $image = ImageAsset::fromBinary("\x89PNG\r\n\x1a\n" . str_repeat('x', 3000));
        $payloadFragment = substr($image->dataBase64(), 100, 40);
        $request = new SystemOneRequest(
            ['photo' => self::REFERENCE],
            ['q' => new NoulQuestion('Is there a person in `photo`?')],
            assets: [self::REFERENCE => $image],
        );

        $printed = print_r($request, true);
        ob_start();
        var_dump($request);
        $dumped = (string) ob_get_clean();

        foreach ([$printed, $dumped] as $output) {
            self::assertStringContainsString(self::REFERENCE, $output);
            self::assertStringNotContainsString($payloadFragment, $output);
        }
    }
}
