<?php

declare(strict_types=1);

namespace TypesafeAi\Request\Question;

/**
 * A question that locates what its instructions describe in the request's image, answered with a
 * box around it.
 *
 * Mirrors the official SDK (0.7.5)'s `Box`. The image is the request's `state`, or an image placed
 * in it; see {@see \TypesafeAi\Request\Image}. The API answers a box question only for a model that
 * can see images (Jev 1.14 or newer, at the time of writing), and with the images header described
 * on that class. The answer is a {@see \TypesafeAi\Response\Answer\BoxAnswer}.
 */
final readonly class BoxQuestion implements QuestionInterface
{
    /**
     * Like the other questions, a box question takes JSON content rather than only text: a
     * string, a list or map of values (nested to any depth), or an object that encodes as JSON,
     * such as a {@see \JsonSerializable} or an {@see \TypesafeAi\Request\Image}. A string must not
     * be empty; an array or an object is sent as it is, and the API decides whether it makes
     * sense.
     *
     * @param string|array<array-key, mixed>|object|null $instructions What to locate in the image,
     *     for example "The dog". Omitted from the request when null.
     */
    public function __construct(
        private string|array|object|null $instructions = null,
    ) {
        if (is_string($instructions) && trim($instructions) === '') {
            throw new \InvalidArgumentException('instructions must not be empty.');
        }
    }

    public function type(): string
    {
        return 'box';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = ['type' => $this->type()];

        if ($this->instructions !== null) {
            $data['instructions'] = $this->instructions;
        }

        return $data;
    }
}
