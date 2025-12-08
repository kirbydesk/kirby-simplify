<?php

namespace chrfickinger\Simplify\Helpers;

use chrfickinger\Simplify\Providers\Anthropic;
use chrfickinger\Simplify\Providers\Gemini;
use chrfickinger\Simplify\Providers\Mistral;
use chrfickinger\Simplify\Providers\OpenAI;

/**
 * Provider Factory Class
 *
 * Creates and configures AI provider instances from configuration.
 */
class ProviderFactory
{
    /**
     * Create a provider instance from provider ID
     *
     * @param string $providerId Provider identifier
     * @param array $globalConfig Global configuration
     * @return object Provider instance
     * @throws \Exception If provider not found or invalid
     */
    public static function create(string $providerId, array $globalConfig)
    {
        if (!isset($globalConfig['providers'][$providerId])) {
            throw new \Exception("Provider not found: {$providerId}");
        }

        $providerConfig = $globalConfig['providers'][$providerId];

        // Use explicit 'type' field from config, fallback to detection based on ID
        $providerType = $providerConfig['type'] ?? self::detectProviderType($providerId);

        $instanceConfig = [
            'apiKey' => $providerConfig['apikey'] ?? '',
            'timeout' => $globalConfig['timeout'] ?? 120,
            'connectTimeout' => $globalConfig['connectTimeout'] ?? 10,
            'retries' => $globalConfig['retries'] ?? 3,
        ];

        if (isset($providerConfig['endpoint'])) {
            $instanceConfig['endpoint'] = $providerConfig['endpoint'];
        }

        return self::instantiateProvider($providerType, $instanceConfig);
    }

    /**
     * Detect provider type from ID
     *
     * @param string $providerId
     * @return string Provider type (gemini, openai, anthropic)
     */
    private static function detectProviderType(string $providerId): string
    {
        if (strpos($providerId, 'gemini') !== false) {
            return 'gemini';
        }

        if (strpos($providerId, 'claude') !== false || strpos($providerId, 'anthropic') !== false) {
            return 'anthropic';
        }

        if (strpos($providerId, 'openai') !== false) {
            return 'openai';
        }

        return 'openai'; // Default
    }

    /**
     * Instantiate the provider class
     *
     * @param string $type Provider type
     * @param array $config Instance configuration
     * @return object Provider instance
     */
    private static function instantiateProvider(string $type, array $config)
    {
        switch ($type) {
            case 'gemini':
                return new Gemini($config);

            case 'anthropic':
                return new Anthropic($config);

            case 'mistral':
                return new Mistral($config);

            case 'openai':
            default:
                return new OpenAI($config);
        }
    }

    /**
     * Get provider model from config
     *
     * @param string $providerId
     * @param array $globalConfig
     * @return string|null
     */
    public static function getModel(string $providerId, array $globalConfig): ?string
    {
        return $globalConfig['providers'][$providerId]['model'] ?? null;
    }

    /**
     * Create provider by model config ID
     * Loads model config to determine provider type
     *
     * @param string $modelConfigId Model config ID (e.g., 'openai/gpt-4o')
     * @param array $globalConfig Global configuration
     * @return object|null Provider instance or null if not found
     */
    public static function createByModel(string $modelConfigId, array $globalConfig): ?object
    {
        // Load model config to get provider type
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
        if (!$modelConfig) {
            return null;
        }

        $providerType = $modelConfig['provider_type'] ?? null;
        if (!$providerType) {
            return null;
        }

        // Create provider using the provider type
        return self::create($providerType, $globalConfig);
    }

    /**
     * Create provider from variant config
     *
     * @param array $variantConfig Variant configuration
     * @return object|null Provider instance or null if not found
     */
    public static function createFromVariantConfig(array $variantConfig): ?object
    {
        $model = $variantConfig['provider'] ?? null;

        if (!$model) {
            return null;
        }

        // Get global config to find provider by model
        $kirby = \Kirby\Cms\App::instance();
        $globalConfig = $kirby->option('chrfickinger.simplify', []);

        return self::createByModel($model, $globalConfig);
    }
}
