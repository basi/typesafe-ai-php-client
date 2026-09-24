<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * Builds the appropriate {@see ApiException} subclass from an HTTP error response.
 */
final class ApiExceptionFactory
{
    /**
     * @param array<string, string> $headers Response headers, keyed by lower-case header name.
     */
    public static function fromResponse(int $statusCode, string $body, array $headers, ?string $requestId): ApiException
    {
        [$errorType, $detailMessage, $validationErrors] = self::parseDetail(self::decode($body));
        $retryAfterMs = self::parseRetryAfterMs($headers);
        $reason = $detailMessage ?? $errorType ?? self::defaultReason($statusCode);
        $message = sprintf('typesafe.ai API error %d: %s', $statusCode, $reason);

        return match (true) {
            $statusCode === 401, $statusCode === 403 => new AuthenticationException(
                $message,
                $statusCode,
                $requestId,
                $errorType,
                $body,
            ),
            $statusCode === 404 => new NotFoundException($message, $statusCode, $requestId, $errorType, $body),
            $statusCode === 422 => new ValidationException(
                $message,
                $statusCode,
                $validationErrors,
                $requestId,
                $errorType,
                $body,
            ),
            $statusCode === 429 => new RateLimitException(
                $message,
                $statusCode,
                $retryAfterMs,
                $requestId,
                $errorType,
                $body,
            ),
            $statusCode >= 500 && $statusCode <= 599 => new ServerException(
                $message,
                $statusCode,
                $retryAfterMs,
                $requestId,
                $errorType,
                $body,
            ),
            default => new ApiException($message, $statusCode, $requestId, $errorType, $body),
        };
    }

    private static function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // The body is not JSON (for example a plain-text or HTML error page from a proxy in
            // front of the API). Fall back to the generic per-status-code message.
            return null;
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: list<array{loc: list<string|int>, msg: string, type: string}>}
     */
    private static function parseDetail(mixed $decoded): array
    {
        $errorType = null;
        $detailMessage = null;
        $validationErrors = [];

        if (!is_array($decoded) || !array_key_exists('detail', $decoded)) {
            return [$errorType, $detailMessage, $validationErrors];
        }

        $detail = $decoded['detail'];

        if (is_string($detail)) {
            $detailMessage = $detail;
        } elseif (is_array($detail) && array_is_list($detail)) {
            foreach ($detail as $entry) {
                $parsed = self::parseValidationError($entry);
                if ($parsed !== null) {
                    $validationErrors[] = $parsed;
                }
            }
            if ($validationErrors !== []) {
                $detailMessage = $validationErrors[0]['msg'];
            }
        } elseif (is_array($detail)) {
            if (is_string($detail['error_type'] ?? null)) {
                $errorType = $detail['error_type'];
            }
            if (is_string($detail['message'] ?? null)) {
                $detailMessage = $detail['message'];
            }
        }

        return [$errorType, $detailMessage, $validationErrors];
    }

    /**
     * @return array{loc: list<string|int>, msg: string, type: string}|null
     */
    private static function parseValidationError(mixed $entry): ?array
    {
        if (
            !is_array($entry)
            || !is_array($entry['loc'] ?? null)
            || !is_string($entry['msg'] ?? null)
            || !is_string($entry['type'] ?? null)
        ) {
            return null;
        }

        $loc = [];
        foreach ($entry['loc'] as $segment) {
            if (is_string($segment) || is_int($segment)) {
                $loc[] = $segment;
            }
        }

        return ['loc' => $loc, 'msg' => $entry['msg'], 'type' => $entry['type']];
    }

    /**
     * @param array<string, string> $headers Lower-case header names.
     */
    private static function parseRetryAfterMs(array $headers): ?int
    {
        $retryAfterMsHeader = isset($headers['retry-after-ms']) ? trim($headers['retry-after-ms']) : null;
        if ($retryAfterMsHeader !== null && $retryAfterMsHeader !== '' && ctype_digit($retryAfterMsHeader)) {
            return (int) $retryAfterMsHeader;
        }

        $retryAfterHeader = isset($headers['retry-after']) ? trim($headers['retry-after']) : null;
        if ($retryAfterHeader === null || $retryAfterHeader === '') {
            return null;
        }

        if (ctype_digit($retryAfterHeader)) {
            return ((int) $retryAfterHeader) * 1000;
        }

        $target = strtotime($retryAfterHeader);
        if ($target === false) {
            return null;
        }

        return max(0, ($target - time()) * 1000);
    }

    private static function defaultReason(int $statusCode): string
    {
        return match (true) {
            $statusCode === 401, $statusCode === 403 => 'authentication failed',
            $statusCode === 404 => 'not found',
            $statusCode === 422 => 'validation failed',
            $statusCode === 429 => 'rate limited',
            $statusCode >= 500 => 'server error',
            default => 'request failed',
        };
    }
}
