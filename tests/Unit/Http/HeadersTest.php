<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Http\Headers;

final class HeadersTest extends TestCase
{
    #[Test]
    public function returnsValidHeadersUnchanged(): void
    {
        $headers = [
            'X-Request-Source' => 'nightly job',
            'x-tenant' => '42',
            'X-Empty' => '',
            'X-Tab' => "a\tb",
            'X-Latin1' => "caf\xE9",
            'X-Last-Byte' => "a\xFE",
        ];

        self::assertSame($headers, Headers::validate($headers, 'headers'));
    }

    #[Test]
    public function acceptsNoHeaders(): void
    {
        self::assertSame([], Headers::validate([], 'headers'));
    }

    #[Test]
    public function acceptsTheSameNameInDifferentCases(): void
    {
        // The caller that merges header maps lets the later one win; validation does not.
        $headers = ['X-Team' => 'a', 'x-team' => 'b'];

        self::assertSame($headers, Headers::validate($headers, 'headers'));
    }

    /**
     * @return iterable<string, array{0: array<array-key, mixed>}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'empty name' => [['' => 'v']];
        yield 'name with a space' => [['X Bad' => 'v']];
        yield 'name with a colon' => [['X-Bad:' => 'v']];
        yield 'name with a line break' => [["X-Bad\r\nX-Injected" => 'v']];
        yield 'name with a NUL byte' => [["X-Bad\0" => 'v']];
        yield 'non-ASCII name' => [['X-Café' => 'v']];
        yield 'list of Name: value strings' => [['Authorization: Bearer x']];
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    #[Test]
    #[DataProvider('invalidNameProvider')]
    public function rejectsAnInvalidName(array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^headers keys must be valid HTTP header names, got /');

        Headers::validate($headers, 'headers');
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'line feed' => ["a\nb"];
        yield 'carriage return' => ["a\rb"];
        yield 'CRLF followed by another header' => ["a\r\nX-Injected: b"];
        yield 'NUL byte' => ["a\0b"];
        yield 'DEL' => ["a\x7Fb"];
        yield 'vertical tab' => ["a\x0Bb"];
        yield 'the byte 0xFF, which one PSR-7 implementation rejects' => ["a\xFFb"];
        yield 'integer' => [123];
        yield 'null' => [null];
        yield 'list of strings' => [['a', 'b']];
    }

    #[Test]
    #[DataProvider('invalidValueProvider')]
    public function rejectsAnInvalidValueWithoutEchoingIt(mixed $value): void
    {
        try {
            Headers::validate(['X-Secret-Ish' => $value], 'headers');
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'headers values must be strings without control characters, but the value of "X-Secret-Ish" is not.',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function theLabelNamesTheArgumentInTheMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^extraHeaders keys must be /');

        Headers::validate(['bad name' => 'v'], 'extraHeaders');
    }

    #[Test]
    public function aControlCharacterInANameIsEscapedInTheMessage(): void
    {
        try {
            Headers::validate(["X-Bad\r\nX-Injected" => 'v'], 'headers');
            self::fail('Expected an exception.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString("\r", $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertStringContainsString('X-Bad\r\nX-Injected', $exception->getMessage());
        }
    }
}
