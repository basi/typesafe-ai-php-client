<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\AssetException;
use TypesafeAi\Request\Image;
use TypesafeAi\Tests\Support\RecordingStreamWrapper;
use TypesafeAi\Tests\Support\Samples;
use TypesafeAi\Tests\Support\UsesTemporaryDirectory;

final class ImageTest extends TestCase
{
    use UsesTemporaryDirectory;

    private const string REFERENCE_PATTERN = '/^<image:[0-9a-f]{32}>$/D';

    #[Test]
    public function aReferenceIsAUuidInAngleBrackets(): void
    {
        self::assertMatchesRegularExpression(self::REFERENCE_PATTERN, (string) Image::fromBytes(Samples::PNG));
    }

    #[Test]
    public function theReferenceIsTheStringFormAndTheJsonFormOfAnImage(): void
    {
        $image = Image::fromBytes(Samples::PNG);

        self::assertSame((string) $image, "{$image}");
        // Outside a request that is being sent, an image encodes as its reference.
        self::assertSame(json_encode((string) $image), json_encode($image));
        self::assertSame((string) $image, $image->jsonSerialize());
    }

    #[Test]
    public function twoImagesMadeFromTheSameBytesAreTwoImages(): void
    {
        $first = Image::fromBytes(Samples::PNG);
        $second = Image::fromBytes(Samples::PNG);

        self::assertNotSame($first, $second);
        self::assertNotEquals($first, $second);
        self::assertNotSame((string) $first, (string) $second);
    }

    #[Test]
    public function aCloneKeepsTheReferenceOfItsOriginal(): void
    {
        $image = Image::fromBytes(Samples::PNG);
        $copy = clone $image;

        self::assertNotSame($image, $copy);
        self::assertSame((string) $image, (string) $copy);
    }

    /**
     * @return iterable<string, array{0: \Closure(self): Image}>
     */
    public static function imageProvider(): iterable
    {
        yield 'bytes' => [static fn (): Image => Image::fromBytes(Samples::PNG)];
        yield 'base64' => [static fn (): Image => Image::fromBase64(base64_encode(Samples::PNG))];
        yield 'path' => [
            static fn (self $test): Image => Image::fromPath($test->temporaryFile('shown.png', Samples::PNG)),
        ];
    }

    /**
     * @param \Closure(self): Image $make
     */
    #[Test]
    #[DataProvider('imageProvider')]
    public function debugOutputNeverShowsTheReferenceOrTheBytes(\Closure $make): void
    {
        $image = $make($this);
        $image->resolve();

        $printed = print_r($image, true);
        ob_start();
        try {
            var_dump($image);
        } finally {
            $dumped = (string) ob_get_clean();
        }

        foreach ([$printed, $dumped] as $output) {
            self::assertStringNotContainsString('<image:', $output);
            self::assertStringNotContainsString(substr((string) $image, 7, 32), $output);
            self::assertStringNotContainsString('image data', $output);
            self::assertStringContainsString(Image::class, $output);
        }
    }

    #[Test]
    public function debugOutputShowsTheSourceAndTheSize(): void
    {
        $fromBytes = Image::fromBytes(Samples::PNG);
        $path = $this->temporaryFile('size.png', Samples::JPEG);
        $fromPath = Image::fromPath($path);

        self::assertSame(
            ['source' => 'bytes', 'path' => null, 'bytes' => strlen(Samples::PNG)],
            $fromBytes->__debugInfo(),
        );
        self::assertSame(['source' => 'path', 'path' => $path, 'bytes' => null], $fromPath->__debugInfo());

        $fromPath->resolve();

        self::assertSame(
            ['source' => 'path', 'path' => $path, 'bytes' => strlen(Samples::JPEG)],
            $fromPath->__debugInfo(),
        );
    }

    #[Test]
    public function fromBytesRejectsEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image data is empty.');

        Image::fromBytes('');
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function contentTypeProvider(): iterable
    {
        yield 'PNG' => [Samples::PNG, 'image/png'];
        yield 'JPEG' => [Samples::JPEG, 'image/jpeg'];
        yield 'GIF' => [Samples::GIF, 'application/octet-stream'];
        yield 'a PNG signature that is cut short' => ["\x89PNG\r\n\x1A", 'application/octet-stream'];
        yield 'a JPEG signature that is cut short' => ["\xFF\xD8", 'application/octet-stream'];
        yield 'text' => ['not an image', 'application/octet-stream'];
        yield 'a single byte' => ['x', 'application/octet-stream'];
    }

