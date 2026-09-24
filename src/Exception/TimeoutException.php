<?php

declare(strict_types=1);

namespace TypesafeAi\Exception;

/**
 * The request did not complete before the configured timeout elapsed.
 */
final class TimeoutException extends TransportException
{
}
