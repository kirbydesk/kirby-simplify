<?php

namespace chrfickinger\Simplify\Config;

use Kirby\Cms\App as Kirby;
use chrfickinger\Simplify\Helpers\PathHelper;

/**
 * Config File Manager Class
 *
 * Manages variant configuration JSON files.
 */
class ConfigFileManager
{
    /**
     * Load variant config from JSON file
     *
     * @param string $variantCode
     * @return array Configuration array
     * @throws \Exception If file doesn't exist or is invalid
     */
    public static function loadVariantConfig(string $variantCode): array
    {
        $configPath = PathHelper::getConfigPath($variantCode . '.json');

        if (!file_exists($configPath)) {
            throw new \Exception("Config file does not exist for variant: {$variantCode}");
        }

        $jsonContent = file_get_contents($configPath);
        $config = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Failed to parse JSON: " . json_last_error_msg());
        }

        return $config;
    }

    /**
     * Save variant config to JSON file
     *
     * @param string $variantCode
     * @param array $config Configuration to save
     * @param object|null $logger Optional logger
     * @return int Bytes written
     */
    public static function saveVariantConfig(
        string $variantCode,
        array $config,
        ?object $logger = null
    ): int {
        $configPath = PathHelper::getConfigPath($variantCode . '.json');

        // Ensure directory exists
        PathHelper::ensureConfigDirectory(dirname($configPath));

        $jsonContent = json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $written = file_put_contents($configPath, $jsonContent);

        if ($logger) {
            $logger->info("Wrote {$written} bytes to {$configPath}");
        }

        return $written;
    }

    /**
     * Update variant config with merge
     *
     * @param string $variantCode
     * @param array $updates Updates to merge
     * @param object|null $logger Optional logger
     * @return array Updated config
     */
    public static function updateVariantConfig(
        string $variantCode,
        array $updates,
        ?object $logger = null
    ): array {
        $existing = [];

        try {
            $existing = self::loadVariantConfig($variantCode);
        } catch (\Exception $e) {
            // File doesn't exist yet, start with empty config
        }

        $merged = array_merge($existing, $updates);
        self::saveVariantConfig($variantCode, $merged, $logger);

        return $merged;
    }
}
