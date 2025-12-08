<?php

namespace kirbydesk\Simplify\Diagnostics;

use Kirby\Cms\App as Kirby;
use kirbydesk\Simplify\Queue\WorkerManager;

/**
 * PHP CLI Checker
 *
 * Performs one-time check of PHP CLI configuration on first plugin load
 */
class PhpCliChecker
{
    private const CACHE_FILE = 'simplify/php-cli-check.json';

    /**
     * Check if PHP CLI check has been performed
     *
     * @return bool
     */
    public static function hasBeenChecked(): bool
    {
        $kirby = Kirby::instance();
        $cacheFile = $kirby->root('cache') . '/' . self::CACHE_FILE;

        return file_exists($cacheFile);
    }

    /**
     * Get cached check result
     *
     * @return array|null
     */
    public static function getCachedResult(): ?array
    {
        $kirby = Kirby::instance();
        $cacheFile = $kirby->root('cache') . '/' . self::CACHE_FILE;

        if (!file_exists($cacheFile)) {
            return null;
        }

        $content = file_get_contents($cacheFile);
        return json_decode($content, true);
    }

    /**
     * Perform PHP CLI check
     *
     * @param object|null $logger Optional logger instance
     * @return array Check result with status and details
     */
    public static function check(?object $logger = null): array
    {
        $kirby = Kirby::instance();
        $phpBinary = WorkerManager::detectPhpBinary();
        $webPhpVersion = PHP_VERSION;
        $isConfigured = WorkerManager::isPhpBinaryConfigured();

        $result = [
            'checked_at' => date('Y-m-d H:i:s'),
            'binary' => $phpBinary,
            'is_configured' => $isConfigured,
            'web_php_version' => $webPhpVersion,
            'cli_php_version' => null,
            'exec_available' => WorkerManager::isExecAvailable(),
            'status' => 'ok',
            'errors' => [],
        ];

        // Check if exec() is available
        if (!$result['exec_available']) {
            $result['status'] = 'error';
            $result['errors'][] = [
                'key' => 'simplify.system.php.error.exec',
                'data' => []
            ];
        }

        // Check PHP CLI binary
        try {
            $output = [];
            $return = 0;

            @exec("{$phpBinary} -v 2>&1", $output, $return);

            if ($return === 0 && !empty($output)) {
                $versionLine = $output[0] ?? '';

                // Extract version number
                if (preg_match('/PHP (\d+\.\d+\.\d+)/', $versionLine, $matches)) {
                    $cliVersion = $matches[1];
                    $result['cli_php_version'] = $cliVersion;
                    $result['cli_php_version_full'] = $versionLine;

                    // Compare major.minor versions (e.g., 8.3.x should match 8.3.y)
                    $webMajorMinor = substr($webPhpVersion, 0, 3); // e.g., "8.3"
                    $cliMajorMinor = substr($cliVersion, 0, 3);

                    if ($webMajorMinor !== $cliMajorMinor) {
                        $result['status'] = 'error';
                        $result['errors'][] = [
                            'key' => 'simplify.system.php.error.version',
                            'data' => ['webVersion' => $webPhpVersion, 'cliVersion' => $cliVersion]
                        ];
                    }
                } else {
                    $result['status'] = 'error';
                    // Use different error message based on whether binary is configured
                    if ($isConfigured) {
                        $result['errors'][] = [
                            'key' => 'simplify.system.php.error.binary',
                            'data' => ['binary' => $phpBinary]
                        ];
                    } else {
                        $result['errors'][] = [
                            'key' => 'simplify.system.php.error.notConfigured',
                            'data' => []
                        ];
                    }
                }
            } else {
                $result['status'] = 'error';

                // Return code 127 = command not found
                if ($return === 127) {
                    $result['errors'][] = [
                        'key' => 'simplify.system.php.error.commandNotFound',
                        'data' => []
                    ];
                } else {
                    // Use different error message based on whether binary is configured
                    if ($isConfigured) {
                        $result['errors'][] = [
                            'key' => 'simplify.system.php.error.binary',
                            'data' => ['binary' => $phpBinary]
                        ];
                    } else {
                        $result['errors'][] = [
                            'key' => 'simplify.system.php.error.notConfigured',
                            'data' => []
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['errors'][] = "PHP CLI check failed: " . $e->getMessage();
        }

        // Only save to cache if status is OK
        // If there are errors, we want to check again next time
        if ($result['status'] === 'ok') {
            self::saveResult($result);
        }

        return $result;
    }

    /**
     * Save check result to cache
     *
     * @param array $result
     * @return bool
     */
    private static function saveResult(array $result): bool
    {
        $kirby = Kirby::instance();
        $cacheDir = $kirby->root('cache') . '/simplify';
        $cacheFile = $kirby->root('cache') . '/' . self::CACHE_FILE;

        // Create cache directory if it doesn't exist
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $json = json_encode($result, JSON_PRETTY_PRINT);
        return file_put_contents($cacheFile, $json) !== false;
    }

    /**
     * Clear cached result (force re-check on next load)
     *
     * @return bool
     */
    public static function clearCache(): bool
    {
        $kirby = Kirby::instance();
        $cacheFile = $kirby->root('cache') . '/' . self::CACHE_FILE;

        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }

        return true;
    }
}
