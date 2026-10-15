<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

/**
 * An {@see Image} that has been read: its bytes, and the content type to label its upload with.
 *
 * Mirrors the official SDK (0.7.5): only PNG and JPEG are recognised, by their file signatures, and
 * only to label the upload part. The API detects the format itself, so any other data is still
 * sent, labelled as generic binary data.
 *
 * @internal
 */
final readonly class ResolvedImage
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    private const string JPEG_SIGNATURE = "\xFF\xD8\xFF";

    private const string UNKNOWN_CONTENT_TYPE = 'application/octet-stream';

    public string $contentType;

    public function __construct(public string $bytes)
    {
        $this->contentType = match (true) {
            str_starts_with($bytes, self::PNG_SIGNATURE) => 'image/png',
            str_starts_with($bytes, self::JPEG_SIGNATURE) => 'image/jpeg',
            default => self::UNKNOWN_CONTENT_TYPE,
        };
    }
}
