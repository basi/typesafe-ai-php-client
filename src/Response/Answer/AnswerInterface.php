<?php

declare(strict_types=1);

namespace TypesafeAi\Response\Answer;

/**
 * A single answer to a request question.
 */
interface AnswerInterface
{
    /**
     * The answer type discriminator: "noul", "choice", "score", or, for a type this client
     * version does not recognise, whatever string the server sent.
     */
    public function type(): string;

    /**
     * A normalised representation of this answer, including `confidence` whenever the server sent
     * one.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array;

    /**
     * This answer exactly as decoded from the response body, including any field this client does
     * not otherwise expose. Nothing the server sends is ever dropped.
     *
     * @return array<array-key, mixed>
     */
    public function raw(): array;
}