    #[Test]
    #[DataProvider('contentTypeProvider')]
    public function theUploadIsLabelledByTheFileSignature(string $bytes, string $contentType): void
    {
        $resolved = Image::fromBytes($bytes)->resolve();

        self::assertSame($contentType, $resolved->contentType);
        self::assertSame($bytes, $resolved->bytes);
    }

    #[Test]
    public function anImageIsResolvedOnce(): void
    {
        $image = Image::fromBytes(Samples::PNG);

        self::assertSame($image->resolve(), $image->resolve());
    }

    #[Test]
    public function fromPathDoesNotReadTheFileYet(): void
    {
        $path = $this->temporaryPath('does-not-exist-yet.png');

        $image = Image::fromPath($path);

        self::assertSame(['source' => 'path', 'path' => $path, 'bytes' => null], $image->__debugInfo());
    }

    #[Test]
    public function fromPathAcceptsASplFileInfo(): void
    {
        $path = $this->temporaryFile('info.png', Samples::PNG);

        $image = Image::fromPath(new \SplFileInfo($path));

        self::assertSame(Samples::PNG, $image->resolve()->bytes);
    }

    /**
     * @return iterable<string, array{0: string, 1: non-empty-string}>
     */
    public static function unacceptablePathProvider(): iterable
    {
        $wrapper = 'Image path must be a path on the local filesystem, not a stream wrapper URL.';
        $wrappers = [
            'php://memory',
            'php://filter/read=convert.base64-encode/resource=/etc/hostname',
            'file:///etc/hostname',
            'http://example.test/photo.png',
            'https://example.test/photo.png',
            'phar://archive.phar/photo.png',
            'data://text/plain;base64,aGVsbG8=',
            'compress.zlib://photo.png.gz',
            'glob://*.png',
            'HTTP://example.test/photo.png',
            'data:image/png;base64,aGVsbG8=',
            'DATA:text/plain,hello',
            // PHP accepts a digit or a sign at the start of a scheme, and any scheme of two or more characters.
            '1photos://photo.png',
            '+x://photo.png',
            'x-1.y://photo.png',
            'zz://photo.png',
        ];

        yield 'empty' => ['', 'Image path must not be empty.'];
        yield 'NUL byte' => ["photo\0.png", 'Image path must not contain a NUL byte.'];
        foreach ($wrappers as $path) {
            yield $path => [$path, $wrapper];
        }
    }

    /**
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('unacceptablePathProvider')]
    public function fromPathRejectsWhatIsNotAPlainPath(string $path, string $message): void
    {
        try {
            Image::fromPath($path);
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringStartsWith($message, $exception->getMessage());
        }
    }

    #[Test]
    #[DataProvider('plainPathProvider')]
    public function fromPathAcceptsPathsThatOnlyLookLikeWrappers(string $path): void
    {
        self::assertSame($path, Image::fromPath($path)->__debugInfo()['path']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function plainPathProvider(): iterable
    {
        yield 'relative' => ['images/photo.png'];
        yield 'absolute' => ['/var/images/photo.png'];
        yield 'with a colon in the name' => ['photo:1.png'];
        yield 'Windows drive path' => ['C:\\images\\photo.png'];
        yield 'Windows drive path with slashes' => ['C:/images/photo.png'];
        yield 'a directory called data' => ['data/photo.png'];
        yield 'a wrapper-like segment later in the path' => ['/tmp/php://memory'];
        yield 'a drive letter followed by two slashes' => ['C://images/photo.png'];
        yield 'a lower-case drive letter followed by two slashes' => ['c://photo.png'];
        yield 'a dot slash before a scheme-like name' => ['./ab://photo.png'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wrapperNameProvider(): iterable
    {
        yield 'a name that starts with a digit' => ['1photos'];
        yield 'a name that starts with a plus' => ['+x'];
        yield 'letters, digits and signs' => ['x-1.y'];
        yield 'two letters' => ['zz'];
    }

    #[Test]
    #[DataProvider('wrapperNameProvider')]
    public function aStreamWrapperThatPhpWouldResolveIsRejectedWhateverItsName(string $scheme): void
    {
        RecordingStreamWrapper::$opened = [];
        self::assertTrue(stream_wrapper_register($scheme, RecordingStreamWrapper::class));

        try {
            $path = "{$scheme}://photo.png";

            // PHP itself reads such a path through the wrapper, not as a file ...
            self::silently(static fn (): mixed => file_get_contents($path));
            self::assertSame([$path], RecordingStreamWrapper::$opened);

            // ... so it must never be accepted as the path of a file.
            try {
                Image::fromPath($path);
                self::fail('Expected an exception.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith(
                    'Image path must be a path on the local filesystem',
                    $exception->getMessage(),
                );
            }
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    #[Test]
    public function aSingleLetterBeforeTwoSlashesIsADriveAndNotAStreamWrapper(): void
    {
        RecordingStreamWrapper::$opened = [];
        self::assertTrue(stream_wrapper_register('C', RecordingStreamWrapper::class));

        try {
            // PHP only takes a scheme of two or more characters for a wrapper, so that a drive
            // letter is never mistaken for one, and reads "C://photo.png" as a path. So does the client.
            $image = Image::fromPath('C://photo.png');

            try {
                $image->resolve();
                self::fail('Expected an exception.');
            } catch (AssetException $exception) {
                self::assertStringStartsWith(
                    "Image file 'C://photo.png' could not be read: ",
                    $exception->getMessage(),
                );
            }
            self::assertSame([], RecordingStreamWrapper::$opened);
        } finally {
            stream_wrapper_unregister('C');
        }
    }

    /**
     * Runs $callback with its warnings muted.
     *
     * @param \Closure(): mixed $callback
     */
    private static function silently(\Closure $callback): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $callback();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fileProvider(): iterable
    {
        yield 'PNG' => [Samples::PNG, 'image/png'];
        yield 'JPEG' => [Samples::JPEG, 'image/jpeg'];
        yield 'other' => [Samples::GIF, 'application/octet-stream'];
    }

