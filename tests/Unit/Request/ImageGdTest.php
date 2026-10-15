<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Image;
use TypesafeAi\Tests\Support\FakeApi;

/**
 * {@see Image::fromGd()}, ported from the official SDK (0.7.5)'s tests of `Image.from_pil`. They
 * need the GD extension and are skipped without it.
 */
#[RequiresPhpExtension('gd')]
final class ImageGdTest extends TestCase
{
    /**
     * A solid red image.
     *
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function redImage(int $width = 4, int $height = 4): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            self::fail('GD could not create an image.');
        }

        $red = imagecolorallocate($image, 255, 0, 0);
        if ($red === false) {
            self::fail('GD could not allocate a colour.');
        }
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);

        return $image;
    }

    /**
     * An image with enough detail for the quality of a JPEG to change its bytes.
     */
    private static function noisyImage(): \GdImage
    {
        $image = imagecreatetruecolor(64, 64);
        if ($image === false) {
            self::fail('GD could not create an image.');
        }

        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                imagesetpixel($image, $x, $y, (($x * 41) % 256) << 16 | (($y * 67) % 256) << 8 | (($x * $y) % 256));
            }
        }

        return $image;
    }

    /**
     * What a GD encoder writes to the output.
     *
     * @param \Closure(): mixed $encode
     */
    private static function written(\Closure $encode): string
    {
        ob_start();
        try {
            $encode();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'PNG' => ['png', 'image/png', 'image/png'];
        yield 'JPEG' => ['jpeg', 'image/jpeg', 'image/jpeg'];
        yield 'GIF, which is sent as binary data' => ['gif', 'image/gif', 'application/octet-stream'];
        yield 'BMP, which is sent as binary data' => ['bmp', 'image/bmp', 'application/octet-stream'];
    }

    #[Test]
    #[DataProvider('formatProvider')]
    public function encodesTheImageInTheFormatAsked(string $format, string $mime, string $contentType): void
    {
        $resolved = Image::fromGd(self::redImage(), $format)->resolve();

        $size = getimagesizefromstring($resolved->bytes);
        self::assertIsArray($size);
        self::assertSame([4, 4, $mime], [$size[0], $size[1], $size['mime']]);
        self::assertSame($contentType, $resolved->contentType);
    }

    #[Test]
    public function encodesAJpegByDefaultWithQualityNinetyFive(): void
    {
        $gd = self::noisyImage();
        $expected = self::written(static fn () => imagejpeg($gd, null, 95));
        $defaultQuality = self::written(static fn () => imagejpeg($gd));

        $bytes = Image::fromGd($gd)->resolve()->bytes;

        self::assertSame($expected, $bytes);
        self::assertNotSame($defaultQuality, $bytes);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function formatNameProvider(): iterable
    {
        foreach (['png', 'jpeg', 'gif', 'bmp'] as $format) {
            yield $format => [$format];
        }
    }

    #[Test]
    #[DataProvider('formatNameProvider')]
    public function theFormatNameCanBeInAnyCase(string $format): void
    {
        $lower = Image::fromGd(self::redImage(), $format)->resolve()->bytes;

        self::assertSame($lower, Image::fromGd(self::redImage(), strtoupper($format))->resolve()->bytes);
    }

    #[Test]
    public function encodesWebpWhenGdCanAndSaysSoWhenItCannot(): void
    {
        if (function_exists('imagewebp')) {
            $size = getimagesizefromstring(Image::fromGd(self::redImage(), 'webp')->resolve()->bytes);
            self::assertIsArray($size);
            self::assertSame('image/webp', $size['mime']);

            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'GD image could not be encoded as webp: this build of PHP has no GD encoder for it.',
        );

        Image::fromGd(self::redImage(), 'webp');
    }

    #[Test]
    public function encodesAvifWhenGdCanAndSaysSoWhenItCannot(): void
    {
        if (function_exists('imageavif')) {
            $size = getimagesizefromstring(Image::fromGd(self::redImage(), 'avif')->resolve()->bytes);
            self::assertIsArray($size);
            self::assertSame('image/avif', $size['mime']);

            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'GD image could not be encoded as avif: this build of PHP has no GD encoder for it.',
        );

        Image::fromGd(self::redImage(), 'avif');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unknownFormatProvider(): iterable
    {
        yield 'a name nobody has' => ['nope'];
        yield 'jpg, which is not the name of the format' => ['jpg'];
        yield 'an empty name' => [''];
        yield 'a format GD cannot write' => ['tiff'];
    }

    #[Test]
    #[DataProvider('unknownFormatProvider')]
    public function rejectsAFormatItCannotEncode(string $format): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "GD image could not be encoded as {$format}: the format must be jpeg, png, gif, webp, bmp or avif.",
        );

        Image::fromGd(self::redImage(), $format);
    }

    #[Test]
    public function dropsTheTransparencyOfAJpeg(): void
    {
        $gd = imagecreatetruecolor(8, 8);
        self::assertInstanceOf(\GdImage::class, $gd);
        imagealphablending($gd, false);
        imagesavealpha($gd, true);
        $transparentRed = imagecolorallocatealpha($gd, 255, 0, 0, 127);
        self::assertIsInt($transparentRed);
        imagefill($gd, 0, 0, $transparentRed);

        $png = imagecreatefromstring(Image::fromGd($gd, 'png')->resolve()->bytes);
        $jpeg = imagecreatefromstring(Image::fromGd($gd, 'jpeg')->resolve()->bytes);

        self::assertInstanceOf(\GdImage::class, $png);
        self::assertInstanceOf(\GdImage::class, $jpeg);
        // PNG keeps the transparency (127 is fully transparent); JPEG has none, so the pixel is opaque.
        self::assertSame(127, (imagecolorat($png, 0, 0) >> 24) & 0x7F);
        self::assertSame(0, (imagecolorat($jpeg, 0, 0) >> 24) & 0x7F);
    }

    #[Test]
    public function leavesOutputBufferingAndOtherOutputAsItWas(): void
    {
        $level = ob_get_level();

        ob_start();
        echo 'output of the caller';
        try {
            Image::fromGd(self::redImage(), 'png');
            try {
                Image::fromGd(self::redImage(), 'nope');
            } catch (\InvalidArgumentException) {
                // Expected.
            }
            $leftInTheCallersBuffer = ob_get_contents();
            self::assertSame($level + 1, ob_get_level());
        } finally {
            ob_end_clean();
        }

        self::assertSame('output of the caller', $leftInTheCallersBuffer);
        self::assertSame($level, ob_get_level());
    }

    #[Test]
    public function leavesTheErrorHandlerAsItWas(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            Image::fromGd(self::redImage(), 'png');
            try {
                Image::fromGd(self::redImage(), 'nope');
            } catch (\InvalidArgumentException) {
                // Expected.
            }
            trigger_error('after the encoding', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        self::assertSame(['after the encoding'], $seen);
    }

    #[Test]
    public function aGdImageIsUploadedWithTheRequest(): void
    {
        $image = Image::fromGd(self::redImage(), 'png');
        $api = (new FakeApi())->answering();

        $api->ask('<photo>', ['<photo>' => $image]);

        $asset = $api->assets()['<photo>'] ?? null;
        self::assertIsArray($asset);
        self::assertSame('image/png', $asset['content_type']);
        self::assertSame($image->resolve()->bytes, $asset['data']);
        self::assertStringStartsWith("\x89PNG\r\n\x1A\n", $image->resolve()->bytes);
    }
}
