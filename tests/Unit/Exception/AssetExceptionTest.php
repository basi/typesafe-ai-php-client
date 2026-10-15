<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\AssetException;
use TypesafeAi\Exception\TypesafeAiException;

final class AssetExceptionTest extends TestCase
{
    #[Test]
    public function isATypesafeAiExceptionThatNeedsNoHttpStatus(): void
    {
        $exception = new AssetException("Asset 'photo': Image data is empty.");

        self::assertInstanceOf(TypesafeAiException::class, $exception);
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame("Asset 'photo': Image data is empty.", $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    #[Test]
    public function keepsTheCauseItIsGiven(): void
    {
        $previous = new AssetException('Image data is empty.');

        $exception = new AssetException("Asset 'photo': Image data is empty.", $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