    #[Test]
    #[DataProvider('fileProvider')]
    public function aPathIsReadWhenItIsResolved(string $bytes, string $contentType): void
    {
        $path = $this->temporaryPath('photo');
        $image = Image::fromPath($path);
        // The file is created after the Image: it is only read when it is needed.
        file_put_contents($path, $bytes);

        $resolved = $image->resolve();

        self::assertSame($bytes, $resolved->bytes);
        self::assertSame($contentType, $resolved->contentType);
    }

    #[Test]
    public function aRelativePathIsResolvedAgainstTheWorkingDirectoryOfThatMoment(): void
    {
        $this->temporaryFile('relative.png', Samples::PNG);
        $image = Image::fromPath('relative.png');
        $directory = getcwd();
        self::assertNotFalse($directory);

        try {
            chdir(dirname($this->temporaryPath('relative.png')));
            $bytes = $image->resolve()->bytes;
        } finally {
            chdir($directory);
        }

        self::assertSame(Samples::PNG, $bytes);
    }

    #[Test]
    public function aPathThatWasReadIsNotReadAgain(): void
    {
        $path = $this->temporaryFile('once.png', Samples::PNG);
        $image = Image::fromPath($path);
        $first = $image->resolve();

        file_put_contents($path, Samples::JPEG);
        $afterTheFileChanged = $image->resolve();
        unlink($path);
        $afterTheFileWasDeleted = $image->resolve();

        self::assertSame($first, $afterTheFileChanged);
        self::assertSame($first, $afterTheFileWasDeleted);
        self::assertSame(Samples::PNG, $afterTheFileWasDeleted->bytes);
        self::assertSame('image/png', $afterTheFileWasDeleted->contentType);
    }

    #[Test]
    public function aFailedReadIsNotRemembered(): void
    {
        $path = $this->temporaryPath('later.png');
        $image = Image::fromPath($path);

        try {
            $image->resolve();
            self::fail('Expected an exception.');
        } catch (AssetException) {
            // Expected: the file does not exist yet.
        }

        file_put_contents($path, Samples::PNG);

        self::assertSame(Samples::PNG, $image->resolve()->bytes);
    }

    #[Test]
    public function aMissingFileIsReportedWithTheReasonFromTheSystem(): void
    {
        $path = $this->temporaryPath('missing.png');

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("Image file '{$path}' could not be read: No such file or directory.");

        Image::fromPath($path)->resolve();
    }

    #[Test]
    public function aDirectoryIsReportedAsOne(): void
    {
        $path = dirname($this->temporaryFile('inside.png', Samples::PNG));

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("Image file '{$path}' could not be read: Is a directory.");

        Image::fromPath($path)->resolve();
    }

    #[Test]
    public function aFileThatCannotBeReadIsReportedWithTheReasonFromTheSystem(): void
    {
        $path = $this->temporaryFile('locked.png', Samples::PNG);
        chmod($path, 0000);
        if (is_readable($path)) {
            self::markTestSkipped('The file is still readable: the tests are running as root.');
        }

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage("Image file '{$path}' could not be read: Permission denied.");

        Image::fromPath($path)->resolve();
    }

    #[Test]
    public function anEmptyFileHasNoImageData(): void
    {
        $path = $this->temporaryFile('empty.png', '');

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('Image data is empty.');

        Image::fromPath($path)->resolve();
    }

