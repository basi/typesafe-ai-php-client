<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Unit\Request;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypesafeAi\Request\Image;
use TypesafeAi\Request\References;
use TypesafeAi\Tests\Support\Samples;

final class ReferencesTest extends TestCase
{
    #[Test]
    public function encodesContentWithoutImagesExactlyLikeJsonEncode(): void
    {
        $content = ['text' => "caf\u{E9} / <b>", 'list' => [1, 2.5, true, null], 'map' => ['a' => ['b' => []]]];

        [$json, $found] = References::encode($content);

        self::assertSame(json_encode($content, JSON_THROW_ON_ERROR), $json);
        self::assertSame([], $found);
    }

    /**
     * @return iterable<string, array{0: \Closure(Image): mixed}>
     */
    public static function containerProvider(): iterable
    {
        yield 'the value itself' => [static fn (Image $image): mixed => $image];
        yield 'a list' => [static fn (Image $image): mixed => ['text', $image]];
        yield 'a map' => [static fn (Image $image): mixed => ['photo' => $image]];
        yield 'nested arrays' => [static fn (Image $image): mixed => ['a' => [['b' => [$image]]]]];
        yield 'a stdClass' => [static fn (Image $image): mixed => (object) ['photo' => $image]];
        yield 'an object with public properties' => [
            static fn (Image $image): mixed => new class ($image) {
                public function __construct(public Image $photo)
                {
                }
            },
        ];
        yield 'an ArrayObject' => [static fn (Image $image): mixed => new \ArrayObject(['photo' => $image])];
        yield 'a JsonSerializable returning an array' => [
            static fn (Image $image): mixed => new class ($image) implements \JsonSerializable {
                public function __construct(private readonly Image $photo)
                {
                }

                public function jsonSerialize(): mixed
                {
                    return ['photo' => $this->photo];
                }
            },
        ];
        yield 'a JsonSerializable returning an image' => [
            static fn (Image $image): mixed => new class ($image) implements \JsonSerializable {
                public function __construct(private readonly Image $photo)
                {
                }

                public function jsonSerialize(): mixed
                {
                    return $this->photo;
                }
            },
        ];
    }

    /**
     * @param \Closure(Image): mixed $wrap
     */
    #[Test]
    #[DataProvider('containerProvider')]
    public function findsAnImageWhereverJsonEncodeMeetsIt(\Closure $wrap): void
    {
        $image = Image::fromBytes(Samples::PNG);

        [$json, $found] = References::encode($wrap($image));

        self::assertSame([(string) $image => $image], $found);
        self::assertStringContainsString(json_encode((string) $image, JSON_THROW_ON_ERROR), $json);
    }

    #[Test]
    public function reportsEachImageOnceInTheOrderItWasMet(): void
    {
        $first = Image::fromBytes('first');
        $second = Image::fromBytes('second');
        $third = Image::fromBytes('third');

        [$json, $found] = References::encode(['b' => $second, 'a' => [$first, $second], 'c' => $third, 'd' => $first]);

        self::assertSame([(string) $second, (string) $first, (string) $third], array_keys($found));
        self::assertSame([(string) $second => $second, (string) $first => $first, (string) $third => $third], $found);
        self::assertSame(
            sprintf('{"b":"%1$s","a":["%2$s","%1$s"],"c":"%3$s","d":"%2$s"}', $second, $first, $third),
            $json,
        );
    }

    #[Test]
    public function writesAnAliasInPlaceOfTheReferenceAndDoesNotReportTheImage(): void
    {
        $named = Image::fromBytes('named');
        $inline = Image::fromBytes('inline');

        [$json, $found] = References::encode(['a' => $named, 'b' => $inline, 'c' => $named], ['<photo>' => $named]);

        self::assertSame(sprintf('{"a":"<photo>","b":"%s","c":"<photo>"}', $inline), $json);
        self::assertSame([(string) $inline => $inline], $found);
    }

    #[Test]
    public function anImageWithSeveralAliasesTakesTheLastOne(): void
    {
        $image = Image::fromBytes('image');

        [$json] = References::encode([$image], ['<a>' => $image, '<b>' => $image]);

        self::assertSame('["<b>"]', $json);
    }

