<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * An asset of a request, such as an image, could not be read or is unusable (for example, the file
 * does not exist). The message names the asset: `Asset 'photo': Image file 'photo.png' could not
 * be read: No such file or directory.`
 *
 * Thrown before any HTTP request is sent, so it is never retried and nothing has been billed. It is
 * not an `\InvalidArgumentException`: an `Image` that is built from a path is valid until the
 * moment the file is read, which only happens when a request is sent. Always carries
 * {@see \Exception::getCode()} `=== 0`.
 */
final class AssetException extends \RuntimeException implements TypesafeAiException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
