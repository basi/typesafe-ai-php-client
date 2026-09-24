<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Contract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Response\ModelCard;

/**
 * Hydrates a synthetic GET /v1/models response, built strictly from the OpenAPI spec, to guard
 * against regressions in model list parsing.
 */
final class ModelCardContractTest extends TestCase
{
    #[Test]
    public function syntheticModelsResponseHydrates(): void
    {
        $body = file_get_contents(__DIR__ . '/fixtures/synthetic/models-response.json');
        self::assertNotFalse($body);

        $cards = ModelCard::listFromJson($body);

        self::assertNotEmpty($cards);

        foreach ($cards as $card) {
            self::assertNotSame('', $card->name());
            self::assertNotSame('', $card->description());
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $card->releaseDate());
        }
    }
}
