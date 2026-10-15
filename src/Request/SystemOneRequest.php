<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

use TypesafeAi\Limits;
use TypesafeAi\Request\Asset\ImageAsset;
use TypesafeAi\Request\Question\QuestionInterface;

/**
 * A request to POST /v1/systemone: content to evaluate together with one or more named questions,
 * and optionally the images (experimental) that content refers to.
 */
final class SystemOneRequest implements \JsonSerializable
{
    private readonly mixed $state;

    /**
     * @var array<array-key, QuestionInterface>
     */
    private readonly array $questions;

    private readonly ?string $model;

    /**
     * @var array<string, ImageAsset>
     */
    private readonly array $assets;

    /**
     * $state, $questions and $assets are validated at runtime rather than typed narrowly, so that
     * validation does not appear dead to static analysis: $state must be a string, array, or
     * object (native `mixed` lets a caller pass anything, including the int/null this constructor
     * rejects); $questions' values must implement QuestionInterface, and PHP normalises a
     * numeric-looking string key (e.g. "1") to an integer, so a single-question map keyed "1" is
     * legitimate and its key genuinely is an int by the time this constructor sees it. $assets'
     * values must be ImageAsset instances, and its keys must be strings: a reference that PHP
     * turns into an integer key (e.g. "1") is rejected.
     *
     * @param mixed $state Content all questions in this request refer to.
     * @param array<array-key, mixed> $questions Non-empty map of question name to question. The
     *     response uses these names to identify the answers.
     * @param ?string $model Model name or alias. Omitted from the request payload when null; a
     *     client sending this request is expected to fill in its own default in that case.
     * @param array<array-key, mixed> $assets Map of reference string to {@see ImageAsset}; empty
     *     for a request without images. Write each reference inside a string value of $state, or
     *     of a question's instructions or criteria, and the API replaces it with the image there.
     *     Object keys are never replaced, and every asset must be referenced at least once. A
     *     reference must not be empty or whitespace-only and is at most
     *     {@see Limits::MAX_ASSET_REFERENCE_BYTES} bytes long, and all references together are at
     *     most {@see Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES} bytes. Pick references that cannot
     *     occur in your own text by accident, such as a new UUID for each image.
     *
     * @throws \InvalidArgumentException if any argument is invalid. When $assets is not empty,
     *     $state and the questions must also be encodable as JSON, so that the references in them
     *     can be found.
     */
    public function __construct(mixed $state, array $questions, ?string $model = null, array $assets = [])
    {
        if (!is_string($state) && !is_array($state) && !is_object($state)) {
            throw new \InvalidArgumentException(sprintf(
                'state must be a string, array, or object, got %s.',
                get_debug_type($state),
            ));
        }

        if ($questions === []) {
            throw new \InvalidArgumentException('questions must not be empty.');
        }

        $validatedQuestions = [];
        foreach ($questions as $name => $question) {
            if (trim((string) $name) === '') {
                throw new \InvalidArgumentException('questions keys must be non-empty strings.');
            }
            if (!$question instanceof QuestionInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'questions values must implement %s, got %s.',
                    QuestionInterface::class,
                    get_debug_type($question),
                ));
            }
            $validatedQuestions[$name] = $question;
        }

        if ($model !== null && trim($model) === '') {
            throw new \InvalidArgumentException('model must not be empty when provided.');
        }

        $validatedAssets = self::validatedAssets($assets);
        if ($validatedAssets !== []) {
            self::assertEveryAssetIsReferenced($validatedAssets, $state, $validatedQuestions);
        }

        $this->state = $state;
        $this->questions = $validatedQuestions;
        $this->model = $model;
        $this->assets = $validatedAssets;
    }

    public function state(): mixed
    {
        return $this->state;
    }

    /**
     * @return array<array-key, QuestionInterface>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    /**
     * @return array<string, ImageAsset> Reference to image; empty for a request without images.
     */
    public function assets(): array
    {
        return $this->assets;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'state' => $this->state,
            // Cast to object: a single-entry (or numerically-named) questions map would otherwise
            // risk being emitted as a JSON array instead of the object the API requires.
            'questions' => (object) $this->questions,
        ];

        if ($this->model !== null) {
            $data['model'] = $this->model;
        }

        // Left out entirely when empty, so that a request without images is byte-for-byte what it
        // was before assets existed. Cast to object for the same reason as the questions above.
        if ($this->assets !== []) {
            $data['assets'] = (object) $this->assets;
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $assets
     *
     * @return array<string, ImageAsset>
     *
     * @throws \InvalidArgumentException
     */
    private static function validatedAssets(array $assets): array
    {
        $validatedAssets = [];
        $totalReferenceBytes = 0;
        foreach ($assets as $reference => $asset) {
            if (!is_string($reference)) {
                throw new \InvalidArgumentException(sprintf(
                    'assets keys must be strings, got the integer %d. PHP turns a numeric-looking string key '
                        . 'into an integer, so use a non-numeric reference such as a UUID.',
                    $reference,
                ));
            }
            if (trim($reference) === '') {
                throw new \InvalidArgumentException('assets keys (references) must not be empty or whitespace-only.');
            }

            $referenceBytes = strlen($reference);
            if ($referenceBytes > Limits::MAX_ASSET_REFERENCE_BYTES) {
                throw new \InvalidArgumentException(sprintf(
                    'assets keys (references) must be at most %d bytes, got %d.',
                    Limits::MAX_ASSET_REFERENCE_BYTES,
                    $referenceBytes,
                ));
            }

            $totalReferenceBytes += $referenceBytes;
            if ($totalReferenceBytes > Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES) {
                throw new \InvalidArgumentException(sprintf(
                    'assets keys (references) must total at most %d bytes.',
                    Limits::MAX_TOTAL_ASSET_REFERENCE_BYTES,
                ));
            }

            if (!$asset instanceof ImageAsset) {
                throw new \InvalidArgumentException(sprintf(
                    'assets values must be instances of %s, got %s.',
                    ImageAsset::class,
                    get_debug_type($asset),
                ));
            }

            $validatedAssets[$reference] = $asset;
        }

        return $validatedAssets;
    }

    /**
     * Throws unless every reference in $assets occurs inside a string value of $state or of the
     * serialized $questions. A reference that occurs only as an object key does not count, since
     * the API never replaces object keys, and an asset that nothing refers to is rejected by the
     * API with a 400.
     *
     * This is a plain substring check over every string value, including the `type` of each
     * question, so it is a fast local guard rather than a copy of how the API matches references:
     * the API remains the authority, for example on references that overlap each other.
     *
     * @param array<string, ImageAsset> $assets
     * @param array<array-key, QuestionInterface> $questions
     *
     * @throws \InvalidArgumentException
     */
    private static function assertEveryAssetIsReferenced(array $assets, mixed $state, array $questions): void
    {
        // Encode and decode again to see the content the way the API receives it, whatever $state
        // is made of (an object, a JsonSerializable...), with the questions already serialized.
        try {
            $content = (array) json_decode(
                json_encode(['state' => $state, 'questions' => (object) $questions], JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(
                'state and questions must be encodable as JSON when assets are given: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        $strings = iterator_to_array(self::stringValues($content), false);

        foreach (array_keys($assets) as $reference) {
            foreach ($strings as $string) {
                if (str_contains($string, $reference)) {
                    continue 2;
                }
            }

            throw new \InvalidArgumentException(sprintf(
                'asset reference "%s" does not appear in any string value of state or questions; every asset '
                    . 'must be referenced at least once, and a reference in an object key is not replaced.',
                $reference,
            ));
        }
    }

    /**
     * Every string found in $value at any depth, skipping array keys: only values are searched
     * for references.
     *
     * @return \Generator<int, string>
     */
    private static function stringValues(mixed $value): \Generator
    {
        if (is_string($value)) {
            yield $value;
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                yield from self::stringValues($item);
            }
        }
    }
}