    #[Test]
    public function writesAnIntegerAliasAsAString(): void
    {
        $image = Image::fromBytes('image');

        [$json] = References::encode([$image], [1 => $image]);

        self::assertSame('["1"]', $json);
    }

    #[Test]
    public function anImageOutsideAnEncodingIsJustItsReference(): void
    {
        $image = Image::fromBytes('image');

        self::assertSame((string) $image, References::write($image));
        self::assertSame('"' . $image . '"', json_encode($image, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->activeContexts());
    }

    #[Test]
    public function keepsTheDefaultEscapingOfAngleBracketsSoThatReferencesStayVisible(): void
    {
        $image = Image::fromBytes('image');

        [$json] = References::encode(['text' => "Compare {$image} with <b>this</b>"]);

        // JSON_HEX_TAG would write every "<" as an escape sequence and hide the reference. The "/"
        // of the closing tag is escaped, as json_encode() does by default.
        self::assertStringContainsString("Compare {$image} with <b>this<\\/b>", $json);
        self::assertSame(1, preg_match('/<image:[0-9a-f]{32}>/', $json));
    }

    #[Test]
    public function encodingsNestedInsideAnEncodingKeepTheirImagesApart(): void
    {
        $inner = Image::fromBytes('inner');
        $outer = Image::fromBytes('outer');
        $nested = new class ($inner) implements \JsonSerializable {
            /** @var ?array{string, array<string, Image>} */
            public ?array $result = null;

            public function __construct(private readonly Image $image)
            {
            }

            public function jsonSerialize(): string
            {
                $this->result = References::encode(['inner' => $this->image]);

                return 'nested';
            }
        };

        [$json, $found] = References::encode(['nested' => $nested, 'outer' => $outer]);

        self::assertSame(sprintf('{"nested":"nested","outer":"%s"}', $outer), $json);
        self::assertSame([(string) $outer => $outer], $found);
        self::assertSame([(string) $inner => $inner], $nested->result[1] ?? null);
        self::assertSame([], $this->activeContexts());
    }

    #[Test]
    public function anImageThatANestedJsonEncodeMeetsCountsAsPlacedInTheEncodingInProgress(): void
    {
        $image = Image::fromBytes('embedded');
        $embedding = new class ($image) implements \JsonSerializable {
            public function __construct(private readonly Image $image)
            {
            }

            public function jsonSerialize(): string
            {
                // The caller embeds a JSON document in a string: this nested call meets the image.
                return json_encode(['photo' => $this->image], JSON_THROW_ON_ERROR);
            }
        };

        [$json, $found] = References::encode(['note' => $embedding]);

        self::assertSame([(string) $image => $image], $found);
        self::assertSame(
            json_encode(['note' => sprintf('{"photo":"%s"}', $image)], JSON_THROW_ON_ERROR),
            $json,
        );
    }

    #[Test]
    public function anExceptionWhileEncodingLeavesNoContextBehind(): void
    {
        $failing = new class implements \JsonSerializable {
            public function jsonSerialize(): never
            {
                throw new \DomainException('cannot serialize');
            }
        };
        $image = Image::fromBytes('image');

        try {
            References::encode([$image, $failing]);
            self::fail('Expected an exception.');
        } catch (\Exception $exception) {
            self::assertInstanceOf(\DomainException::class, $exception);
            self::assertSame('cannot serialize', $exception->getMessage());
        }

        self::assertSame([], $this->activeContexts());
        self::assertSame([(string) $image => $image], References::encode($image)[1]);
    }

    #[Test]
    public function aJsonErrorLeavesNoContextBehindEither(): void
    {
        try {
            References::encode(["bad utf-8: \xB1\x31"]);
            self::fail('Expected an exception.');
        } catch (\JsonException) {
            // Expected.
        }

        self::assertSame([], $this->activeContexts());
    }

    #[Test]
    public function fibersThatInterleaveWhileTheyEncodeDoNotSeeEachOthersImages(): void
    {
        // Each fiber needs a pause of its own: json_encode() refuses to meet an object again while
        // it is still serializing it, and a suspended fiber is still serializing it.
        $encode = static fn (Image $before, Image $after): array => References::encode([
            'before' => $before,
            'pause' => new class implements \JsonSerializable {
                public function jsonSerialize(): string
                {
                    \Fiber::suspend();

                    return 'resumed';
                }
            },
            'after' => $after,
        ]);
        [$a1, $a2, $b1, $b2] = [
            Image::fromBytes('a1'),
            Image::fromBytes('a2'),
            Image::fromBytes('b1'),
            Image::fromBytes('b2'),
        ];

        $fiberA = new \Fiber(static fn (): array => $encode($a1, $a2));
        $fiberB = new \Fiber(static fn (): array => $encode($b1, $b2));
        $fiberA->start();
        $fiberB->start();
        self::assertCount(2, $this->activeContexts());

        // The first fiber finishes first, while the second one's context is the newest: its images
        // must still go to its own context, and its context must be the one that is removed.
        $fiberA->resume();
        self::assertCount(1, $this->activeContexts());
        $fiberB->resume();

        self::assertSame([(string) $a1 => $a1, (string) $a2 => $a2], self::foundBy($fiberA));
        self::assertSame([(string) $b1 => $b1, (string) $b2 => $b2], self::foundBy($fiberB));
        self::assertSame([], $this->activeContexts());
    }

    #[Test]
    public function aFiberThatIsDroppedWhileItIsSuspendedInAnEncodingTakesItsContextWithIt(): void
    {
        $image = Image::fromBytes('image');
        $fiber = new \Fiber(static function () use ($image): void {
            References::encode([
                $image,
                new class implements \JsonSerializable {
                    public function jsonSerialize(): string
                    {
                        \Fiber::suspend();

                        return 'never resumed';
                    }
                },
            ]);
        });
        $fiber->start();
        self::assertCount(1, $this->activeContexts());

        // Nothing but the variable keeps the fiber alive: dropping it unwinds the fiber, which ends
        // the encoding it was suspended in. If the context held the fiber, neither would ever end.
        unset($fiber);

        self::assertSame([], $this->activeContexts());
        // A context of the main flow is not mistaken for the one of the fiber that is gone.
        self::assertSame([(string) $image => $image], References::encode($image)[1]);
    }

    #[Test]
    public function aFiberThatIsNotEncodingDoesNotReportIntoTheContextOfTheMainFlow(): void
    {
        $inFiber = Image::fromBytes('in the fiber');
        $inMain = Image::fromBytes('in the main flow');
        $spy = new class ($inFiber) implements \JsonSerializable {
            public function __construct(private readonly Image $image)
            {
            }

            public function jsonSerialize(): string
            {
                $fiber = new \Fiber(fn (): string => json_encode($this->image, JSON_THROW_ON_ERROR));
                $fiber->start();

                return 'spied';
            }
        };

        [, $found] = References::encode(['spy' => $spy, 'main' => $inMain]);

        self::assertSame([(string) $inMain => $inMain], $found);
    }

    #[Test]
    public function aMainFlowContextIsNotUsedByAFiberItStartsEncodingIn(): void
    {
        $image = Image::fromBytes('image');
        $outer = new class ($image) implements \JsonSerializable {
            /** @var ?array<string, Image> */
            public ?array $foundInFiber = null;

            public function __construct(private readonly Image $image)
            {
            }

            public function jsonSerialize(): string
            {
                $fiber = new \Fiber(fn (): array => References::encode([$this->image]));
                $fiber->start();
                $this->foundInFiber = ReferencesTest::foundBy($fiber);

                return 'outer';
            }
        };

        [, $found] = References::encode([$outer]);

        self::assertSame([], $found);
        self::assertSame([(string) $image => $image], $outer->foundInFiber);
    }

    /**
     * The images that the encoding run by the finished $fiber found.
     *
     * @param \Fiber<mixed, mixed, mixed, mixed> $fiber
     *
     * @return array<string, Image>
     */
    public static function foundBy(\Fiber $fiber): array
    {
        $returned = $fiber->getReturn();
        self::assertIsArray($returned);
        self::assertArrayHasKey(1, $returned);
        self::assertIsArray($returned[1]);

        /** @var array<string, Image> $found */
        $found = $returned[1];

        return $found;
    }

    /**
     * @return list<mixed> The encodings in progress, which should be none between encodings.
     */
    private function activeContexts(): array
    {
        $contexts = (new \ReflectionProperty(References::class, 'active'))->getValue();
        self::assertIsArray($contexts);

        return array_values($contexts);
    }
}
