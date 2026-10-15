<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TypesafeAi\Client;
use TypesafeAi\ClientOptions;
use TypesafeAi\Exception\AssetException;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\ChoiceQuestion;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Question\QuestionInterface;
use TypesafeAi\Request\Question\ScoreQuestion;
use TypesafeAi\Request\Questions;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Testing\RequestBody;
use TypesafeAi\Tests\Support\FakeApi;
use TypesafeAi\Tests\Support\Samples;
use TypesafeAi\Tests\Support\UsesTemporaryDirectory;

/**
 * Images in a request, end to end through {@see Client}: ported from the official SDK (0.7.5)'s
 * `test_inline_images.py` and `test_assets.py`, which are the behaviour this client mirrors.
 */
final class ClientImagesTest extends TestCase
{
    use UsesTemporaryDirectory;

    #[Test]
    public function imagesInTheStateAndInEveryKindOfQuestionAreFoundAndSentOnceEach(): void
    {
        $returned = Image::fromBytes(Samples::PNG);
        $listing = Image::fromBytes(Samples::JPEG);
        $custom = new class ($returned, $listing) implements QuestionInterface {
            public function __construct(private readonly Image $instructions, private readonly Image $true)
            {
            }

            public function type(): string
            {
                return 'noul';
            }

            /**
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return ['type' => 'noul', 'instructions' => $this->instructions, 'criteria' => ['true' => $this->true]];
            }
        };
        $request = new SystemOneRequest(
            [['role' => 'user', 'msg' => ['Here is what came back:', $returned]], ['listing', $listing]],
            [
                'same' => new NoulQuestion(['Is it the item in this photo?', $listing]),
                'condition' => new ChoiceQuestion('How is it?', ['new' => $returned, 'used' => null]),
                'damage' => new ScoreQuestion('How bad?', ['none', ['example' => [$returned]]]),
                'custom' => $custom,
            ],
            'vision',
        );
        $api = (new FakeApi())->answering();

        $api->client->systemOne($request);

        $r = (string) $returned;
        $l = (string) $listing;
        self::assertSame(
            [
                'state' => [['role' => 'user', 'msg' => ['Here is what came back:', $r]], ['listing', $l]],
                'questions' => [
                    'same' => ['type' => 'noul', 'instructions' => ['Is it the item in this photo?', $l]],
                    'condition' => [
                        'type' => 'choice',
                        'instructions' => 'How is it?',
                        'criteria' => ['new' => $r, 'used' => null],
                    ],
                    'damage' => [
                        'type' => 'score',
                        'instructions' => 'How bad?',
                        'criteria' => ['none', ['example' => [$r]]],
                    ],
                    'custom' => ['type' => 'noul', 'instructions' => $r, 'criteria' => ['true' => $l]],
                ],
                'model' => 'vision',
                'assets' => [
                    $r => ['type' => 'image', 'content_type' => 'image/png', 'data' => Samples::PNG],
                    $l => ['type' => 'image', 'content_type' => 'image/jpeg', 'data' => Samples::JPEG],
                ],
            ],
            $api->body(),
        );
    }

    #[Test]
    public function imagesInPlainObjectsAndJsonSerializableValuesAreFoundToo(): void
    {
        $inObject = Image::fromBytes('in an object');
        $inCollection = Image::fromBytes('in an ArrayObject');
        $inSerializable = Image::fromBytes('in a JsonSerializable');
        $serializable = new class ($inSerializable) implements \JsonSerializable {
            public function __construct(private readonly Image $image)
            {
            }

            /**
             * @return array<string, Image>
             */
            public function jsonSerialize(): array
            {
                return ['wrapped' => $this->image];
            }
        };
        $api = (new FakeApi())->answering();

        $api->ask([(object) ['photo' => $inObject], new \ArrayObject(['photo' => $inCollection]), $serializable]);

        self::assertSame(
            [(string) $inObject, (string) $inCollection, (string) $inSerializable],
            array_keys($api->assets()),
        );
    }

