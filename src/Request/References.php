<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

/**
 * Finds the images in a request while it is encoded as JSON.
 *
 * Mirrors the official SDK (0.7.5), whose serializer hands each image to a context object as it
 * meets it. Here {@see self::encode()} runs `json_encode()` with a context active, and
 * {@see Image::jsonSerialize()} reports to the active context through {@see self::write()}. Since
 * `json_encode()` calls `jsonSerialize()` at any depth — inside arrays, plain objects, other
 * `JsonSerializable` values, an `ArrayObject` — an image is found exactly where it is encoded,
 * without a second walk over the request that would have to repeat the encoder's rules for
 * objects.
 *
 * Contexts are kept per fiber, so fibers that interleave while they encode do not see each
 * other's images. With no context for the current fiber, an image simply encodes as its reference,
 * which is what a plain `json_encode($request)` for logging does. A context refers to its fiber
 * weakly: a fiber that suspends inside an encoding and is then dropped can still be destroyed,
 * which unwinds it and removes its context, instead of being kept alive here for good.
 *
 * The context is the one of the encoding in progress, whoever calls `json_encode()`: an image that
 * a nested `json_encode()` meets — for example inside the caller's own `jsonSerialize()`, which
 * embeds a JSON document in a string — counts as placed in the request and is uploaded. That is
 * deliberate: it is what lets a reference inside such embedded JSON work, and a reference that
 * the caller wrote into the request is meant to stand for its image. In the official SDK an image
 * is only ever serialized by the request's own serializer, so there is no such case.
 *
 * The encoded JSON must keep the default escaping, which leaves `<` and `>` as they are:
 * `JSON_HEX_TAG` would write the `<` of every reference as an escape sequence, and the scan for
 * references in text would no longer find them.
 *
 * @internal
 */
final class References
{
    /**
     * @var list<self> The contexts of the encodings in progress, oldest first.
     */
    private static array $active = [];

    /**
     * @var array<string, Image> Images found in this context, by reference, in the order found.
     */
    private array $found = [];

    /**
     * @param \WeakMap<Image, string> $aliases
     * @param ?\WeakReference<object> $fiber The fiber that started the encoding, or null for the
     *     main flow.
     */
    private function __construct(
        private readonly \WeakMap $aliases,
        private readonly ?\WeakReference $fiber,
    ) {
    }

    /**
     * Encodes $value as JSON, collecting the images in it.
     *
     * @param array<array-key, Image> $aliases Images that have a name in the request, by name. An
     *     image with an alias is encoded as that alias, not as its reference, and is not reported
     *     as found. An image given more than one alias takes the last.
     *
     * @return array{string, array<string, Image>} The JSON, and the images that were encoded as
     *     their reference, by reference, in the order they were met.
     *
     * @throws \JsonException
     */
    public static function encode(mixed $value, array $aliases = []): array
    {
        /** @var \WeakMap<Image, string> $names */
        $names = new \WeakMap();
        foreach ($aliases as $name => $image) {
            $names[$image] = (string) $name;
        }

        $fiber = \Fiber::getCurrent();
        $context = new self($names, $fiber === null ? null : \WeakReference::create($fiber));
        // The fiber is suspended inside json_encode() in some cases, and this frame is part of its
        // stack: a variable here would keep the fiber alive from the inside, and it could never be
        // destroyed when dropped.
        unset($fiber);
        self::$active[] = $context;

        try {
            // The default flags, on purpose: see the class documentation on JSON_HEX_TAG.
            $json = json_encode($value, JSON_THROW_ON_ERROR);
        } finally {
            self::deactivate($context);
        }

        return [$json, $context->found];
    }

    /**
     * What $image is encoded as: its alias if it has one in the encoding in progress, else its
     * reference, which is also recorded as found. Outside an encoding, just the reference.
     */
    public static function write(Image $image): string
    {
        $context = self::current();
        $reference = (string) $image;

        if ($context === null) {
            return $reference;
        }

        $alias = $context->aliases[$image] ?? null;
        if ($alias !== null) {
            return $alias;
        }

        $context->found[$reference] = $image;

        return $reference;
    }

    /**
     * The newest context started by the current fiber, or by the main flow when there is no fiber.
     */
    private static function current(): ?self
    {
        $fiber = \Fiber::getCurrent();

        for ($index = count(self::$active) - 1; $index >= 0; $index--) {
            $owner = self::$active[$index]->fiber;

            // A context of the main flow has no owner; one whose fiber is gone matches nobody.
            if ($fiber === null ? $owner === null : $owner?->get() === $fiber) {
                return self::$active[$index];
            }
        }

        return null;
    }

    /**
     * Removes $context by identity, not as the last one: fibers can finish their encodings in an
     * order other than the one they started them in.
     */
    private static function deactivate(self $context): void
    {
        $index = array_search($context, self::$active, true);
        if ($index !== false) {
            array_splice(self::$active, $index, 1);
        }
    }
}
