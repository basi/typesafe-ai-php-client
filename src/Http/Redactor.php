<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

/**
 * Scrubs a typesafe.ai API key out of arbitrary text — for example an upstream exception message
 * that happens to echo back a request header, URL, or JSON body containing it.
 *
 * Four representations of the key are treated as sensitive and replaced with `***`:
 *
 *  - the raw key;
 *  - `Bearer <key>` as a single unit, scheme included (the word `Bearer` is matched
 *    case-insensitively, since HTTP treats header values that way; the key itself is matched
 *    exactly);
 *  - the JSON-escaped form of the key — the inner content of `json_encode($key)` — which differs
 *    from the raw key whenever it contains a `/`, `"`, or `\`;
 *  - the URL-encoded form of the key ({@see rawurlencode()}), which differs from the raw key
 *    whenever it contains a character outside the URL-safe set.
 *
 * `Bearer <key>` is scrubbed as a single unit before the raw key is scrubbed on its own, so the
 * result is `***` rather than `Bearer ***`.
 */
final class Redactor
{
    private const string MASK = '***';

    private readonly string $bearerPattern;

    /**
     * @var list<string> Exact substrings to replace with {@see self::MASK}, longest first so a
     *     shorter needle cannot leave a fragment of a longer one behind in the result.
     */
    private readonly array $needles;

    public function __construct(string $apiKey)
    {
        $this->bearerPattern = '/Bearer\s+' . preg_quote($apiKey, '/') . '/i';

        $needles = [$apiKey];

        $jsonEscaped = json_encode($apiKey, JSON_THROW_ON_ERROR);
        $jsonInner = substr($jsonEscaped, 1, -1);
        if ($jsonInner !== $apiKey) {
            $needles[] = $jsonInner;
        }

        $urlEncoded = rawurlencode($apiKey);
        if ($urlEncoded !== $apiKey) {
            $needles[] = $urlEncoded;
        }

        usort($needles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->needles = array_values(array_unique($needles));
    }

    /**
     * Returns $text with every representation of the configured key replaced by `***`.
     */
    public function redact(string $text): string
    {
        $text = preg_replace($this->bearerPattern, self::MASK, $text) ?? $text;

        foreach ($this->needles as $needle) {
            $text = str_replace($needle, self::MASK, $text);
        }

        return $text;
    }
}
