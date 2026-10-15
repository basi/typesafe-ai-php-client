<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Support;

/**
 * A stream wrapper that records the paths it is asked to open and refuses them all, to show which
 * paths PHP hands to a registered wrapper instead of reading them as files. Register it under a
 * name of the test's choosing with `stream_wrapper_register()` and unregister it afterwards.
 */
final class RecordingStreamWrapper
{
    /**
     * @var list<string> The paths that PHP asked a registered instance of this class to open.
     */
    public static array $opened = [];

    /**
     * PHP sets this on the wrapper it creates; declared so that it is not a dynamic property.
     *
     * @var resource|null
     */
    public $context;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- PHP's stream wrapper protocol fixes the name
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened[] = $path;

        return false;
    }
}
