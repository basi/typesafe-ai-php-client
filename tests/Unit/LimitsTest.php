<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Limits;

final class LimitsTest extends TestCase
{
    #[Test]
    public function assetReferenceLimitsAreInBytes(): void
    {
        self::assertSame(1024, Limits::MAX_ASSET_REFERENCE_BYTES);
        self::assertSame(64 * 1024, Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES);
    }

    #[Test]
    public function imageSizeLimitsMatchTheDocumentedMebibyteSizes(): void
    {
        self::assertSame(16 * 1024 * 1024, Limits::MAX_IMAGE_BYTES);
        self::assertSame(32 * 1024 * 1024, Limits::MAX_REQUEST_BODY_BYTES);
    }

    #[Test]
    public function imageDimensionLimitsMatchTheDocumentedRanges(): void
    {
        self::assertSame(256, Limits::MIN_IMAGE_SIDE_PIXELS);
        self::assertSame(1568, Limits::MAX_IMAGE_SIDE_PIXELS);
        // The pixel limits are the areas of a 280 x 280 and a 1568 x 1568 square.
        self::assertSame(78400, Limits::MIN_IMAGE_PIXELS);
        self::assertSame(280 * 280, Limits::MIN_IMAGE_PIXELS);
        self::assertSame(2458624, Limits::MAX_IMAGE_PIXELS);
        self::assertSame(Limits::MAX_IMAGE_SIDE_PIXELS ** 2, Limits::MAX_IMAGE_PIXELS);
        // A 256 x 256 image satisfies the side limits but is still too small overall.
        self::assertLessThan(Limits::MIN_IMAGE_PIXELS, Limits::MIN_IMAGE_SIDE_PIXELS ** 2);
    }
}
