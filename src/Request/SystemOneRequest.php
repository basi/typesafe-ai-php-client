<?php

declare(strict_types=1);

namespace TypesafeAi\Request;

use TypesafeAi\Http\Headers;
use TypesafeAi\Request\Question\QuestionInterface;

/**
 * A request to POST /v1/systemone: content to evaluate together with one or more named questions.
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
     * @var array<string, Image>
     */
    private readonly array $assets;

    /**
     * @var array<string, string>
     */
    private readonly array $extraHeaders;

    /**
     * $state, $questions, $assets and $extraHeaders are validated at runtime rather than typed
     * narrowly, so that validation does not appear dead to static analysis: $state must be a
     * string, array, or object (native `mixed` lets a caller pass anything, including the int/null
     * this constructor rejects); $questions' values must implement QuestionInterface, and PHP
     * normalises a numeric-looking string key (e.g. "1") to an integer, so a single-question map
     * keyed "1" is legitimate and its key genuinely is an int by the time this constructor sees
     * it. The names in $assets are the exception: an integer key is rejected there.
     *
     * @param mixed $state Content all questions in this request refer to. An {@see Image} anywhere
     *     in it, or in a question, is uploaded with the request.
     * @param array<array-key, mixed> $questions Non-empty map of question name to question. The
     *     response uses these names to identify the answers.
     * @param ?string $model Model name or alias. Omitted from the request payload when null; a
     *     client sending this request is expected to fill in its own default in that case.
     * @param array<array-key, mixed> $assets Images that have a name of your choosing, by name.
     *     Mirrors the official SDK (0.7.5)'s `assets`. An image named here is written as its name
     *     wherever it is placed in the request, and uploaded once; write the name inside a string,
     *     such as `"Compare <photo> with the listing."`, and the API replaces it with the image.
     *     A name is a non-empty string that PHP keeps as a string: a name such as `"1"` becomes an
     *     integer array key, which cannot be told from a position in a list, so an integer key is
     *     rejected — and with it a list such as `[$photo]`, which would otherwise name the image
     *     `"0"`, a name that the API then replaces wherever a `0` appears in the text. Every image
     *     named here is uploaded, whether anything refers to it or not (the API answers 400 if
     *     nothing does), in the order given and before the images that are only placed in the
     *     content. An image given several names is uploaded once for each, and is written as the
     *     last of them where it is placed.
     * @param array<array-key, mixed> $extraHeaders Header name to header value, added to this
     *     request only; they are not part of the request payload. Mirrors the official SDK
     *     (0.7.5)'s per-call `extra_headers`. A header here replaces one of the same name in
     *     {@see \TypesafeAi\ClientOptions::$headers} (names are compared without regard to case),
     *     while the headers the client always sets — see that option — replace it in turn. Each
     *     name must be an HTTP token and each value a string without line breaks or other control
     *     characters, or this constructor throws an `\InvalidArgumentException`.
     */
    public function __construct(
        mixed $state,
        array $questions,
        ?string $model = null,
        array $assets = [],
        array $extraHeaders = [],
    ) {
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

        $validatedAssets = [];
        foreach ($assets as $name => $image) {
            // The official SDK rejects a name that is not a non-empty string. PHP turns the string
            // "1" into the integer key 1, and a list has the keys 0, 1, ...: such a key is a mistake
            // far more often than a choice of name, and the API replaces a name like "0" wherever it
            // appears in a text.
            if (is_int($name)) {
                throw new \InvalidArgumentException(
                    'assets keys must be non-empty strings; PHP turns a numeric string such as "1" into an '
                    . 'integer key, so use a name that is not a number, such as "<photo>".',
                );
            }
            if (trim($name) === '') {
                throw new \InvalidArgumentException('assets keys must be non-empty strings.');
            }
            if (!$image instanceof Image) {
                throw new \InvalidArgumentException(sprintf(
                    'assets values must be %s instances, got %s.',
                    Image::class,
                    get_debug_type($image),
                ));
            }
            $validatedAssets[$name] = $image;
        }

        $this->state = $state;
        $this->questions = $validatedQuestions;
        $this->model = $model;
        $this->assets = $validatedAssets;
        $this->extraHeaders = Headers::validate($extraHeaders, 'extraHeaders');
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
     * @return array<string, Image> The named images, by name, in the order given.
     */
    public function assets(): array
    {
        return $this->assets;
    }

    /**
     * @return array<string, string> Headers for this request only, validated at construction.
     */
    public function extraHeaders(): array
    {
        return $this->extraHeaders;
    }

    /**
     * A copy of this request that asks for $model, with everything else — the state, the
     * questions, the named images and the headers — unchanged. A client uses it to fill in its
     * default model.
     *
     * @throws \InvalidArgumentException if $model is empty.
     */
    public function withModel(string $model): self
    {
        return new self(
            $this->state,
            $this->questions,
            $model,
            assets: $this->assets,
            extraHeaders: $this->extraHeaders,
        );
    }

    /**
     * The request payload, without its `assets`: the client adds those when it builds the body,
     * since it has to read the images to do so. Encoded on its own, a named image is written as
     * its reference, not as its name.
     *
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

        return $data;
    }
}
