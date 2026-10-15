<?php

declare(strict_types=1);

namespace TypesafeAi;

/**
 * Random identifiers, for the reference of an image and the boundary of a multipart body.
 *
 * @internal
 */
final class Uuid
{
    private function __construct()
    {
    }

    /**
     * The 32 lowercase hex digits of a random (version 4) UUID, without hyphens: the same shape as
     * the `uuid4().hex` the official SDK (0.7.5) uses for both purposes.
     *
     * @throws \Random\RandomException if the system has no source of randomness.
     */
    public static function hex(): string
    {
        $bytes = random_bytes(16);
        // RFC 9562: the version (4) goes in the top bits of byte 6, the variant (10) in byte 8.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return bin2hex($bytes);
    }
}
