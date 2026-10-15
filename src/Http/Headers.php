<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

/**
 * Validates HTTP headers supplied by the caller — the client-wide
 * {@see \TypesafeAi\ClientOptions::$headers} and a request's
 * {@see \TypesafeAi\Request\SystemOneRequest::extraHeaders()} — when those objects are built.
 *
 * Doing it there matters: a PSR-7 message rejects a malformed header name or value with an
 * `\InvalidArgumentException` from `withHeader()`, which {@see Transport} calls outside the `try`
 * block that turns transport failures into `TypesafeAiException`s. Checking early means a bad
 * header fails at the call site that supplied it instead of in the middle of a request.
 *
 * @internal
 */
final class Headers
{
    /**
     * A header name is an HTTP token (RFC 9110, section 5.6.2).
     */
    private const string NAME_PATTERN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /**
     * A header value may hold spaces, tabs, visible ASCII characters and bytes above 0x7F, but no
     * line break or other control character (RFC 9110, section 5.5). Obsolete line folding is not
     * supported, like in the PSR-7 implementations. The byte 0xFF is left out although the RFC
     * allows it: a PSR-7 implementation (Laminas Diactoros) rejects it, and it never occurs in
     * valid UTF-8.
     */
    private const string VALUE_PATTERN = '/^[\x09\x20-\x7E\x80-\xFE]*$/D';

    private function __construct()
    {
    }

    /**
     * Returns $headers unchanged when every name and value is valid. Names are compared without
     * regard to case later on, so the same name given twice in different cases is not an error
     * here: the caller that merges header maps lets the later one win.
     *
     * The values are not echoed in a message, since a header often carries a credential.
     *
     * @param array<array-key, mixed> $headers Header name to header value.
     * @param string $label What the headers are called in an error message, e.g. `headers`.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException if a name is not an HTTP token (which also rejects a list
     *     of `Name: value` strings, whose keys are integers) or a value is not a string without
     *     control characters.
     */
    public static function validate(array $headers, string $label): array
    {
        $validated = [];

        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    '%s keys must be valid HTTP header names, got %s.',
                    $label,
                    self::describe($name),
                ));
            }

            if (!is_string($value) || preg_match(self::VALUE_PATTERN, $value) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    '%s values must be strings without control characters, but the value of %s is not.',
                    $label,
                    self::describe($name),
                ));
            }

            $validated[$name] = $value;
        }

        return $validated;
    }

    /**
     * Quotes a header name for an error message, escaping anything that would otherwise reach a
     * log as a raw line break or control character.
     */
    private static function describe(int|string $name): string
    {
        if (is_int($name)) {
            return (string) $name;
        }

        return json_encode($name, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            ?: '""';
    }
}
