<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\AttachedAssets;
use TypesafeAi\Request\Image;
use TypesafeAi\Tests\Support\Samples;

final class AttachedAssetsTest extends TestCase
{
    #[Test]
    public function startsWithNothingAttached(): void
    {
        self::assertSame([], (new AttachedAssets())->snapshot());
    }

    #[Test]
    public function attachesOneImageByItsReference(): void
    {
        $assets = new AttachedAssets();
        $image = Image::fromBytes(Samples::PNG);

        $held = $assets->attach($image);

        self::assertSame([$image], $held);
        self::assertSame([(string) $image => $image], $assets->snapshot());
    }

    #[Test]
    public function attachesACollectionOfImagesAndIgnoresItsKeys(): void
    {
        $assets = new AttachedAssets();
        $first = Image::fromBytes('first');
        $second = Image::fromBytes('second');

        $held = $assets->attach(['a' => $first, 7 => $second]);

        self::assertSame([$first, $second], $held);
        self::assertSame([(string) $first => $first, (string) $second => $second], $assets->snapshot());
    }

    #[Test]
    public function aReleasedImageIsNoLongerAttached(): void
    {
        $assets = new AttachedAssets();
        $kept = Image::fromBytes('kept');
        $released = Image::fromBytes('released');
        $assets->attach($kept);

        $assets->release($assets->attach($released));

        self::assertSame([(string) $kept => $kept], $assets->snapshot());
    }

    #[Test]
    public function anImageAttachedTwiceStaysAttachedUntilTheLastHoldIsReleased(): void
    {
        $assets = new AttachedAssets();
        $image = Image::fromBytes(Samples::PNG);

        $outer = $assets->attach($image);
        $inner = $assets->attach([$image]);
        $assets->release($inner);
        self::assertSame([(string) $image => $image], $assets->snapshot());

        $assets->release($outer);
        self::assertSame([], $assets->snapshot());
    }

    #[Test]
    public function theSameImageTwiceInOneCollectionHoldsItTwice(): void
    {
        $assets = new AttachedAssets();
        $image = Image::fromBytes(Samples::PNG);

        $held = $assets->attach([$image, $image]);
        $assets->release([$image]);
        self::assertSame([(string) $image => $image], $assets->snapshot());

        $assets->release($held);
        self::assertSame([], $assets->snapshot());
    }

    #[Test]
    public function aCloneSharesTheHoldsOfItsOriginal(): void
    {
        $assets = new AttachedAssets();
        $image = Image::fromBytes(Samples::PNG);

        $held = $assets->attach($image);
        $assets->attach(clone $image);
        $assets->release($held);

        self::assertCount(1, $assets->snapshot());
    }

    #[Test]
    public function releasingAnImageThatIsNotAttachedChangesNothing(): void
    {
        $assets = new AttachedAssets();
        $attached = Image::fromBytes('attached');
        $assets->attach($attached);

        $assets->release([Image::fromBytes('stranger')]);

        self::assertSame([(string) $attached => $attached], $assets->snapshot());
    }

    #[Test]
    public function aSnapshotIsNotChangedByLaterAttachments(): void
    {
        $assets = new AttachedAssets();
        $before = $assets->snapshot();

        $assets->attach(Image::fromBytes(Samples::PNG));

        self::assertSame([], $before);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function notAnImageProvider(): iterable
    {
        yield 'the bytes of an image' => [Samples::PNG, 'string'];
        yield 'null' => [null, 'null'];
        yield 'an array' => [['type' => 'image'], 'array'];
        yield 'another object' => [new \stdClass(), 'stdClass'];
    }

    #[Test]
    #[DataProvider('notAnImageProvider')]
    public function holdsNothingWhenAValueIsNotAnImage(mixed $value, string $type): void
    {
        $assets = new AttachedAssets();

        try {
            $assets->attach([Image::fromBytes('valid'), $value]);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                sprintf('attached values must be TypesafeAi\Request\Image instances, got %s.', $type),
                $exception->getMessage(),
            );
        }

        // The image that came before the invalid value was not attached either.
        self::assertSame([], $assets->snapshot());
    }
}