    #[Test]
    public function anImageCanBeTheWholeState(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $api = (new FakeApi())->answering();

        $api->ask($image);

        $body = $api->body();
        self::assertSame((string) $image, $body['state']);
        self::assertSame(
            [(string) $image => ['type' => 'image', 'content_type' => 'image/png', 'data' => Samples::PNG]],
            $body['assets'],
        );
    }

    #[Test]
    public function contentWithoutImagesStaysAPlainJsonRequest(): void
    {
        $api = (new FakeApi())->answering(2);

        $api->ask('text');
        $api->ask(['a' => [1, 'x', null, [true, 0.5]]]);

        foreach ($api->http->requests() as $index => $request) {
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
            self::assertArrayNotHasKey('assets', $api->body($index));
        }
        self::assertSame(
            ['q' => ['type' => 'noul', 'instructions' => 'Is it damaged?']],
            $api->body()['questions'],
        );
    }

    #[Test]
    public function aRequestWithImagesIsAMultipartUploadAndKeepsTheOtherHeaders(): void
    {
        $api = (new FakeApi())->answering();

        $api->ask(Image::fromBytes(Samples::PNG));

        $request = $api->http->lastRequest();
        self::assertNotNull($request);
        self::assertMatchesRegularExpression(
            '~^multipart/form-data; boundary=[0-9a-f]{32}$~D',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('Expect'));
        self::assertFalse($request->hasHeader('X-TypeSafe-Retry-Count'));
    }

