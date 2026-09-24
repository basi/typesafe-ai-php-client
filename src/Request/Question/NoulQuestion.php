<?php

declare(strict_types=1);

namespace TypesafeAi\Request\Question;

/**
 * A yes/no question or statement, answered with the probability of a "yes" or "true" answer.
 */
final readonly class NoulQuestion implements QuestionInterface
{
    /**
     * @param string $instructions The yes/no question or statement to evaluate.
     * @param ?string $whenTrue What counts as a "yes" answer. Omitted from the request when null.
     * @param ?string $whenFalse What counts as a "no" answer. Omitted from the request when null.
     */
    public function __construct(
        private string $instructions,
        private ?string $whenTrue = null,
        private ?string $whenFalse = null,
    ) {
        if (trim($instructions) === '') {
            throw new \InvalidArgumentException('instructions must not be empty.');
        }
    }

    public function type(): string
    {
        return 'noul';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type(),
            'instructions' => $this->instructions,
        ];

        $criteria = [];
        if ($this->whenTrue !== null) {
            $criteria['true'] = $this->whenTrue;
        }
        if ($this->whenFalse !== null) {
            $criteria['false'] = $this->whenFalse;
        }

        if ($criteria !== []) {
            // Cast to object: json_encode() would otherwise emit a numerically-indexed criteria
            // array as a JSON array instead of an object when only "false" is set (a re-indexed
            // single-element list), and the API always expects criteria to be an object.
            $data['criteria'] = (object) $criteria;
        }

        return $data;
    }
}
