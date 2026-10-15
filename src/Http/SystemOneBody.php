<?php

declare(strict_types=1);

namespace TypesafeAi\Http;

use TypesafeAi\Exception\AssetException;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\References;
use TypesafeAi\Request\SystemOneRequest;

/**
 * Builds the body of a `POST /v1/systemone` request: a JSON document, or, when the request carries
 * images, a `multipart/form-data` upload.
 *
 * Mirrors the official SDK (0.7.5)'s `prepare_system_one` and `with_assets`. A request with no
 * image is the JSON document, exactly as it was before images were supported. A request with
 * images becomes a multipart body: the JSON document is its first part, named `request`, with an
 * `assets` object appended that maps each image's name to the part that holds it, followed by one
 * part per image, holding the raw bytes. Sent as they are, the files take a quarter less room than
 * the base64 text they would need inside the JSON, which is a third larger than the file.
 *
 * @internal
 */
final class SystemOneBody
{
    private const string JSON_CONTENT_TYPE = 'application/json';

    /**
     * Matches the reference of any image, which is the same text in a string and in JSON.
     */
    private const string REFERENCE_PATTERN = '/<image:[0-9a-f]{32}>/';

    private function __construct()
    {
    }

    /**
     * Encodes $request, reading every image that has to be sent. The body is built once, so a retry
     * sends exactly the same bytes, boundary included.
     *
     * The images sent, in this order, are those named in the request's `assets`, in the order
     * given, then those whose reference appears in the encoded JSON, in the order they first
     * appear: an image placed in the content, one the client has attached, or a named one, whose
     * reference the text mentions. A reference to any other image is just text.
     *
     * @param array<string, Image> $attached The images the client has attached, by reference.
     *
     * @return array{string, string} The `Content-Type` header value and the body.
     *
     * @throws \JsonException if the request cannot be encoded as JSON.
     * @throws AssetException if an image cannot be read. The message names the image by the name it
     *     has in the request: its alias, or its reference when it has none.
     */
    public static function encode(SystemOneRequest $request, array $attached = []): array
    {
        // A named image is written as its name wherever it is placed; any other is written as its
        // reference and reported in $found.
        [$json, $found] = References::encode($request->jsonSerialize(), $request->assets());

        /** @var array<array-key, Image> $pending The images to upload, by name, in order. */
        $pending = $request->assets();

        // The references the body may mention: that of an attached image, that of a named image,
        // and that of an image placed in the content. Text can hold any of them, for example from
        // an interpolated string. The later kind wins if a reference is in more than one.
        $known = array_replace($attached, self::indexedByReference($pending), $found);

        // A reference is JSON-safe ASCII, so one scan of the encoded body finds it in every string.
        if ($known !== [] && preg_match_all(self::REFERENCE_PATTERN, $json, $matches) > 0) {
            foreach ($matches[0] as $reference) {
                if (isset($known[$reference]) && !array_key_exists($reference, $pending)) {
                    $pending[$reference] = $known[$reference];
                }
            }
        }

        if ($pending === []) {
            return [self::JSON_CONTENT_TYPE, $json];
        }

        $entries = [];
        $parts = [];
        foreach ($pending as $name => $image) {
            try {
                $resolved = $image->resolve();
            } catch (AssetException $exception) {
                throw new AssetException(sprintf("Asset '%s': %s", $name, $exception->getMessage()), $exception);
            }

            // Each name has a part of its own, named by its position, so that the JSON can name the
            // part before the image is read. An image with two names is sent twice: the API wants
            // each part claimed by exactly one asset.
            $partName = 'asset-' . count($parts);
            // Assigned by key rather than merged, so that every entry keeps its name and its place.
            $entries[$name] = ['type' => 'image', 'part' => $partName];
            $parts[] = [
                'name' => $partName,
                'contentType' => $resolved->contentType,
                'content' => $resolved->bytes,
                'filename' => $partName,
            ];
        }

        // `assets` goes last in the body. $json is the encoded request, always a JSON object, so
        // appending to it saves encoding the request a second time.
        $body = substr($json, 0, -1) . ',"assets":' . json_encode((object) $entries, JSON_THROW_ON_ERROR) . '}';

        return Multipart::encode([
            ['name' => 'request', 'contentType' => self::JSON_CONTENT_TYPE, 'content' => $body, 'filename' => null],
            ...$parts,
        ]);
    }

    /**
     * @param array<array-key, Image> $images
     *
     * @return array<string, Image>
     */
    private static function indexedByReference(array $images): array
    {
        $indexed = [];
        foreach ($images as $image) {
            $indexed[(string) $image] = $image;
        }

        return $indexed;
    }
}