    #[Test]
    public function aMultipartRequestStillTakesTheCallersHeadersButNotItsContentType(): void
    {
        $api = new FakeApi(new ClientOptions(headers: ['Content-Type' => 'text/plain', 'X-Tenant' => '42']));
        $api->answering();

        $api->ask(Image::fromBytes(Samples::PNG));

        $request = $api->http->lastRequest();
        self::assertNotNull($request);
        self::assertSame(['42'], $request->getHeader('X-Tenant'));
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function anImageThatCannotBeReadIsReportedByItsReferenceBeforeAnyRequestIsSent(): void
    {
        $path = $this->temporaryPath('missing.png');
        $image = Image::fromPath($path);
        $api = new FakeApi();

        try {
            $api->ask(['photos' => [$image]]);
            self::fail('Expected an exception.');
        } catch (AssetException $exception) {
            self::assertSame(
                "Asset '{$image}': Image file '{$path}' could not be read: No such file or directory.",
                $exception->getMessage(),
            );
        }

        self::assertSame([], $api->http->requests());
    }

    #[Test]
    public function anUnreadableImageIsNeverRetried(): void
    {
        $api = new FakeApi(new ClientOptions(maxRetries: 3));
        $image = Image::fromPath($this->temporaryPath('missing.png'));

        try {
            $api->ask($image);
            self::fail('Expected an exception.');
        } catch (AssetException) {
            // Expected.
        }

        self::assertCount(0, $api->http->requests());
    }

    #[Test]
    public function aPathIsReadWhenTheRequestIsSentAndNotWhenTheImageIsCreated(): void
    {
        $path = $this->temporaryPath('photo.jpg');
        $image = Image::fromPath($path);
        file_put_contents($path, Samples::JPEG);
        $api = (new FakeApi())->answering();

        $api->ask($image);

        self::assertSame(
            [(string) $image => ['type' => 'image', 'content_type' => 'image/jpeg', 'data' => Samples::JPEG]],
            $api->body()['assets'],
        );
    }

    #[Test]
    public function anImageIsReadOnlyOnceEvenIfTheFileChangesBetweenRequests(): void
    {
        $path = $this->temporaryFile('photo.png', Samples::PNG);
        $image = Image::fromPath($path);
        $api = (new FakeApi())->answering(2);

        $api->ask($image);
        file_put_contents($path, Samples::JPEG);
        $api->ask($image);

        self::assertSame(Samples::PNG, $api->assetData((string) $image, 0));
        self::assertSame(Samples::PNG, $api->assetData((string) $image, 1));
    }

    #[Test]
    public function anImageThatCannotBeReadYetIsReadByALaterRequest(): void
    {
        $path = $this->temporaryPath('later.png');
        $image = Image::fromPath($path);
        $api = (new FakeApi())->answering();

        try {
            $api->ask($image);
            self::fail('Expected an exception.');
        } catch (AssetException) {
            // Expected: nothing was sent, and the failure is not remembered.
        }
        file_put_contents($path, Samples::PNG);
        $api->ask($image);

        self::assertCount(1, $api->http->requests());
        self::assertSame(Samples::PNG, $api->assetData((string) $image));
    }

    #[Test]
    public function anImageThatTheCallersOwnJsonEncodeMeetsWhileTheRequestIsEncodedIsUploaded(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $embedding = new class ($image) implements \JsonSerializable {
            public function __construct(private readonly Image $image)
            {
            }

            public function jsonSerialize(): string
            {
                // The caller embeds a JSON document in a string: this nested call meets the image,
                // which counts as placed in the request.
                return json_encode(['photo' => $this->image], JSON_THROW_ON_ERROR);
            }
        };
        $api = (new FakeApi())->answering();

        $api->ask(['note' => $embedding]);

        self::assertSame(['note' => sprintf('{"photo":"%s"}', $image)], $api->body()['state']);
        self::assertSame([(string) $image], array_keys($api->assets()));
        self::assertSame(Samples::PNG, $api->assetData((string) $image));
    }

    #[Test]
    public function aRetryResendsExactlyTheSameBytesWithTheRetryCount(): void
    {
        $api = new FakeApi();
        $api->http->queue(JsonResponse::fromArray(429, ['detail' => 'slow down'], ['retry-after-ms' => '1']));
        $api->answering();

        $api->ask(['photo' => Image::fromBytes(Samples::PNG)]);

        [$first, $second] = $api->http->requests();
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame($first->getHeaderLine('Content-Type'), $second->getHeaderLine('Content-Type'));
        self::assertFalse($first->hasHeader('X-TypeSafe-Retry-Count'));
        self::assertSame('1', $second->getHeaderLine('X-TypeSafe-Retry-Count'));
    }

    #[Test]
    public function aRetryDoesNotReadTheFileAgain(): void
    {
        $path = $this->temporaryFile('photo.png', Samples::PNG);
        $http = new class ($path) implements HttpClientInterface {
            /** @var list<string> */
            public array $bodies = [];

            public function __construct(private readonly string $path)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->bodies[] = (string) $request->getBody();
                if (count($this->bodies) === 1) {
                    // The file changes after the first attempt, as the buffer does in the official SDK's test.
                    file_put_contents($this->path, Samples::JPEG);

                    return JsonResponse::fromArray(429, ['detail' => 'slow down'], ['retry-after-ms' => '1']);
                }

                return JsonResponse::fromArray(200, [
                    'model' => 'vision',
                    'answers' => ['q' => ['type' => 'noul', 'noul' => 0.5]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]);
            }
        };

        (new Client('test-key', $http))->systemOne(FakeApi::request(Image::fromPath($path)));

        self::assertCount(2, $http->bodies);
        self::assertSame($http->bodies[0], $http->bodies[1]);
        self::assertStringContainsString(Samples::PNG, $http->bodies[1]);
        self::assertStringNotContainsString(Samples::JPEG, $http->bodies[1]);
    }

    #[Test]
    public function aFormattedReferenceToAnImagePlacedInTheContentIsFound(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $api = (new FakeApi())->answering();

        $api->ask([$image, "Compare {$image} with the listing."]);

        self::assertSame([(string) $image], array_keys($api->assets()));
    }

    #[Test]
    public function aFormattedReferenceAloneDoesNotSendTheImage(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $api = (new FakeApi())->answering();

        $api->ask("Compare {$image} with the listing.");

        $request = $api->http->lastRequest();
        self::assertNotNull($request);
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame("Compare {$image} with the listing.", $api->body()['state']);
    }

    #[Test]
    public function namedImagesAreUploadedAndTheirNamesWorkInTheTextOfEveryKindOfQuestion(): void
    {
        $questions = [
            'n' => new NoulQuestion(['image' => '<png>'], whenTrue: ['Like <jpeg>']),
            'c' => new ChoiceQuestion('Which?', ['match' => '<png>', 'other' => null]),
            's' => new ScoreQuestion('How much?', ['<jpeg>', 'nothing']),
        ];
        $api = (new FakeApi())->answering();

        $api->client->systemOne(new SystemOneRequest(
            'Compare the images.',
            $questions,
            'vision',
            ['<png>' => Image::fromBytes(Samples::PNG), '<jpeg>' => Image::fromBytes(Samples::JPEG)],
        ));

        $body = $api->body();
        self::assertSame(
            [
                'n' => [
                    'type' => 'noul',
                    'instructions' => ['image' => '<png>'],
                    'criteria' => ['true' => ['Like <jpeg>']],
                ],
                'c' => [
                    'type' => 'choice',
                    'instructions' => 'Which?',
                    'criteria' => ['match' => '<png>', 'other' => null],
                ],
                's' => ['type' => 'score', 'instructions' => 'How much?', 'criteria' => ['<jpeg>', 'nothing']],
            ],
            $body['questions'],
        );
        self::assertSame(['<png>', '<jpeg>'], array_keys($api->assets()));
        self::assertSame(Samples::PNG, $api->assetData('<png>'));
        self::assertSame(Samples::JPEG, $api->assetData('<jpeg>'));
    }

    #[Test]
    public function withoutNamedImagesTheRequestIsPlainJsonWithNoAssets(): void
    {
        $api = (new FakeApi())->answering();

        $api->ask('text', []);

        $request = $api->http->lastRequest();
        self::assertNotNull($request);
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertArrayNotHasKey('assets', $api->body());
    }

    /**
     * @return iterable<string, array{0: \Closure(self): Image, 1: \Closure(self, Image): string}>
     */
    public static function unreadableImageProvider(): iterable
    {
        yield 'a missing file' => [
            static fn (self $test): Image => Image::fromPath($test->temporaryPath('missing.png')),
            static fn (self $test, Image $image): string => sprintf(
                "Asset '<photo>': Image file '%s' could not be read: No such file or directory.",
                $test->temporaryPath('missing.png'),
            ),
        ];
        yield 'a directory' => [
            static function (self $test): Image {
                $test->temporaryFile('inside.png', Samples::PNG);

                return Image::fromPath(dirname($test->temporaryPath('inside.png')));
            },
            static fn (self $test, Image $image): string => sprintf(
                "Asset '<photo>': Image file '%s' could not be read: Is a directory.",
                dirname($test->temporaryPath('inside.png')),
            ),
        ];
        yield 'an empty file' => [
            static fn (self $test): Image => Image::fromPath($test->temporaryFile('empty.png', '')),
            static fn (self $test, Image $image): string => "Asset '<photo>': Image data is empty.",
        ];
    }

    /**
     * @param \Closure(self): Image $make
     * @param \Closure(self, Image): string $message
     */
    #[Test]
    #[DataProvider('unreadableImageProvider')]
    public function aNamedImageThatCannotBeReadIsReportedByItsNameAndNothingIsSent(
        \Closure $make,
        \Closure $message,
    ): void {
        $image = $make($this);
        $api = new FakeApi();

        try {
            $api->ask('<photo>', ['<photo>' => $image]);
            self::fail('Expected an exception.');
        } catch (AssetException $exception) {
            self::assertSame($message($this, $image), $exception->getMessage());
        }

        self::assertSame([], $api->http->requests());
    }

    #[Test]
    public function theFirstNamedImageThatCannotBeReadIsReportedInTheOrderGiven(): void
    {
        $api = new FakeApi();
        $assets = [
            '<ok>' => Image::fromBytes(Samples::PNG),
            '<missing>' => Image::fromPath($this->temporaryPath('missing.png')),
            '<empty>' => Image::fromPath($this->temporaryFile('empty.png', '')),
        ];

        $this->expectException(AssetException::class);
        $this->expectExceptionMessageMatches("/^Asset '<missing>': Image file '.*missing\\.png' could not be read/");

        $api->ask('<ok> <missing> <empty>', $assets);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fileBytesProvider(): iterable
    {
        yield 'PNG' => [Samples::PNG, 'image/png'];
        yield 'JPEG' => [Samples::JPEG, 'image/jpeg'];
    }

    #[Test]
    #[DataProvider('fileBytesProvider')]
    public function aNamedPathIsReadWhenTheRequestIsSentNotWhenTheImageIsCreated(string $bytes, string $type): void
    {
        $path = $this->temporaryPath('photo');
        $image = Image::fromPath($path);
        file_put_contents($path, $bytes);
        $api = (new FakeApi())->answering();

        $api->ask('<photo>', ['<photo>' => $image]);

        self::assertSame(
            ['<photo>' => ['type' => 'image', 'content_type' => $type, 'data' => $bytes]],
            $api->assets(),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function base64TextProvider(): iterable
    {
        $encoded = base64_encode(str_repeat(Samples::JPEG, 20));

        yield 'plain' => [$encoded];
        yield 'wrapped like MIME' => [chunk_split($encoded, 76, "\r\n")];
        yield 'followed by a line break' => [$encoded . "\r\n"];
    }

    #[Test]
    #[DataProvider('base64TextProvider')]
    public function anImageMadeFromBase64TextIsUploadedAsTheBytesItDecodesTo(string $text): void
    {
        $api = (new FakeApi())->answering();

        $api->ask('<photo>', ['<photo>' => Image::fromBase64($text)]);

        self::assertSame(
            [
                '<photo>' => [
                    'type' => 'image',
                    'content_type' => 'image/jpeg',
                    'data' => str_repeat(Samples::JPEG, 20),
                ],
            ],
            $api->assets(),
        );
    }

    #[Test]
    public function theMultipartBodyHasOnePartPerNameInTheDocumentedLayout(): void
    {
        $png = Image::fromBytes(Samples::PNG);
        $api = (new FakeApi())->answering();

        $api->ask(
            '<a> <b> <c>',
            ['<a>' => $png, '<b>' => $png, '<c>' => Image::fromBase64(base64_encode(Samples::JPEG))],
        );

        $request = $api->http->lastRequest();
        self::assertNotNull($request);
        $parts = RequestBody::parts($request);
        self::assertSame(['request', 'asset-0', 'asset-1', 'asset-2'], array_keys($parts));
        self::assertStringContainsString('Content-Type: application/json', $parts['request']['headers']);
        self::assertStringContainsString(
            '"assets":{"<a>":{"type":"image","part":"asset-0"},"<b>":{"type":"image","part":"asset-1"},'
            . '"<c>":{"type":"image","part":"asset-2"}}',
            $parts['request']['content'],
        );
        // The API wants each part claimed by exactly one asset: an image with two names has two parts.
        $disposition = 'Content-Disposition: form-data; name="asset-%1$d"; filename="asset-%1$d"';
        self::assertSame(
            [
                'headers' => sprintf($disposition, 0) . "\r\nContent-Type: image/png",
                'content' => Samples::PNG,
            ],
            $parts['asset-0'],
        );
        self::assertSame(
            [
                'headers' => sprintf($disposition, 1) . "\r\nContent-Type: image/png",
                'content' => Samples::PNG,
            ],
            $parts['asset-1'],
        );
        self::assertSame(
            [
                'headers' => sprintf($disposition, 2) . "\r\nContent-Type: image/jpeg",
                'content' => Samples::JPEG,
            ],
            $parts['asset-2'],
        );
    }

    #[Test]
    public function aFormatThisClientDoesNotRecogniseIsStillSentLabelledAsBinaryData(): void
    {
        $api = (new FakeApi())->answering();

        $api->ask('<photo>', ['<photo>' => Image::fromBytes(Samples::GIF)]);

        self::assertSame(
            ['<photo>' => ['type' => 'image', 'content_type' => 'application/octet-stream', 'data' => Samples::GIF]],
            $api->assets(),
        );
    }

    #[Test]
    public function namedAndPlacedImagesAreBothUploadedNamedFirst(): void
    {
        $inline = Image::fromBytes(Samples::JPEG);
        $api = (new FakeApi())->answering();

        $api->ask(['Compare with <listing>.', $inline], ['<listing>' => Image::fromBytes(Samples::PNG)]);

        self::assertSame(['<listing>', (string) $inline], array_keys($api->assets()));
        self::assertSame(Samples::PNG, $api->assetData('<listing>'));
        self::assertSame(Samples::JPEG, $api->assetData((string) $inline));
    }

    #[Test]
    public function aNamedImageThatIsAlsoPlacedInTheContentIsUploadedOnceUnderItsName(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $request = new SystemOneRequest(
            ['Compare <photo> with', $image],
            ['q' => new NoulQuestion(['Is it damaged?', $image])],
            'vision',
            ['<photo>' => $image],
        );
        $api = (new FakeApi())->answering();

        $api->client->systemOne($request);

        $body = $api->body();
        self::assertSame(['<photo>'], array_keys($api->assets()));
        self::assertSame(['Compare <photo> with', '<photo>'], $body['state']);
        self::assertSame(
            ['q' => ['type' => 'noul', 'instructions' => ['Is it damaged?', '<photo>']]],
            $body['questions'],
        );
    }

    #[Test]
    public function aNamedImageWrittenIntoTextAsItsReferenceNeedsNoAttachmentAndIsReadOnce(): void
    {
        $missing = Image::fromPath($this->temporaryPath('missing.png'));
        $api = (new FakeApi())->answering();

        // The text holds the reference, so the image is sent under it as well as under its name, but
        // the name is what the failure reports.
        try {
            $api->ask("<photo> is {$missing}", ['<photo>' => $missing]);
            self::fail('Expected an exception.');
        } catch (AssetException $exception) {
            self::assertStringStartsWith("Asset '<photo>': Image file ", $exception->getMessage());
        }

        $image = Image::fromBytes(Samples::PNG);
        $api->ask("<photo> is {$image}", ['<photo>' => $image]);

        self::assertSame(['<photo>', (string) $image], array_keys($api->assets()));
        self::assertSame(Samples::PNG, $api->assetData((string) $image));
        self::assertCount(1, $api->http->requests());
    }

    #[Test]
    public function referencesThatNothingKnowsAreSentAsTextAlongsideNamedImages(): void
    {
        $unknown = Image::fromBytes(Samples::PNG);
        $state = "Look at {$unknown} and <image:" . str_repeat('0', 32) . '>';
        $api = (new FakeApi())->answering();

        $api->ask($state, ['<photo>' => Image::fromBytes(Samples::PNG)]);

        self::assertSame($state, $api->body()['state']);
        self::assertSame(['<photo>'], array_keys($api->assets()));
    }

    #[Test]
    public function aRetryOfANamedImageResendsTheBytesOfTheFirstAttempt(): void
    {
        $api = new FakeApi();
        $path = $this->temporaryFile('photo.png', Samples::PNG);
        $api->http->queue(JsonResponse::fromArray(429, ['detail' => 'slow down'], ['retry-after-ms' => '1']));
        $api->answering();

        $api->ask('<photo>', ['<photo>' => Image::fromPath($path)]);

        [$first, $second] = $api->http->requests();
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame(Samples::PNG, $api->assetData('<photo>', 0));
        self::assertSame(Samples::PNG, $api->assetData('<photo>', 1));
    }

    #[Test]
    public function evaluateUploadsTheNamedImages(): void
    {
        $api = new FakeApi();
        $api->http->queue(JsonResponse::fromArray(200, [
            'model' => 'vision',
            'answers' => ['question_0' => ['type' => 'noul', 'noul' => 0.9]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));

        $result = $api->client->evaluate(
            '<photo>',
            Questions::create()->noul('Is <photo> damaged?'),
            assets: ['<photo>' => Image::fromBytes(Samples::PNG)],
        );

        self::assertSame(0.9, $result->noul(0)->noul());
        self::assertSame(Samples::PNG, $api->assetData('<photo>'));
    }

    #[Test]
    public function fillingInTheDefaultModelKeepsTheNamedImages(): void
    {
        $api = new FakeApi(new ClientOptions(defaultModel: 'jev-preview'));
        $api->answering();

        $api->client->systemOne(new SystemOneRequest(
            '<photo>',
            ['q' => new NoulQuestion('Is <photo> damaged?')],
            assets: ['<photo>' => Image::fromBytes(Samples::PNG)],
        ));

        self::assertSame('jev-preview', $api->body()['model']);
        self::assertSame(Samples::PNG, $api->assetData('<photo>'));
    }
}
