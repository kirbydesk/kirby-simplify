<?php

namespace kirbydesk\Simplify\Helpers;

use Kirby\Http\Remote;

/**
 * ModelQualityChecker
 *
 * Checks AI model quality and compatibility by fetching data from GitHub.
 * Provides community ratings and status for AI models.
 */
class ModelQualityChecker
{
    /**
     * GitHub raw content URL for model data
     */
    private const GITHUB_BASE_URL = 'https://raw.githubusercontent.com/kirbydesk/kirby-simplify-models/main';

    /**
     * Check model quality from GitHub data
     *
     * @param string $providerType Provider type (openai, anthropic, google, mistral, deepl)
     * @param string $model Model name (gpt-4o-mini, claude-3-5-sonnet-20241022, etc.)
     * @return array|null Quality check result or null on error
     */
    public static function check(string $providerType, string $model): ?array
    {
        try {
            // Get all models for this provider (uses cache if available)
            $data = self::checkAllModels($providerType);

            if ($data === null || !isset($data['models'])) {
                return null;
            }

            // Find the specific model
            if (!isset($data['models'][$model])) {
                return null;
            }

            return $data['models'][$model];

        } catch (\Exception $e) {
            // Silently fail - model check is non-critical
            return null;
        }
    }

    /**
     * Get all models status for a provider from GitHub
     *
     * @param string $providerType Provider type (openai, anthropic, google, mistral, deepl)
     * @return array|null Full response with provider info and models, or null on error
     *                   Format: ['provider' => [...], 'models' => ['gpt-4o' => [...], ...]]
     */
    public static function checkAllModels(string $providerType): ?array
    {
        $logger = $GLOBALS['simplify_instances']['api_logger'] ?? null;

        try {
            // Check cache first
            $cached = self::getCachedModels($providerType);
            if ($cached !== null) {
                if ($logger) {
                    $logger->info("Retrieved models for {$providerType} from cache");
                }
                return $cached;
            }

            // Build GitHub URL
            $url = self::GITHUB_BASE_URL . '/' . $providerType . '.json';

            // Make request to GitHub
            $response = Remote::get($url, [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);

            // Check if request was successful
            if ($response->code() !== 200) {
                if ($logger) {
                    $logger->error("GitHub fetch for {$url} returned HTTP {$response->code()}");
                }
                return null;
            }

            // Parse JSON response
            $data = $response->json();

            // Validate response structure
            if (!isset($data['provider']) || !isset($data['models'])) {
                if ($logger) {
                    $logger->error("Invalid JSON structure from GitHub for {$providerType}");
                }
                return null;
            }

            // Cache the result
            self::cacheModels($providerType, $data);

            if ($logger) {
                $logger->info("Retrieved models for {$providerType} from GitHub");
            }

            return $data;

        } catch (\Exception $e) {
            if ($logger) {
                $logger->error("Exception in checkAllModels for {$providerType}: " . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Get cached model data
     *
     * @param string $providerType
     * @return array|null
     */
    private static function getCachedModels(string $providerType): ?array
    {
        try {
            $kirby = \Kirby\Cms\App::instance();
            $cacheDir = $kirby->root('cache') . '/simplify/models';
            $cacheFile = $cacheDir . '/' . $providerType . '.json';

            if (!file_exists($cacheFile)) {
                return null;
            }

            // Check if cache is older than 24 hours
            $fileTime = filemtime($cacheFile);
            if (time() - $fileTime > 86400) { // 24 hours in seconds
                return null;
            }

            $content = file_get_contents($cacheFile);
            if ($content === false) {
                return null;
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Cache model data
     *
     * @param string $providerType
     * @param array $data
     * @return void
     */
    private static function cacheModels(string $providerType, array $data): void
    {
        try {
            $kirby = \Kirby\Cms\App::instance();
            $cacheDir = $kirby->root('cache') . '/simplify/models';

            // Create cache directory if it doesn't exist
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            $cacheFile = $cacheDir . '/' . $providerType . '.json';
            file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            // Silently fail - caching is not critical
        }
    }


}
