<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Support;

/**
 * Builds the parts that {@see \TypesafeAi\Http\Multipart::encode()} takes.
 */
final class Parts
{
    private function __construct()
    {
    }

    /**
     * @return array{name: string, contentType: string, content: string, filename: ?string}
     */
    public static function part(string $name, string $contentType, string $content, ?string $filename = null): array
    {
        return ['name' => $name, 'contentType' => $contentType, 'content' => $content, 'filename' => $filename];
    }
}
