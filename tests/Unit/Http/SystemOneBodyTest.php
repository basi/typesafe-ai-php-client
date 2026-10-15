<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Http;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\AssetException;
use TypesafeAi\Http\SystemOneBody;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Testing\RequestBody;
use TypesafeAi\Tests\Support\Samples;
use TypesafeAi\Tests\Support\UsesTemporaryDirectory;

final class SystemOneBodyTest extends TestCase
{
    use UsesTemporaryDirectory;

    private static function request(mixed $state, ?NoulQuestion $question = null): SystemOneRequest
    {
        return new SystemOneRequest($state, ['q' => $question ?? new NoulQuestion('Is it damaged?')], 'vision');
    }

    /**
     * @param array{string, string} $encoded
     */
    private static function sent(array $encoded): Request
    {
        [$contentType, $body] = $encoded;

        return new Request('POST', 'https://api.typesafe.ai/v1/systemone', ['Content-Type' => $contentType], $body);
    }

    #[Test]
    public function aRequestWithoutImagesIsTheJsonItWasBeforeImagesWereSupported(): void
    {
        $request = new SystemOneRequest(
            ['text' => 'a/b <c> & d', 'list' => [1, 2.5, true, null]],
            ['q' => new NoulQuestion('Is it damaged?', whenTrue: 'Yes')],
            'jev-latest',
        );

        [$contentType, $body] = SystemOneBody::encode($request);

        self::assertSame('application/json', $contentType);
        // Byte for byte what json_encode() has always produced for such a request.
        self::assertSame(
            '{"state":{"text":"a\/b <c> & d","list":[1,2.5,true,null]},'
            . '"questions":{"q":{"type":"noul","instructions":"Is it damaged?","criteria":{"true":"Yes"}}},'
            . '"model":"jev-latest"}',
            $body,
        );
        self::assertSame(json_encode($request, JSON_THROW_ON_ERROR), $body);
    }

    #[Test]
    public function aRequestWithoutImagesKeepsTheDefaultEscapingOfNonAsciiText(): void
    {
        $request = self::request("caf\u{E9} \u{1F600}");

        [, $body] = SystemOneBody::encode($request);

        // Non-ASCII text is written as \u escapes, as json_encode() does by default.
        self::assertSame(
            '{"state":"caf\\u00e9 \\ud83d\\ude00","questions":{"q":{"type":"noul","instructions":"Is it damaged?"}},'
            . '"model":"vision"}',
            $body,
        );
    }

    #[Test]
    public function aRequestWithAnImageIsAMultipartUploadWithTheImageAsAFilePart(): void
    {
        $image = Image::fromBytes(Samples::PNG);

        [$contentType, $body] = SystemOneBody::encode(self::request(['photo' => $image]));

        self::assertMatchesRegularExpression('~^multipart/form-data; boundary=[0-9a-f]{32}$~D', $contentType);
        $parts = RequestBody::parts(self::sent([$contentType, $body]));
        self::assertSame(['request', 'asset-0'], array_keys($parts));
        self::assertSame(Samples::PNG, $parts['asset-0']['content']);
        self::assertStringContainsString('Content-Type: application/json', $parts['request']['headers']);
        self::assertSame(
            "Content-Disposition: form-data; name=\"asset-0\"; filename=\"asset-0\"\r\nContent-Type: image/png",
            $parts['asset-0']['headers'],
        );
        self::assertSame(
            sprintf(
                '{"state":{"photo":"%1$s"},"questions":{"q":{"type":"noul","instructions":"Is it damaged?"}},'
                . '"model":"vision","assets":{"%1$s":{"type":"image","part":"asset-0"}}}',
                $image,
            ),
            $parts['request']['content'],
        );
    }

    #[Test]
    public function decodesToTheRequestWithEachImageUnderItsReference(): void
    {
        $png = Image::fromBytes(Samples::PNG);
        $jpeg = Image::fromBytes(Samples::JPEG);
        $gif = Image::fromBytes(Samples::GIF);

        $encoded = SystemOneBody::encode(self::request(['png' => $png, 'rest' => [$jpeg, $gif]]));

        self::assertSame(
            [
                'state' => ['png' => (string) $png, 'rest' => [(string) $jpeg, (string) $gif]],
                'questions' => ['q' => ['type' => 'noul', 'instructions' => 'Is it damaged?']],
                'model' => 'vision',
                'assets' => [
                    (string) $png => ['type' => 'image', 'content_type' => 'image/png', 'data' => Samples::PNG],
                    (string) $jpeg => ['type' => 'image', 'content_type' => 'image/jpeg', 'data' => Samples::JPEG],
                    (string) $gif => [
                        'type' => 'image',
                        'content_type' => 'application/octet-stream',
                        'data' => Samples::GIF,
                    ],
                ],
            ],
            RequestBody::decode(self::sent($encoded)),
        );
    }

    #[Test]
    public function sendsImagesInTheOrderTheyAppearInTheRequest(): void
    {
        $inState = Image::fromBytes('in the state');
        $inInstructions = Image::fromBytes('in the instructions');
        $inCriteria = Image::fromBytes('in the criteria');
        $question = new NoulQuestion(['Is it the item in this photo?', $inInstructions], whenTrue: $inCriteria);

        $request = self::request([$inState], $question);

        $assets = RequestBody::decode(self::sent(SystemOneBody::encode($request)))['assets'];

        self::assertIsArray($assets);
        self::assertSame(
            [(string) $inState, (string) $inInstructions, (string) $inCriteria],
            array_keys($assets),
        );
    }

