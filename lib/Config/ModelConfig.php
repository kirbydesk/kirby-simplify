<?php

namespace chrfickinger\Simplify\Config;

use Kirby\Cms\App as Kirby;

/**
 * ModelConfig
 *
 * Manages model configuration files in /site/config/simplify/{provider}/{model}.json
 *
 * Model configs contain:
 * - provider_type and model name
 * - API-fetched data (pricing, supports, defaults)
 * - Community status and quality
 * - User overrides (e.g. custom temperature)
 */
class ModelConfig
{
    /**
     * Get the models config directory path
     */
    private static function getConfigDir(): string
    {
        return \chrfickinger\Simplify\Helpers\PathHelper::getConfigPath();
    }

    /**
     * Build config ID from provider and model
     *
     * @param string $providerType Provider type (openai, anthropic, gemini)
     * @param string $model Model name (gpt-4o-mini, claude-3-5-haiku, etc.)
     * @return string Config ID (openai/gpt-4o-mini)
     */
    public static function buildId(string $providerType, string $model): string
    {
        return $providerType . '/' . $model;
    }

    /**
     * Get config file path
     *
     * @param string $configId Config ID (openai/gpt-4o-mini)
     * @return string Full path to config file
     */
    private static function getConfigPath(string $configId): string
    {
        return self::getConfigDir() . '/' . $configId . '.json';
    }

    /**
     * Check if model config exists
     *
     * @param string $configId Config ID (openai/gpt-4o-mini)
     * @return bool
     */
    public static function exists(string $configId): bool
    {
        return file_exists(self::getConfigPath($configId));
    }

    /**
     * Load model config
     *
     * @param string $configId Config ID (openai/gpt-4o-mini)
     * @return array|null Config data or null if not found
     */
    public static function load(string $configId): ?array
    {
        $path = self::getConfigPath($configId);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    /**
     * Save model config
     *
     * @param string $configId Config ID (openai/gpt-4o-mini)
     * @param array $data Config data
     * @param object|null $logger Optional logger instance
     * @return bool Success
     */
    public static function save(string $configId, array $data, $logger = null): bool
    {
        $path = self::getConfigPath($configId);
        $dir = dirname($path);

        // Ensure directory exists (including provider subdirectory)
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = file_put_contents($path, $json) !== false;

        if ($result && $logger) {
            $logger->info("Saved model config: {$configId}");
        }

        return $result;
    }

    /**
     * Delete model config
     *
     * @param string $configId Config ID (openai/gpt-4o-mini)
     * @param object|null $logger Optional logger instance
     * @return bool Success
     */
    public static function delete(string $configId, $logger = null): bool
    {
        $path = self::getConfigPath($configId);

        if (!file_exists($path)) {
            return true; // Already deleted
        }

        $result = unlink($path);

        if ($result && $logger) {
            $logger->info("Deleted model config: {$configId}");
        }

        return $result;
    }

    /**
     * Get all model configs
     *
     * @return array Array of model configs with config_id as key
     */
    public static function getAll(): array
    {
        $dir = self::getConfigDir();
        $configs = [];

        if (!is_dir($dir)) {
            return $configs;
        }

        // Scan provider subdirectories (skip variant configs like de-x-ls.json)
        $items = glob($dir . '/*');

        foreach ($items as $item) {
            // Only process directories (skip .json files at root level which are variant configs)
            if (!is_dir($item)) {
                continue;
            }

            $providerType = basename($item);
            $modelFiles = glob($item . '/*.json');

            foreach ($modelFiles as $file) {
                $modelName = basename($file, '.json');
                $configId = $providerType . '/' . $modelName;

                $data = self::load($configId);

                if ($data && isset($data['provider_type']) && isset($data['model'])) {
                    // Add config_id to the data for frontend use
                    $data['config_id'] = $configId;
                    $configs[$configId] = $data;
                }
            }
        }

        return $configs;
    }

    /**
     * Get models for a specific provider
     *
     * @param string $providerType Provider type (openai, anthropic, gemini)
     * @return array Array of model configs for this provider
     */
    public static function getByProvider(string $providerType): array
    {
        $all = self::getAll();
        $filtered = [];

        foreach ($all as $configId => $config) {
            if ($config['provider_type'] === $providerType) {
                $filtered[$configId] = $config;
            }
        }

        return $filtered;
    }
}
