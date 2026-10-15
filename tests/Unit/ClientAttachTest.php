<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Client;
use TypesafeAi\Exception\AssetException;
use TypesafeAi\Request\AttachedAssets;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\Question\NoulQuestion;
use TypesafeAi\Request\Questions;
use TypesafeAi\Request\SystemOneRequest;
use TypesafeAi\Testing\JsonResponse;
use TypesafeAi\Tests\Support\FakeApi;
use TypesafeAi\Tests\Support\Samples;
use TypesafeAi\Tests\Support\UsesTemporaryDirectory;

/**
 * {@see Client::attachAssets()}, ported from the official SDK (0.7.5)'s `test_inline_images.py`,
 * whose `attach_assets` is a context manager where this is a callback.
 */
final class ClientAttachTest extends TestCase
{
    use UsesTemporaryDirectory;

    /**
     * @return array<string, Image>
     */
    private static function attachedTo(Client $client): array
    {
        $attached = (new \ReflectionProperty(Client::class, 'attached'))->getValue($client);
        self::assertInstanceOf(AttachedAssets::class, $attached);

        return $attached->snapshot();
    }

    #[Test]
    public function aReferenceInTextUploadsTheAttachedImage(): void
    {
        $returned = Image::fromBytes(Samples::PNG);
        $listing = Image::fromBytes(Samples::JPEG);
        $api = (new FakeApi())->answering();

        $api->client->attachAssets([$returned, $listing], function () use ($api, $returned, $listing): void {
            $api->client->systemOne(new SystemOneRequest(
                "The customer says {$returned} arrived scratched.",
                ['same' => new NoulQuestion("Is {$returned} the item in {$listing}?")],
                'vision',
            ));
        });

        $body = $api->body();
        self::assertSame("The customer says {$returned} arrived scratched.", $body['state']);
        self::assertIsArray($body['questions']);
        self::assertSame(
            ['type' => 'noul', 'instructions' => "Is {$returned} the item in {$listing}?"],
            $body['questions']['same'],
        );
        self::assertSame([(string) $returned, (string) $listing], array_keys($api->assets()));
        self::assertSame(Samples::PNG, $api->assetData((string) $returned));
        self::assertSame(Samples::JPEG, $api->assetData((string) $listing));
    }

    #[Test]
    public function aSingleImageCanBeAttachedWithoutAnArray(): void
    {
        $photo = Image::fromBytes(Samples::PNG);
        $api = (new FakeApi())->answering();

        $api->client->attachAssets($photo, fn () => $api->ask("Product: {$photo}"));

        self::assertSame([(string) $photo], array_keys($api->assets()));
    }