    #[Test]
    public function aMentionInTextCountsAsAnAppearanceForTheOrder(): void
    {
        $placedLate = Image::fromBytes('placed in the criteria');
        $placedEarly = Image::fromBytes('placed in the state');
        $question = new NoulQuestion('Is it damaged?', whenTrue: $placedLate);

        // The state mentions the first image's reference before it is placed anywhere else.
        $request = self::request(["see {$placedLate}", $placedEarly], $question);

        $assets = RequestBody::decode(self::sent(SystemOneBody::encode($request)))['assets'];

        self::assertIsArray($assets);
        self::assertSame([(string) $placedLate, (string) $placedEarly], array_keys($assets));
    }

    #[Test]
    public function sendsAnImageOnceHoweverOftenItAppears(): void
    {
        $image = Image::fromBytes(Samples::PNG);

        $encoded = SystemOneBody::encode(self::request([$image, ['again' => $image], "text about {$image}"]));

        $parts = RequestBody::parts(self::sent($encoded));
        self::assertSame(['request', 'asset-0'], array_keys($parts));
        self::assertSame(1, substr_count($encoded[1], Samples::PNG));
    }

    #[Test]
    public function aTextReferenceToAnImageThatIsAlsoPlacedInTheRequestIsFound(): void
    {
        $image = Image::fromBytes(Samples::PNG);

        $decoded = RequestBody::decode(self::sent(SystemOneBody::encode(
            self::request([$image, "Compare {$image} with the listing."]),
        )));

        self::assertSame([(string) $image, "Compare {$image} with the listing."], $decoded['state']);
        self::assertIsArray($decoded['assets']);
        self::assertSame([(string) $image], array_keys($decoded['assets']));
    }

    #[Test]
    public function referencesNobodyKnowsStayText(): void
    {
        $placed = Image::fromBytes(Samples::PNG);
        $unplaced = Image::fromBytes(Samples::JPEG);
        $madeUp = '<image:' . str_repeat('0', 32) . '>';
        $state = ["Look at {$placed}", "and {$unplaced}", "and {$madeUp}", '<image:not-a-uuid>'];

        $decoded = RequestBody::decode(self::sent(SystemOneBody::encode(self::request($state))));

        // The state holds only text, so no image is placed: nothing is uploaded and nothing changes.
        self::assertArrayNotHasKey('assets', $decoded);
        self::assertSame($state, $decoded['state']);
    }

    #[Test]
    public function aRequestWithOnlyUnknownReferencesIsPlainJson(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $request = self::request("Look at {$image}");

        [$contentType, $body] = SystemOneBody::encode($request);

        self::assertSame('application/json', $contentType);
        self::assertSame(json_encode($request, JSON_THROW_ON_ERROR), $body);
    }

    #[Test]
    public function putsAssetsLastInTheRequestPart(): void
    {
        $decoded = RequestBody::decode(self::sent(SystemOneBody::encode(
            self::request(Image::fromBytes(Samples::PNG)),
        )));

        self::assertSame(['state', 'questions', 'model', 'assets'], array_keys($decoded));
    }

    #[Test]
    public function aWholeRequestSendsTheSameBytesEveryTimeExceptForTheBoundary(): void
    {
        $request = self::request(Image::fromBytes(Samples::PNG));

        $first = RequestBody::decode(self::sent(SystemOneBody::encode($request)));
        $second = RequestBody::decode(self::sent(SystemOneBody::encode($request)));

        self::assertSame($first, $second);
        self::assertNotSame(SystemOneBody::encode($request)[0], SystemOneBody::encode($request)[0]);
    }

    #[Test]
    public function readsAPathImageWhenTheBodyIsBuilt(): void
    {
        $path = $this->temporaryPath('late.png');
        $image = Image::fromPath($path);
        $request = self::request($image);
        file_put_contents($path, Samples::JPEG);

        $decoded = RequestBody::decode(self::sent(SystemOneBody::encode($request)));

        self::assertSame(
            [(string) $image => ['type' => 'image', 'content_type' => 'image/jpeg', 'data' => Samples::JPEG]],
            $decoded['assets'],
        );
    }

    #[Test]
    public function reportsAnUnreadableImageByItsReferenceAndKeepsTheCause(): void
    {
        $path = $this->temporaryPath('missing.png');
        $image = Image::fromPath($path);

        try {
            SystemOneBody::encode(self::request(['photo' => $image]));
            self::fail('Expected an exception.');
        } catch (AssetException $exception) {
            self::assertSame(
                "Asset '{$image}': Image file '{$path}' could not be read: No such file or directory.",
                $exception->getMessage(),
            );
            self::assertInstanceOf(AssetException::class, $exception->getPrevious());
            self::assertSame(
                "Image file '{$path}' could not be read: No such file or directory.",
                $exception->getPrevious()->getMessage(),
            );
        }
    }

    #[Test]
    public function reportsTheFirstUnreadableImageInTheOrderTheyAreSent(): void
    {
        $first = Image::fromPath($this->temporaryPath('first-missing.png'));
        $second = Image::fromPath($this->temporaryPath('second-missing.png'));
        $fine = Image::fromBytes(Samples::PNG);

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("Asset '{$first}': Image file ");

        SystemOneBody::encode(self::request([$fine, $first, $second]));
    }

    #[Test]
    public function reportsAnEmptyFileAsHavingNoData(): void
    {
        $image = Image::fromPath($this->temporaryFile('empty.png', ''));

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("Asset '{$image}': Image data is empty.");

        SystemOneBody::encode(self::request($image));
    }
}
