<?php

declare(strict_types=1);

namespace TypesafeAi\Tests\Support;

/**
 * Gives a test case a scratch directory that is removed after each test, files with a revoked
 * permission included.
 */
trait UsesTemporaryDirectory
{
    private ?string $temporaryDirectory = null;

    /**
     * The path of $name inside the test's scratch directory. Nothing is created at that path.
     */
    private function temporaryPath(string $name): string
    {
        if ($this->temporaryDirectory === null) {
            $directory = sys_get_temp_dir() . '/typesafe-ai-test-' . bin2hex(random_bytes(6));
            if (!mkdir($directory, 0700)) {
                self::fail("Could not create the scratch directory {$directory}.");
            }
            $this->temporaryDirectory = $directory;
        }

        return $this->temporaryDirectory . '/' . $name;
    }

    /**
     * Creates the file $name in the scratch directory and returns its path.
     */
    private function temporaryFile(string $name, string $contents): string
    {
        $path = $this->temporaryPath($name);
        if (file_put_contents($path, $contents) === false) {
            self::fail("Could not write {$path}.");
        }

        return $path;
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== null) {
            self::removeTree($this->temporaryDirectory);
            $this->temporaryDirectory = null;
        }

        parent::tearDown();
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0700);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }

        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
