<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Testing;

use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TypesafeAi\Http\Multipart;
use TypesafeAi\Tests\Support\Parts;
use TypesafeAi\Tests\Support\Samples;
use TypesafeAi\Testing\RequestBody;

final class RequestBodyTest extends TestCase
{
    /**
     * @param list<array{name: string, contentType: string, content: string, filename: ?string}> $parts
     */
    private static function multipartRequest(array $parts): RequestInterface
    {
        [$contentType, $body] = Multipart::encode($parts);

        return new Request('POST', 'https://api.typesafe.ai/v1/systemone', ['Content-Type' => $contentType], $body);
    }

    private static function jsonRequest(string $json): RequestInterface
    {
        $headers = ['Content-Type' => 'application/json'];

        return new Request('POST', 'https://api.typesafe.ai/v1/systemone', $headers, $json);
    }

    #[Test]
    public function splitsAMultipartBodyIntoItsPartsByName(): void
    {
        $request = self::multipartRequest([
            Parts::part('request', 'application/json', '{"a":1}'),
            Parts::part('asset-0', 'image/png', Samples::PNG, 'asset-0'),
        ]);

        $parts = RequestBody::parts($request);

        self::assertSame(['request', 'asset-0'], array_keys($parts));
        self::assertSame(
            [
                'headers' => "Content-Disposition: form-data; name=\"request\"\r\nContent-Type: application/json",
                'content' => '{"a":1}',
            ],
            $parts['request'],
        );
        self::assertSame(
            [
                'headers' => "Content-Disposition: form-data; name=\"asset-0\"; filename=\"asset-0\"\r\n"
                    . 'Content-Type: image/png',
                'content' => Samples::PNG,
            ],
            $parts['asset-0'],
        );
    }

    #[Test]
    public function keepsContentThatLooksLikeFramingIntact(): void
    {
        // Binary content may contain blank lines and end in a line break of its own.
        $content = "head\r\n\r\ntail\r\n--almost\r\n\r\n";
        $request = self::multipartRequest([
            Parts::part('asset-0', 'application/octet-stream', $content, 'asset-0'),
            Parts::part('asset-1', 'application/octet-stream', '', 'asset-1'),
        ]);

        $parts = RequestBody::parts($request);

        self::assertSame($content, $parts['asset-0']['content']);
        self::assertSame('', $parts['asset-1']['content']);
    }

    #[Test]
    public function decodesAPlainJsonBody(): void
    {
        $request = self::jsonRequest('{"state":"text","questions":{"q":{"type":"noul"}}}');

        self::assertSame(
            ['state' => 'text', 'questions' => ['q' => ['type' => 'noul']]],
            RequestBody::decode($request),
        );
    }

    #[Test]
    public function decodesAMultipartBodyByReplacingEachAssetsPartWithItsDataAndContentType(): void
    {
        $json = '{"state":"<a> <b>","assets":{'
            . '"<a>":{"type":"image","part":"asset-0"},"<b>":{"type":"image","part":"asset-1"}}}';
        $request = self::multipartRequest([
            Parts::part('request', 'application/json', $json),
            Parts::part('asset-0', 'image/png', Samples::PNG, 'asset-0'),
            Parts::part('asset-1', 'application/octet-stream', Samples::GIF, 'asset-1'),
        ]);

        self::assertSame(
            [
                'state' => '<a> <b>',
                'assets' => [
                    '<a>' => ['type' => 'image', 'content_type' => 'image/png', 'data' => Samples::PNG],
                    '<b>' => ['type' => 'image', 'content_type' => 'application/octet-stream', 'data' => Samples::GIF],
                ],
            ],
            RequestBody::decode($request),
        );
    }

    #[Test]
    public function decodesAMultipartBodyWithoutAssetsAsItsRequestPart(): void
    {
        $request = self::multipartRequest([Parts::part('request', 'application/json', '{"state":"text"}')]);

        self::assertSame(['state' => 'text'], RequestBody::decode($request));
    }

    /**
     * @return iterable<string, array{0: RequestInterface, 1: string}>
     */
    public static function malformedRequestProvider(): iterable
    {
        $boundary = 'multipart/form-data; boundary=BOUNDARY';
        $multipart = static function (string $body, string $type = '') use ($boundary): RequestInterface {
            $headers = ['Content-Type' => $type === '' ? $boundary : $type];

            return new Request('POST', 'https://api.typesafe.ai/v1/systemone', $headers, $body);
        };

        yield 'a JSON request' => [self::jsonRequest('{}'), 'The request is not a multipart/form-data request'];
        yield 'a multipart request without a boundary' => [
            $multipart('', 'multipart/form-data'),
            'The request is not a multipart/form-data request',
        ];
        yield 'a body that does not start with the boundary' => [
            $multipart("garbage--BOUNDARY\r\n\r\n--BOUNDARY--\r\n"),
            'The multipart body is not framed by its boundary.',
        ];
        yield 'a body without the closing delimiter' => [
            $multipart("--BOUNDARY\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n"),
            'The multipart body is not framed by its boundary.',
        ];
        yield 'a part without headers separated by a blank line' => [
            $multipart("--BOUNDARY\r\nContent-Disposition: form-data; name=\"a\"\r\n--BOUNDARY--\r\n"),
            'A multipart part has no blank line after its headers.',
        ];
        yield 'a part that is not delimited by line breaks' => [
            $multipart("--BOUNDARYContent-Disposition: form-data; name=\"a\"\r\n\r\nx--BOUNDARY--\r\n"),
            'A multipart part is not delimited by line breaks.',
        ];
        yield 'a part without a name' => [
            $multipart("--BOUNDARY\r\nContent-Type: text/plain\r\n\r\nx\r\n--BOUNDARY--\r\n"),
            'A multipart part has no name.',
        ];
    }

    #[Test]
    #[DataProvider('malformedRequestProvider')]
    public function refusesAMultipartBodyThatIsNotFramedTheWayTheClientFramesIt(
        RequestInterface $request,
        string $message,
    ): void {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        RequestBody::parts($request);
    }

    #[Test]
    public function refusesInvalidJson(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The request body is not valid JSON.');

        RequestBody::decode(self::jsonRequest('{"state":'));
    }

    #[Test]
    public function refusesJsonThatIsNotAnObject(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The request body is not a JSON object.');

        RequestBody::decode(self::jsonRequest('"just a string"'));
    }

    #[Test]
    public function refusesAMultipartBodyWithoutARequestPart(): void
    {
        $request = self::multipartRequest([
            ['name' => 'asset-0', 'contentType' => 'image/png', 'content' => Samples::PNG, 'filename' => 'asset-0'],
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The multipart body has no "request" part.');

        RequestBody::decode($request);
    }

    #[Test]
    public function refusesAnAssetThatNamesAPartThatWasNotSent(): void
    {
        $request = self::multipartRequest([
            [
                'name' => 'request',
                'contentType' => 'application/json',
                'content' => '{"assets":{"<a>":{"type":"image","part":"asset-7"}}}',
                'filename' => null,
            ],
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The asset "<a>" names a part that was not sent.');

        RequestBody::decode($request);
    }
}
