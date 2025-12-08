<?php

namespace chrfickinger\Simplify\Helpers;

use Kirby\Cms\App as Kirby;

/**
 * Path Helper Class
 *
 * Provides centralized path management for Simplify config files.
 * Allows users to configure custom storage location for configs.
 */
class PathHelper
{
    /**
     * Get the base config path for Simplify
     *
     * Can be customized via 'chrfickinger.simplify.config' option.
     * Default: site/config/simplify
     * For content repos: site/content/.simplify
     *
     * @param string $subPath Optional sub-path to append
     * @return string Full path
     */
    public static function getConfigPath(string $subPath = ''): string
    {
        $kirby = Kirby::instance();

        // Get custom config path or use default
        $basePath = option('chrfickinger.simplify.config');

        if (!$basePath) {
            // Default: site/config/simplify
            $basePath = $kirby->root('config') . '/simplify';
        }

        // Remove trailing slash if present
        $basePath = rtrim($basePath, '/');

        // Append sub-path if provided
        return $subPath ? $basePath . '/' . $subPath : $basePath;
    }

    /**
     * Ensure the config directory exists
     *
     * @param string $path Optional specific path to create, otherwise creates base path
     * @return bool True if directory exists or was created successfully
     */
    public static function ensureConfigDirectory(string $path = ''): bool
    {
        $targetPath = $path ?: self::getConfigPath();

        if (!is_dir($targetPath)) {
            return mkdir($targetPath, 0755, true);
        }

        return true;
    }
}