    #[Test]
    public function onlyAttachedImagesThatTheRequestMentionsAreSent(): void
    {
        $first = Image::fromBytes(Samples::PNG);
        $second = Image::fromBytes(Samples::JPEG);
        $api = (new FakeApi())->answering(2);

        $api->client->attachAssets([$first, $second], function () use ($api, $first): void {
            $api->ask("Look at {$first}");
            $api->ask('No images here.');
        });

        self::assertSame([(string) $first], array_keys($api->assets(0)));
        self::assertArrayNotHasKey('assets', $api->body(1));
        self::assertSame('application/json', $api->http->requests()[1]->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function attachmentsNestAndEndWithTheirCallback(): void
    {
        $outer = Image::fromBytes(Samples::PNG);
        $inner = Image::fromBytes(Samples::JPEG);
        $api = (new FakeApi())->answering(3);
        $text = "{$outer} {$inner}";

        $api->client->attachAssets($outer, function () use ($api, $inner, $text): void {
            $api->client->attachAssets($inner, fn () => $api->ask($text));
            $api->ask($text);
        });
        $api->ask($text);

        self::assertSame([(string) $outer, (string) $inner], array_keys($api->assets(0)));
        self::assertSame([(string) $outer], array_keys($api->assets(1)));
        self::assertSame([], $api->assets(2));
        self::assertSame([], self::attachedTo($api->client));
    }

    #[Test]
    public function attachmentsBelongToTheClientThatMadeThem(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $attaching = new FakeApi();
        $other = (new FakeApi())->answering();

        $attaching->client->attachAssets($image, fn () => $other->ask("{$image}"));

        self::assertSame([], $other->assets());
        self::assertSame([], self::attachedTo($other->client));
    }

    #[Test]
    public function returnsWhateverTheCallbackReturns(): void
    {
        $api = new FakeApi();

        self::assertSame('result', $api->client->attachAssets(Image::fromBytes(Samples::PNG), fn () => 'result'));
        self::assertSame([], self::attachedTo($api->client));
    }

    #[Test]
    public function aFailingCallbackStillEndsTheAttachment(): void
    {
        $api = new FakeApi();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('callback failed');

        try {
            $api->client->attachAssets(Image::fromBytes(Samples::PNG), function (): void {
                throw new \DomainException('callback failed');
            });
        } finally {
            self::assertSame([], self::attachedTo($api->client));
        }
    }

    #[Test]
    public function aFailingRequestStillEndsTheAttachment(): void
    {
        $image = Image::fromPath($this->temporaryPath('missing.png'));
        $api = new FakeApi();

        try {
            $api->client->attachAssets($image, fn () => $api->ask("{$image}"));
            self::fail('Expected an exception.');
        } catch (AssetException) {
            // Expected.
        }

        self::assertSame([], self::attachedTo($api->client));
    }

    #[Test]
    public function anInvalidValueAttachesNothingAndTheCallbackNeverRuns(): void
    {
        $api = new FakeApi();
        $ran = false;

        try {
            $api->client->attachAssets([Image::fromBytes(Samples::PNG), 'image'], function () use (&$ran): void {
                $ran = true;
            });
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'attached values must be TypesafeAi\Request\Image instances, got string.',
                $exception->getMessage(),
            );
        }

        self::assertFalse($ran);
        self::assertSame([], self::attachedTo($api->client));
    }

    #[Test]
    public function aMentionOfAnAttachedImageThatCannotBeReadIsReportedByItsReference(): void
    {
        $path = $this->temporaryPath('missing.png');
        $image = Image::fromPath($path);
        $api = new FakeApi();

        try {
            $api->client->attachAssets($image, fn () => $api->ask("Look at {$image}"));
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
    public function anAttachedPathIsOnlyReadWhenARequestMentionsIt(): void
    {
        $path = $this->temporaryPath('never-read.png');
        $image = Image::fromPath($path);
        $api = (new FakeApi())->answering();

        // The file does not exist, which would be an error if the request had to send it.
        $api->client->attachAssets($image, fn () => $api->ask('Nothing about the image.'));

        self::assertCount(1, $api->http->requests());
    }

    #[Test]
    public function attachedImagesAreFoundInQuestionsBuiltWithTheBuilder(): void
    {
        $photo = Image::fromBytes(Samples::PNG);
        $api = new FakeApi();
        $api->http->queue(JsonResponse::fromArray(200, [
            'model' => 'vision',
            'answers' => ['question_0' => ['type' => 'noul', 'noul' => 0.9]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));

        $api->client->attachAssets($photo, fn () => $api->client->evaluate(
            'a product',
            Questions::create()->noul("Is {$photo} damaged?"),
        ));

        self::assertSame(Samples::PNG, $api->assetData((string) $photo));
    }

    #[Test]
    public function callsMadeByFibersSeeTheAttachments(): void
    {
        $images = array_map(static fn (int $index): Image => Image::fromBytes("image {$index}"), range(0, 7));
        $api = (new FakeApi())->answering(count($images));

        $api->client->attachAssets($images, function () use ($api, $images): void {
            $fibers = array_map(
                static fn (Image $image): \Fiber => new \Fiber(static function () use ($api, $image): void {
                    \Fiber::suspend();
                    $api->ask("{$image}");
                }),
                $images,
            );
            foreach ($fibers as $fiber) {
                $fiber->start();
            }
            foreach (array_reverse($fibers) as $fiber) {
                $fiber->resume();
            }
        });

        $sent = [];
        foreach (array_keys($api->http->requests()) as $index) {
            $sent[] = array_keys($api->assets($index));
        }
        $expected = array_map(static fn (Image $image): array => [(string) $image], array_reverse($images));
        self::assertSame($expected, $sent);
    }

    #[Test]
    public function overlappingAttachmentsHoldAnImageUntilTheLastOneEnds(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $api = (new FakeApi())->answering(2);
        // Two flows attach the same image and wait: the attachments overlap without nesting.
        $first = new \Fiber(static fn () => $api->client->attachAssets($image, static fn () => \Fiber::suspend()));
        $second = new \Fiber(static fn () => $api->client->attachAssets($image, static fn () => \Fiber::suspend()));
        $first->start();
        $second->start();

        $first->resume();
        $api->ask("{$image}");
        $second->resume();
        $api->ask("{$image}");

        self::assertSame([(string) $image], array_keys($api->assets(0)));
        self::assertSame([], $api->assets(1));
        self::assertSame([], self::attachedTo($api->client));
    }
}
