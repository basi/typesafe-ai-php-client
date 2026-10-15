<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Uuid;

final class UuidTest extends TestCase
{
    #[Test]
    public function isThirtyTwoLowercaseHexDigitsOfAVersionFourUuid(): void
    {
        for ($attempt = 0; $attempt < 200; $attempt++) {
            // The 13th digit is the version, the 17th carries the variant bits (10xx).
            self::assertMatchesRegularExpression('/^[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}$/D', Uuid::hex());
        }
    }

    #[Test]
    public function isDifferentEveryTime(): void
    {
        $values = [];
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $values[Uuid::hex()] = true;
        }

        self::assertCount(500, $values);
    }
}
