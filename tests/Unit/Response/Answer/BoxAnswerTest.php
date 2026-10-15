<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Response\Answer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Exception\ResponseFormatException;
use TypesafeAi\Response\Answer\AnswerInterface;
use TypesafeAi\Response\Answer\BoxAnswer;

final class BoxAnswerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return [
            'type' => 'box',
            'box' => ['top' => 0.2, 'bottom' => 0.66, 'left' => 0.19, 'right' => 0.68],
            'confidence' => 0.9,
        ];
    }

    #[Test]
    public function hydratesTheBoxAndTheConfidence(): void
    {
        $answer = BoxAnswer::fromArray(self::valid(), 'answers.dog');

        self::assertInstanceOf(AnswerInterface::class, $answer);
        self::assertSame('box', $answer->type());
        self::assertSame(0.2, $answer->box()->top());
        self::assertSame(0.66, $answer->box()->bottom());
        self::assertSame(0.19, $answer->box()->left());
        self::assertSame(0.68, $answer->box()->right());
        self::assertSame(0.9, $answer->confidence());
    }

    #[Test]
    public function acceptsIntegersForEveryNumber(): void
    {
        $answer = BoxAnswer::fromArray(
            ['type' => 'box', 'box' => ['top' => 0, 'bottom' => 1, 'left' => 0, 'right' => 1], 'confidence' => 1],
            'answers.dog',
        );

        self::assertSame(0.0, $answer->box()->top());
        self::assertSame(1.0, $answer->box()->bottom());
        self::assertSame(1.0, $answer->confidence());
    }

    #[Test]
    public function aNullConfidenceIsKeptAsNull(): void
    {
        $data = self::valid();
        $data['confidence'] = null;

        $answer = BoxAnswer::fromArray($data, 'answers.dog');

        self::assertNull($answer->confidence());
        self::assertArrayNotHasKey('confidence', $answer->toArray());
        self::assertNull($answer->raw()['confidence']);
    }

    #[Test]
    public function doesNotCheckThatTheEdgesAreFractions(): void
    {
        $answer = BoxAnswer::fromArray(
            ['type' => 'box', 'box' => ['top' => -0.25, 'bottom' => 1.5, 'left' => 7, 'right' => 0], 'confidence' => 3],
            'answers.dog',
        );

        self::assertSame(-0.25, $answer->box()->top());
        self::assertSame(1.5, $answer->box()->bottom());
        self::assertSame(7.0, $answer->box()->left());
        self::assertSame(3.0, $answer->confidence());
    }

    #[Test]
    public function toArrayIsTheNormalisedAnswer(): void
    {
        $data = self::valid();
        $data['extra'] = ['k' => [1.0]];

        $answer = BoxAnswer::fromArray($data, 'answers.dog');

        self::assertSame(
            [
                'type' => 'box',
                'box' => ['top' => 0.2, 'bottom' => 0.66, 'left' => 0.19, 'right' => 0.68],
                'confidence' => 0.9,
            ],
            $answer->toArray(),
        );
        self::assertSame($answer->box()->toArray(), $answer->toArray()['box']);
    }

    #[Test]
    public function rawKeepsEverythingTheServerSent(): void
    {
        $data = self::valid();
        $data['extra'] = ['k' => [1.0]];

        self::assertSame($data, BoxAnswer::fromArray($data, 'answers.dog')->raw());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidAnswerProvider(): iterable
    {
        $valid = self::valid();
        $box = ['top' => 0.2, 'bottom' => 0.66, 'left' => 0.19, 'right' => 0.68];

        yield 'no box' => [
            ['type' => 'box', 'confidence' => 0.9],
            'answers.dog.box: field is required but missing.',
        ];
        yield 'a box that is text' => [
            [...$valid, 'box' => 'top-left'],
            'answers.dog.box: expected an object, got string.',
        ];
        yield 'a box that is null' => [
            [...$valid, 'box' => null],
            'answers.dog.box: expected an object, got null.',
        ];
        yield 'a box that is a list' => [
            [...$valid, 'box' => [0.2, 0.66, 0.19, 0.68]],
            'answers.dog.box.top: field is required but missing.',
        ];
        foreach (['top', 'bottom', 'left', 'right'] as $edge) {
            $without = $box;
            unset($without[$edge]);
            yield "no {$edge} edge" => [
                [...$valid, 'box' => $without],
                "answers.dog.box.{$edge}: field is required but missing.",
            ];
            yield "a {$edge} edge that is text" => [
                [...$valid, 'box' => [...$box, $edge => '0.5']],
                "answers.dog.box.{$edge}: expected a number, got string.",
            ];
        }
        yield 'a null edge' => [
            [...$valid, 'box' => [...$box, 'left' => null]],
            'answers.dog.box.left: expected a number, got null.',
        ];
        yield 'no confidence' => [
            ['type' => 'box', 'box' => $box],
            'answers.dog.confidence: field is required but missing.',
        ];
        yield 'a confidence that is text' => [
            [...$valid, 'confidence' => 'high'],
            'answers.dog.confidence: expected a number or null, got string.',
        ];
        yield 'a confidence that is a boolean' => [
            [...$valid, 'confidence' => true],
            'answers.dog.confidence: expected a number or null, got bool.',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('invalidAnswerProvider')]
    public function rejectsAnAnswerThatDoesNotHaveTheShape(array $data, string $message): void
    {
        $this->expectException(ResponseFormatException::class);
        $this->expectExceptionMessage($message);

        BoxAnswer::fromArray($data, 'answers.dog');
    }
}
