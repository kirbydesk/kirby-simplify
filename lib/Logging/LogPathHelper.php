<?php

namespace kirbydesk\Simplify\Logging;

use Kirby\Cms\App as Kirby;
use Kirby\Toolkit\Dir;

/**
 * Helper class for log path operations
 */
class LogPathHelper
{
    /**
     * Get the simplify logs directory
     *
     * @return string Path to simplify logs directory
     */
    public static function getSimplifyLogsDir(): string
    {
        $kirby = Kirby::instance();
        return $kirby->root('logs') . '/simplify';
    }

    /**
     * Ensure directory exists for a given file path
     *
     * @param string $path Path to file
     * @return void
     */
    public static function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            Dir::make($dir, true);
        }
    }
}
