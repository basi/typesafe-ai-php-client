<?php

declare(strict_types=1);

namespace TypesafeAi;

/**
 * Limits and defaults enforced by the typesafe.ai System One API for the `jev` model family.
 *
 * Most of these are used for client-side validation so that a request which would be rejected by
 * the server fails fast, locally, with a clear message instead of a round trip ending in a 422.
 * The image limits are only exposed, not enforced: this client never decodes an image, so the API
 * stays the authority on them, and a request that breaks one gets an
 * {@see \TypesafeAi\Exception\ApiException} with the HTTP status 400 or 413.
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
     * Maximum size of one asset reference, in bytes of UTF-8 (not characters). A reference is the
     * name an image has in a request, as well as the unique reference every image has. Exposed, not
     * enforced.
     */
    public const int MAX_ASSET_REFERENCE_BYTES = 1024;

    /**
     * Maximum combined size of all the asset references in one request, in bytes of UTF-8. Exposed,
     * not enforced.
     */
    public const int MAX_TOTAL_ASSET_REFERENCE_BYTES = 65536;

    /**
     * Maximum size of one image file, in bytes (16 MiB). Exposed, not enforced: the API rejects a
     * larger image.
     */
    public const int MAX_IMAGE_BYTES = 16777216;

    /**
     * Minimum width and minimum height of an image, in pixels. Exposed, not enforced: the API
     * rejects a smaller image instead of resizing it.
     */
    public const int MIN_IMAGE_SIDE_PIXELS = 256;

    /**
     * Maximum width and maximum height of an image, in pixels. Exposed, not enforced: the API
     * rejects a larger image instead of resizing it.
     */
    public const int MAX_IMAGE_SIDE_PIXELS = 1568;

    /**
     * Minimum total number of pixels (width times height) of an image: the area of a 280 x 280
     * square, so an image of 256 x 256 is too small even though each side is allowed. Exposed, not
     * enforced.
     */
    public const int MIN_IMAGE_PIXELS = 78400;

    /**
     * Maximum total number of pixels (width times height) of an image: the area of a 1568 x 1568
     * square. Exposed, not enforced.
     */
    public const int MAX_IMAGE_PIXELS = 2458624;

    /**
     * Maximum size of a request body, in bytes (32 MiB), including the JSON, the image files and
     * the multipart framing. Compression does not raise it. Exposed, not enforced: the API rejects
     * a larger request.
     */
    public const int MAX_REQUEST_BODY_BYTES = 33554432;

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
