<?php

declare(strict_types=1);

namespace TypesafeAi;

/**
 * Limits and defaults enforced by the typesafe.ai System One API for the `jev` model family.
 *
 * These are used for client-side validation so that a request which would be rejected by the
 * server fails fast, locally, with a clear message instead of a round trip ending in a 422.
 */
final class Limits
{
    /**
     * Maximum number of tokens allowed in a single request.
     */
    public const int MAX_TOKENS_PER_REQUEST = 64000;

    /**
     * Maximum combined tokens for the state plus the longest single question.
     */
    public const int MAX_TOKENS_STATE_AND_LONGEST_QUESTION = 32000;

    /**
     * Maximum number of options allowed in a choice question.
     */
    public const int MAX_CHOICE_OPTIONS = 255;

    /**
     * Minimum number of ordered levels allowed in a score question.
     */
    public const int MIN_SCORE_LEVELS = 2;

    /**
     * Maximum number of ordered levels allowed in a score question.
     */
    public const int MAX_SCORE_LEVELS = 10;

    /**
     * Maximum number of requests allowed per minute.
     */
    public const int MAX_REQUESTS_PER_MINUTE = 1200;

    /**
     * Maximum number of tokens processed per second.
     */
    public const int MAX_TOKENS_PER_SECOND = 250000;

    /**
     * Model alias used when a request does not specify one.
     */
    public const string DEFAULT_MODEL = self::MODEL_ALIAS_LATEST;

    /**
     * Alias that resolves to the newest generally available model.
     */
    public const string MODEL_ALIAS_LATEST = 'jev-latest';

    /**
     * Alias that resolves to the newest preview model.
     */
    public const string MODEL_ALIAS_PREVIEW = 'jev-preview';
}
