<?php

declare(strict_types=1);

namespace TypesafeAi\Testing;

use Psr\Http\Message\RequestInterface;

/**
 * Reads the body of a request that {@see \TypesafeAi\Client} sent through
 * {@see MockHttpClient}, for tests of code that sends images.
 *
 * A request with images is a `multipart/form-data` upload, not a JSON document, and its boundary
 * is random, so a test cannot compare its body with a fixed string. Mirrors the helpers of the
 * official SDK (0.7.5)'s own tests:
 *
 * ```php
 * $body = RequestBody::decode($mock->lastRequest());
 * $body['state'];                       // the state, with each image as its reference
 * $body['assets'][(string) $image];     // ['type' => 'image', 'content_type' => 'image/png', 'data' => '<bytes>']
 * ```
 */
final class RequestBody
{
    private function __construct()
    {
    }

    /**
     * The parts of a `multipart/form-data` request, by name, in the order they were sent.
     *
     * @return array<string, array{headers: string, content: string}> `headers` is the part's header
     *     block as sent (without the blank line that ends it), and `content` its body.
     *
     * @throws \UnexpectedValueException if the request is not a multipart request, or its body is
     *     not framed the way this client frames it.
     */
    public static function parts(RequestInterface $request): array
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (preg_match('~^multipart/form-data;\s*boundary=([^\s;]+)\s*$~i', $contentType, $match) !== 1) {
            throw new \UnexpectedValueException(sprintf(
                'The request is not a multipart/form-data request with a boundary (Content-Type: "%s").',
                $contentType,
            ));
        }

        $delimiter = '--' . $match[1];
        $chunks = explode($delimiter, (string) $request->getBody());

        // The body starts with the first delimiter and ends with the closing one, "--" and a line
        // break, which leaves an empty string before the first chunk and "--\r\n" after the last.
        if (array_shift($chunks) !== '' || array_pop($chunks) !== "--\r\n") {
            throw new \UnexpectedValueException('The multipart body is not framed by its boundary.');
        }

        $parts = [];
        foreach ($chunks as $chunk) {
            // A chunk is "\r\n<headers>\r\n\r\n<content>\r\n": cut the line break on each side, then
            // split at the first blank line. The content may hold anything, blank lines included.
            if (!str_starts_with($chunk, "\r\n") || !str_ends_with($chunk, "\r\n")) {
                throw new \UnexpectedValueException('A multipart part is not delimited by line breaks.');
            }
            $chunk = substr($chunk, 2, -2);

            $separator = strpos($chunk, "\r\n\r\n");
            if ($separator === false) {
                throw new \UnexpectedValueException('A multipart part has no blank line after its headers.');
            }
            $headers = substr($chunk, 0, $separator);

            if (preg_match('/(?:^|[;\s])name="([^"]+)"/', $headers, $name) !== 1) {
                throw new \UnexpectedValueException('A multipart part has no name.');
            }

            $parts[$name[1]] = ['headers' => $headers, 'content' => substr($chunk, $separator + 4)];
        }

        return $parts;
    }

    /**
     * The JSON body of a request, decoded. For a multipart request, that is the JSON in its
     * `request` part, with every entry of `assets` rewritten from naming its part to holding what
     * the part holds: `part` is replaced by `content_type` and `data`.
     *
     * @return array<array-key, mixed>
     *
     * @throws \UnexpectedValueException if the body is not JSON or not a well-formed multipart
     *     upload.
     */
    public static function decode(RequestInterface $request): array
    {
        if (stripos($request->getHeaderLine('Content-Type'), 'multipart/form-data') !== 0) {
            return self::json((string) $request->getBody());
        }

        $parts = self::parts($request);
        if (!isset($parts['request'])) {
            throw new \UnexpectedValueException('The multipart body has no "request" part.');
        }

        $body = self::json($parts['request']['content']);

        if (!isset($body['assets']) || !is_array($body['assets'])) {
            return $body;
        }

        $assets = [];
        foreach ($body['assets'] as $name => $entry) {
            if (!is_array($entry) || !is_string($entry['part'] ?? null) || !isset($parts[$entry['part']])) {
                throw new \UnexpectedValueException(sprintf('The asset "%s" names a part that was not sent.', $name));
            }

            $part = $parts[$entry['part']];
            unset($entry['part']);

            $contentType = preg_match('/^Content-Type: ([^\r\n]+)/mi', $part['headers'], $match) === 1
                ? $match[1]
                : null;
            $assets[$name] = [...$entry, 'content_type' => $contentType, 'data' => $part['content']];
        }
        $body['assets'] = $assets;

        return $body;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('The request body is not valid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('The request body is not a JSON object.');
        }

        return $decoded;
    }
}
