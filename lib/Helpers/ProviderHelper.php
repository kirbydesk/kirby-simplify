<?php

namespace kirbydesk\Simplify\Helpers;

/**
 * Provider Helper
 *
 * Central place for provider metadata
 */
class ProviderHelper
{
    private static ?array $config = null;

    /**
     * Load provider config
     *
     * @return array
     */
    private static function getConfig(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../Config/providers.php';
        }
        return self::$config;
    }

    /**
     * Get provider display name
     *
     * @param string $providerId Provider ID (e.g., 'openai', 'anthropic')
     * @return string Display name
     */
    public static function getProviderName(string $providerId): string
    {
        $config = self::getConfig();
        return $config[$providerId]['name'] ?? ucfirst($providerId);
    }

    /**
     * Get provider icon name
     *
     * @param string $providerId Provider ID
     * @return string Icon name
     */
    public static function getProviderIcon(string $providerId): string
    {
        $config = self::getConfig();
        return $config[$providerId]['icon'] ?? 'sparkling';
    }
}
