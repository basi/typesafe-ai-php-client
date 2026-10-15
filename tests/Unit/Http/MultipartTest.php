<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Http\Multipart;
use TypesafeAi\Tests\Support\Parts;
use TypesafeAi\Tests\Support\Samples;

final class MultipartTest extends TestCase
{
    #[Test]
    public function encodesTheDocumentedLayoutExactly(): void
    {
        [$contentType, $body] = Multipart::encode([
            Parts::part('request', 'application/json', '{"a":1}'),
            Parts::part('asset-0', 'image/png', Samples::PNG, 'asset-0'),
            Parts::part('asset-1', 'image/jpeg', Samples::JPEG, 'asset-1'),
        ]);

        self::assertMatchesRegularExpression('~^multipart/form-data; boundary=[0-9a-f]{32}$~D', $contentType);
        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));

        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"request\"\r\n"
            . "Content-Type: application/json\r\n"
            . "\r\n"
            . "{\"a\":1}\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"asset-0\"; filename=\"asset-0\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\n"
            . Samples::PNG . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"asset-1\"; filename=\"asset-1\"\r\n"
            . "Content-Type: image/jpeg\r\n"
            . "\r\n"
            . Samples::JPEG . "\r\n"
            . "--{$boundary}--\r\n",
            $body,
        );
    }

    #[Test]
    public function writesBinaryContentAsItIs(): void
    {
        $content = "\x00\r\n\r\n--\xFF\xFE\r\n--not-the-boundary--\r\n";

        [, $body] = Multipart::encode([Parts::part('asset-0', 'application/octet-stream', $content, 'asset-0')]);

        self::assertStringContainsString("\r\n\r\n{$content}\r\n--", $body);
    }

    #[Test]
    public function writesTheSameContentOncePerPart(): void
    {
        [, $body] = Multipart::encode([
            Parts::part('asset-0', 'image/png', Samples::PNG, 'asset-0'),
            Parts::part('asset-1', 'image/png', Samples::PNG, 'asset-1'),
        ]);

        self::assertSame(2, substr_count($body, Samples::PNG));
    }

    #[Test]
    public function aBodyWithoutPartsIsOnlyTheClosingDelimiter(): void
    {
        [$contentType, $body] = Multipart::encode([]);

        $boundary = substr($contentType, strlen('multipart/form-data; boundary='));
        self::assertSame("--{$boundary}--\r\n", $body);
    }

    #[Test]
    public function everyEncodingGetsABoundaryOfItsOwn(): void
    {
        $parts = [Parts::part('request', 'application/json', '{}')];

        self::assertNotSame(Multipart::encode($parts)[0], Multipart::encode($parts)[0]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string}>
     */
    public static function unsafePartProvider(): iterable
    {
        yield 'a quote in the name' => ['a"b', 'image/png', 'name', null];
        yield 'a line break in the name' => ["a\r\nX-Injected: 1", 'image/png', 'name', null];
        yield 'a space in the name' => ['a b', 'image/png', 'name', null];
        yield 'an empty name' => ['', 'image/png', 'name', null];
        yield 'a quote in the file name' => ['asset-0', 'image/png', 'filename', 'a"b'];
        yield 'a line break in the file name' => ['asset-0', 'image/png', 'filename', "a\r\nX-Injected: 1"];
        yield 'a line break in the content type' => ['asset-0', "image/png\r\nX-Injected: 1", 'contentType', 'asset-0'];
        yield 'a parameter in the content type' => ['asset-0', 'image/png; charset=utf-8', 'contentType', 'asset-0'];
        yield 'an empty content type' => ['asset-0', '', 'contentType', 'asset-0'];
    }

    #[Test]
    #[DataProvider('unsafePartProvider')]
    public function refusesAHeaderValueThatCouldEndTheHeaderEarly(
        string $name,
        string $contentType,
        string $field,
        ?string $filename,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("The {$field} of a multipart part may only contain letters, digits and ._/+-.");

        Multipart::encode([Parts::part($name, $contentType, 'x', $filename)]);
    }
}
