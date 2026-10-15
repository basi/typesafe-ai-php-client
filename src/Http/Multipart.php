<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

use TypesafeAi\Uuid;

/**
 * Encodes a `multipart/form-data` request body (RFC 7578).
 *
 * Mirrors the official SDK (0.7.5)'s encoder. The boundary is a random UUID, like the one browsers
 * and HTTP libraries use, which is practically certain not to occur in the content, so the
 * content is not scanned for it.
 *
 * @internal
 */
final class Multipart
{
    /**
     * The characters allowed in a part's name, file name and content type. They are written into
     * the part's headers without escaping, so a quote or a line break would end the header early
     * or start another one.
     */
    private const string HEADER_VALUE_PATTERN = '/^[A-Za-z0-9._\/+-]+$/D';

    private function __construct()
    {
    }

    /**
     * @param list<array{name: string, contentType: string, content: string, filename: ?string}> $parts
     *     In order. `name` and `filename` (when not null) become the `name` and `filename`
     *     parameters of the part's `Content-Disposition`, and `contentType` its `Content-Type`.
     *     All three may only hold letters, digits and `._/+-`.
     *
     * @return array{string, string} The `Content-Type` header value, which carries the boundary,
     *     and the body.
     *
     * @throws \InvalidArgumentException if a part's name, file name or content type holds any
     *     other character.
     */
    public static function encode(array $parts): array
    {
        $boundary = Uuid::hex();
        $chunks = [];

        foreach ($parts as $part) {
            $disposition = sprintf('form-data; name="%s"', self::headerValue($part['name'], 'name'));
            if ($part['filename'] !== null) {
                $disposition .= sprintf('; filename="%s"', self::headerValue($part['filename'], 'filename'));
            }

            $chunks[] = sprintf(
                "--%s\r\nContent-Disposition: %s\r\nContent-Type: %s\r\n\r\n",
                $boundary,
                $disposition,
                self::headerValue($part['contentType'], 'contentType'),
            );
            $chunks[] = $part['content'];
            $chunks[] = "\r\n";
        }

        $chunks[] = sprintf("--%s--\r\n", $boundary);

        return ['multipart/form-data; boundary=' . $boundary, implode('', $chunks)];
    }

    private static function headerValue(string $value, string $field): string
    {
        if (preg_match(self::HEADER_VALUE_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of a multipart part may only contain letters, digits and ._/+-.',
                $field,
            ));
        }

        return $value;
    }
}
