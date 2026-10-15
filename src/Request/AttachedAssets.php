<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

/**
 * The images a client has attached for the duration of some code, so that text can refer to them
 * by reference without the image being placed anywhere in the request: see
 * {@see \TypesafeAi\Client::attachAssets()}.
 *
 * Mirrors the official SDK (0.7.5)'s `AttachedAssets`. Attaching the same image more than once, at
 * the same time or nested, holds it that many times; it stays attached until the last hold is
 * released. Holds are kept by reference, so a clone of an image holds the same slot as its
 * original.
 *
 * @internal
 */
final class AttachedAssets
{
    /**
     * @var array<string, Image> Attached images, by reference.
     */
    private array $images = [];

    /**
     * @var array<string, positive-int> How many holds each attached image has, by reference.
     */
    private array $holds = [];

    /**
     * Holds every image in $assets. Nothing is held unless every value is an image.
     *
     * @param Image|array<array-key, mixed> $assets An image, or a collection of images; the keys of
     *     a collection are ignored.
     *
     * @return list<Image> The images held, to pass to {@see self::release()} later.
     *
     * @throws \InvalidArgumentException if a value is not an {@see Image}.
     */
    public function attach(Image|array $assets): array
    {
        $candidates = $assets instanceof Image ? [$assets] : $assets;

        $images = [];
        foreach ($candidates as $image) {
            if (!$image instanceof Image) {
                throw new \InvalidArgumentException(sprintf(
                    'attached values must be %s instances, got %s.',
                    Image::class,
                    get_debug_type($image),
                ));
            }
            $images[] = $image;
        }

        foreach ($images as $image) {
            $reference = (string) $image;
            $this->images[$reference] = $image;
            $this->holds[$reference] = ($this->holds[$reference] ?? 0) + 1;
        }

        return $images;
    }

    /**
     * Gives back one hold on each of $images, which {@see self::attach()} returned. An image with
     * no hold left is no longer attached.
     *
     * @param list<Image> $images
     */
    public function release(array $images): void
    {
        foreach ($images as $image) {
            $reference = (string) $image;
            $holds = ($this->holds[$reference] ?? 0) - 1;

            if ($holds > 0) {
                $this->holds[$reference] = $holds;
            } else {
                unset($this->holds[$reference], $this->images[$reference]);
            }
        }
    }

    /**
     * The images attached right now, by reference. A call that starts later does not change it.
     *
     * @return array<string, Image>
     */
    public function snapshot(): array
    {
        return $this->images;
    }
}
