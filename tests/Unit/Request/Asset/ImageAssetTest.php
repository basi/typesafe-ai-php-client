<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request\Asset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Asset\ImageAsset;

final class ImageAssetTest extends TestCase
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const string JPEG_SIGNATURE = "\xFF\xD8\xFF";

    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imageasset');
        self::assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function supportedFormatProvider(): iterable
    {
        yield 'png' => ['png'];
        yield 'jpg' => ['jpg'];
        yield 'jpeg' => ['jpeg'];
    }

    #[Test]
    #[DataProvider('supportedFormatProvider')]
    public function acceptsEachSupportedFormat(string $format): void
    {
        $asset = new ImageAsset($format, 'QUJD');

        self::assertSame($format, $asset->format());
        self::assertSame('QUJD', $asset->dataBase64());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsupportedFormatProvider(): iterable
    {
        yield 'uppercase PNG' => ['PNG'];
        yield 'uppercase JPG' => ['JPG'];
        yield 'uppercase JPEG' => ['JPEG'];
        yield 'mixed case' => ['Png'];
        yield 'gif' => ['gif'];
        yield 'webp' => ['webp'];
        yield 'media type' => ['image/png'];
        yield 'leading dot' => ['.png'];
        yield 'leading space' => [' png'];
        yield 'trailing space' => ['png '];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('unsupportedFormatProvider')]
    public function rejectsAnUnsupportedFormat(string $format): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('format must be one of');

        new ImageAsset($format, 'QUJD');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validBase64Provider(): iterable
    {
        yield 'no padding' => ['QUJD'];
        yield 'one padding character' => ['QUI='];
        yield 'two padding characters' => ['QQ=='];
        yield 'both special characters of the alphabet' => ['+/+/'];
        yield 'several blocks' => [base64_encode(str_repeat("\x00\xFF\x10", 100) . 'ab')];
    }

    #[Test]
    #[DataProvider('validBase64Provider')]
    public function acceptsStandardBase64WithPadding(string $dataBase64): void
    {
        self::assertSame($dataBase64, (new ImageAsset('png', $dataBase64))->dataBase64());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidBase64Provider(): iterable
    {
        $alphabet = 'standard base64 alphabet';
        $multipleOfFour = 'multiple of 4';

        yield 'empty' => ['', 'must not be empty'];
        yield 'data URI prefix' => ['data:image/png;base64,QUJD', 'without a "data:" URI prefix'];
        yield 'space inside' => ['QU JD', $alphabet];
        yield 'leading space' => [' QUJD', $alphabet];
        yield 'trailing space' => ['QUJD ', $alphabet];
        yield 'newline inside' => ["QUJD\nQUJD", $alphabet];
        yield 'trailing newline' => ["QUJD\n", $alphabet];
        yield 'line wrapped like MIME' => ["QUJDQUJD\r\nQUJDQUJD", $alphabet];
        yield 'tab' => ["QUJD\tQUJD", $alphabet];
        yield 'NUL byte' => ["QUJD\0", $alphabet];
        yield 'URL-safe alphabet' => ['-_-_', $alphabet];
        yield 'character outside the alphabet' => ['QUJD!', $alphabet];
        yield 'non-ASCII character' => ["QUJ\u{00E9}", $alphabet];
        yield 'padding in the middle' => ['QQ==QUJD', $alphabet];
        yield 'padding before data' => ['=QUJ', $alphabet];
        yield 'three padding characters' => ['Q===', $alphabet];
        yield 'only padding' => ['====', $alphabet];
        yield 'missing padding' => ['QUI', $multipleOfFour];
        yield 'one padding character missing' => ['QQ=', $multipleOfFour];
        yield 'both padding characters missing' => ['QQ', $multipleOfFour];
        yield 'a single character' => ['Q', $multipleOfFour];
        yield 'too much padding' => ['QUJD==', $multipleOfFour];
    }

    #[Test]
    #[DataProvider('invalidBase64Provider')]
    public function rejectsMalformedBase64(string $dataBase64, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        new ImageAsset('png', $dataBase64);
    }

    #[Test]
    public function doesNotEchoTheRejectedPayloadInTheExceptionMessage(): void
    {
        $payload = str_repeat('QUJD', 1000) . '!';

        try {
            new ImageAsset('png', $payload);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('QUJDQUJD', $exception->getMessage());
        }
    }

    #[Test]
    public function fromBinaryEncodesTheBytesAndDetectsPng(): void
    {
        $bytes = self::PNG_SIGNATURE . "\x00\x00\x00\rIHDR";

        $asset = ImageAsset::fromBinary($bytes);

        self::assertSame('png', $asset->format());
        self::assertSame(base64_encode($bytes), $asset->dataBase64());
    }

    #[Test]
    public function fromBinaryDetectsJpegAsJpg(): void
    {
        $bytes = self::JPEG_SIGNATURE . "\xE0\x00\x10JFIF\x00";

        $asset = ImageAsset::fromBinary($bytes);

        self::assertSame('jpg', $asset->format());
        self::assertSame(base64_encode($bytes), $asset->dataBase64());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function undetectableDataProvider(): iterable
    {
        yield 'GIF' => ['GIF89a' . "\x01\x00\x01\x00"];
        yield 'WebP' => ['RIFF' . "\x00\x00\x00\x00" . 'WEBPVP8 '];
        yield 'PDF' => ["%PDF-1.7\n"];
        yield 'plain text' => ['not an image'];
        yield 'truncated PNG signature' => ["\x89PNG\r\n\x1a"];
        yield 'truncated JPEG signature' => ["\xFF\xD8"];
        yield 'a single byte' => ["\x89"];
    }

    #[Test]
    #[DataProvider('undetectableDataProvider')]
    public function fromBinaryRejectsDataThatIsNeitherPngNorJpeg(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be detected');

        ImageAsset::fromBinary($bytes);
    }

    #[Test]
    public function fromBinaryRejectsEmptyInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('image data must not be empty');

        ImageAsset::fromBinary('');
    }

    #[Test]
    public function fromBinaryRejectsEmptyInputEvenWithAnExplicitFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('image data must not be empty');

        ImageAsset::fromBinary('', 'png');
    }

    #[Test]
    public function fromBinaryUsesAnExplicitFormatWithoutDetecting(): void
    {
        self::assertSame('png', ImageAsset::fromBinary('not an image', 'png')->format());
        // An explicit format wins even when the signature says something else: "jpeg" rather than
        // the "jpg" that detection would report for these bytes.
        self::assertSame('jpeg', ImageAsset::fromBinary(self::JPEG_SIGNATURE . "\xE0", 'jpeg')->format());
    }

    #[Test]
    public function fromBinaryRejectsAnInvalidExplicitFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('format must be one of');

        ImageAsset::fromBinary(self::PNG_SIGNATURE . 'data', 'PNG');
    }

    #[Test]
    public function fromBinaryKeepsEveryByteValueIntact(): void
    {
        $bytes = self::PNG_SIGNATURE;
        for ($byte = 0; $byte < 256; $byte++) {
            $bytes .= chr($byte);
        }

        self::assertSame($bytes, base64_decode(ImageAsset::fromBinary($bytes)->dataBase64(), true));
    }

    #[Test]
    public function fromFileReadsTheFileAndDetectsItsFormat(): void
    {
        $bytes = self::PNG_SIGNATURE . 'file contents';
        $path = $this->temporaryFile($bytes);

        $asset = ImageAsset::fromFile($path);

        self::assertSame('png', $asset->format());
        self::assertSame(base64_encode($bytes), $asset->dataBase64());
    }

    #[Test]
    public function fromFileUsesAnExplicitFormat(): void
    {
        $path = $this->temporaryFile(self::JPEG_SIGNATURE . "\xE0 file contents");

        self::assertSame('jpeg', ImageAsset::fromFile($path, 'jpeg')->format());
    }

    #[Test]
    public function fromFileRejectsAMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        ImageAsset::fromFile(sys_get_temp_dir() . '/typesafe-ai-missing-' . bin2hex(random_bytes(8)) . '.png');
    }

    #[Test]
    public function fromFileRejectsADirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        ImageAsset::fromFile(sys_get_temp_dir());
    }

    #[Test]
    public function fromFileRejectsAnEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        ImageAsset::fromFile('');
    }

    #[Test]
    public function fromFileRejectsAnUnreadableFile(): void
    {
        $path = $this->temporaryFile(self::PNG_SIGNATURE . 'file contents');
        chmod($path, 0000);
        clearstatcache();
        if (is_readable($path)) {
            self::markTestSkipped('This user can read a file with mode 0000 (for example root).');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        ImageAsset::fromFile($path);
    }

    #[Test]
    public function fromFileRejectsAnEmptyFile(): void
    {
        $path = $this->temporaryFile('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('image data must not be empty');

        ImageAsset::fromFile($path);
    }

    #[Test]
    public function encodesAsAnImageAssetObject(): void
    {
        $asset = new ImageAsset('jpeg', 'QUJD');

        self::assertSame(
            ['type' => 'image', 'format' => 'jpeg', 'data_base64' => 'QUJD'],
            $asset->jsonSerialize(),
        );
        self::assertSame(
            '{"type":"image","format":"jpeg","data_base64":"QUJD"}',
            json_encode($asset, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function debugOutputShowsTheFormatAndDecodedSizeInsteadOfThePayload(): void
    {
        $bytes = self::PNG_SIGNATURE . str_repeat('x', 3000);
        $asset = ImageAsset::fromBinary($bytes);
        $payloadFragment = substr($asset->dataBase64(), 100, 40);

        $printed = print_r($asset, true);
        ob_start();
        var_dump($asset);
        $dumped = (string) ob_get_clean();

        foreach ([$printed, $dumped] as $output) {
            self::assertStringContainsString('png', $output);
            self::assertStringContainsString((string) strlen($bytes), $output);
            self::assertStringNotContainsString($payloadFragment, $output);
        }
        self::assertStringNotContainsString($payloadFragment, print_r(['assets' => [$asset]], true));
    }
}
