<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Support;

/**
 * Small stand-ins for image files. The client labels an upload by the signature at the start of
 * the file and leaves everything else to the API, so a signature followed by filler is enough.
 */
final class Samples
{
    public const string PNG = "\x89PNG\r\n\x1A\nimage data";

    public const string JPEG = "\xFF\xD8\xFFimage data";

    public const string GIF = 'GIF89a image data';

    private function __construct()
    {
    }
}