    #[Test]
    #[RunInSeparateProcess]
    public function aPathThatOpenBasedirForbidsIsAnAssetExceptionNotAWarning(): void
    {
        // open_basedir can only be narrowed while a script runs, hence the separate process. The
        // repository and the temporary directory stay allowed so that classes can still be loaded.
        ini_set('open_basedir', dirname(__DIR__, 3) . PATH_SEPARATOR . sys_get_temp_dir());
        $image = Image::fromPath('/etc/hosts');

        // A warning that escaped would be reported by PHPUnit, or turned into an exception by an
        // application's error handler, instead of ending up here.
        $this->expectException(AssetException::class);
        $this->expectExceptionMessageMatches("~^Image file '/etc/hosts' could not be read: ~");

        $image->resolve();
    }

    #[Test]
    public function readingAFileKeepsItsWarningsAndLeavesTheErrorHandlerAsItWas(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            try {
                Image::fromPath($this->temporaryPath('missing.png'))->resolve();
                self::fail('Expected an exception.');
            } catch (AssetException) {
                // Expected: the warning about the missing file is the image's business, not ours.
            }

            trigger_error('after the read', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        self::assertSame(['after the read'], $seen);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function base64Provider(): iterable
    {
        $data = str_repeat(Samples::PNG, 20);
        $encoded = base64_encode($data);

        yield 'plain' => [$encoded, $data];
        yield 'wrapped at 76 characters with LF' => [chunk_split($encoded, 76, "\n"), $data];
        yield 'wrapped at 76 characters with CRLF' => [chunk_split($encoded, 76, "\r\n"), $data];
        yield 'ending in CRLF' => [$encoded . "\r\n", $data];
        yield 'with spaces and tabs' => [implode(" \t", str_split($encoded, 4)), $data];
        yield 'with surrounding whitespace' => ["  \n{$encoded}\n  ", $data];
        yield 'one byte' => ['YQ==', 'a'];
        yield 'two bytes' => ['YWI=', 'ab'];
        yield 'three bytes' => ['YWJj', 'abc'];
        yield 'the PNG signature' => ['iVBORw0KGgo=', "\x89PNG\r\n\x1A\n"];
    }

    #[Test]
    #[DataProvider('base64Provider')]
    public function fromBase64DecodesTextWithOrWithoutWhitespace(string $text, string $bytes): void
    {
        $image = Image::fromBase64($text);

        self::assertSame($bytes, $image->resolve()->bytes);
    }

    #[Test]
    public function fromBase64KeepsTheContentTypeOfTheDecodedFile(): void
    {
        self::assertSame('image/png', Image::fromBase64(base64_encode(Samples::PNG))->resolve()->contentType);
        self::assertSame('image/jpeg', Image::fromBase64(base64_encode(Samples::JPEG))->resolve()->contentType);
    }

    #[Test]
    public function fromBase64DecodesAMegabyteWithoutTrouble(): void
    {
        $bytes = random_bytes(1024 * 1024);

        self::assertSame($bytes, Image::fromBase64(chunk_split(base64_encode($bytes), 76, "\n"))->resolve()->bytes);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidBase64Provider(): iterable
    {
        $ascii = 'Image data must contain non-empty ASCII base64 text.';
        $valid = 'Image data must contain valid base64 text.';

        yield 'empty' => ['', $ascii];
        yield 'only whitespace' => [" \n", $ascii];
        yield 'a no-break space (a byte above 0x7F)' => ["iVBO\xA0Rw0K", $ascii];
        yield 'a non-ASCII character' => ['iVBORw0KGgoé', $ascii];
        yield 'raw image bytes' => [Samples::PNG, $ascii];
        yield 'garbage before valid text' => ['!!!!' . base64_encode(Samples::PNG), $valid];
        yield 'text cut short' => ['iVBORw0', $valid];
        yield 'padding missing' => ['YQ', $valid];
        yield 'one byte of padding too many' => ['YQ===', $valid];
        yield 'padding only' => ['====', $valid];
        yield 'padding in the middle' => ['AA=A', $valid];
        yield 'padding at the start' => ['=AAA', $valid];
        yield 'padding then more text' => ['YQ==YQ==', $valid];
        yield 'three characters of padding' => ['A===', $valid];
        yield 'a URL-safe alphabet' => ['ab-_', $valid];
        yield 'a data URI' => ['data:image/png;base64,iVBORw0KGgo=', $valid];
        yield 'a NUL byte' => ["AAAA\0", $valid];
        yield 'a length that is not a multiple of four' => ['AAAAA', $valid];
    }

    #[Test]
    #[DataProvider('invalidBase64Provider')]
    public function fromBase64RejectsTextThatIsNotValidBase64(string $text, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Image::fromBase64($text);
    }
}
