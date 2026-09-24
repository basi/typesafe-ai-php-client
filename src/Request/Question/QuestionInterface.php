<?php

declare(strict_types=1);

namespace TypesafeAi\Request\Question;

/**
 * A single question to ask about a request's state.
 */
interface QuestionInterface extends \JsonSerializable
{
    /**
     * The question type discriminator: "noul", "choice", or "score".
     */
    public function type(): string;
}
