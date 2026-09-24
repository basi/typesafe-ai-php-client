<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * The request was rejected because the API key was missing or invalid (HTTP 401 or 403).
 */
final class AuthenticationException extends ApiException
{
}
