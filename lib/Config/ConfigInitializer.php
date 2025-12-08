<?php

namespace chrfickinger\Simplify\Config;

use Kirby\Cms\App as Kirby;

class ConfigInitializer
{
    /**
     * Initialize variant config file when a language variant is created
     *
     * Creates a complete "Single Source of Truth" config file in /content/
     * that merges technical field type configs with language-specific instructions
     *
     * @param string $languageCode The Kirby language code (e.g., 'de-x-ls')
     * @return bool True if config was created, false if already exists or error
     */
    public static function initializeVariantConfig(string $languageCode): bool
    {
        $kirby = Kirby::instance();

        // Path to variant config file
        $configPath = \chrfickinger\Simplify\Helpers\PathHelper::getConfigPath($languageCode . '.json');

        // Don't overwrite existing config
        if (file_exists($configPath)) {
            return false;
        }

        // Ensure directory exists
        \chrfickinger\Simplify\Helpers\PathHelper::ensureConfigDirectory(dirname($configPath));

        // Get variant data from language rules
        $variantData = self::getVariantDataFromRules($languageCode);
        if (!$variantData) {
            // Language file might not be ready yet (race condition during creation)
            // Panel will create config on first access
            return false;
        }

        // Build complete config with merged field type instructions
        $config = self::buildCompleteConfig($languageCode, $variantData);

        // Write config file
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents($configPath, $json) !== false;
    }

    /**
     * Get variant data from language rules file
     *
     * @param string $languageCode The Kirby language code
     * @return array|null Variant configuration from rules, or null
     */
    private static function getVariantDataFromRules(string $languageCode): ?array
    {
        $kirby = Kirby::instance();

        // Read variant code from language file
        $langFile = $kirby->root('languages') . '/' . $languageCode . '.php';
        if (!file_exists($langFile)) {
            return null;
        }

        $langData = include $langFile;
        $variantCode = $langData['variant'] ?? null;
        $sourceCode = $langData['source'] ?? null;

        if (!$variantCode || !$sourceCode) {
            return null;
        }

        // Load rule file by source language code (e.g., 'de', 'en', 'fr')
        $ruleFile = dirname(__DIR__, 2) . '/rules/variants/' . $sourceCode . '.json';
        if (!file_exists($ruleFile)) {
            return null;
        }

        $variants = json_decode(file_get_contents($ruleFile), true);
        if (!is_array($variants)) {
            return null;
        }

        // Find variant by variant_code (e.g., 'ls', 'es')
        foreach ($variants as $variant) {
            if (($variant['variant_code'] ?? null) === $variantCode) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Build complete config by merging technical field type configs with variant data
     *
     * @param string $languageCode The Kirby language code
     * @param array $variantData Variant data from rules
     * @return array Complete configuration array
     */
    private static function buildCompleteConfig(string $languageCode, array $variantData): array
    {
        $kirby = Kirby::instance();

        // Load technical field type configurations
        $fieldTypeInstructions = self::loadFieldTypeConfigs();

        // Merge with field_instructions from variant data
        if (isset($variantData['field_instructions']) && is_array($variantData['field_instructions'])) {
            foreach ($variantData['field_instructions'] as $fieldType => $instruction) {
                if (isset($fieldTypeInstructions[$fieldType])) {
                    $fieldTypeInstructions[$fieldType]['instruction'] = $instruction;
                }
            }
        }

        // Get all existing pages and initialize with 'auto' mode
        // Use index(true) to include drafts
        $pages = [];
        foreach ($kirby->site()->index(true) as $page) {
            $uuid = $page->uuid();
            if ($uuid) {
                $pages[] = [
                    'uuid' => $uuid->toString(),
                    'mode' => 'auto'
                ];
            }
        }

        // Build complete config structure
        $config = [
            // Language metadata
            'language_code' => $languageCode,
            'variant_code' => $variantData['variant_code'] ?? null,
            'variant_name' => $variantData['variant_name'] ?? '',
            'source_language' => $variantData['source_language'] ?? '',

            // Translation control
            'enabled' => true, // Enable/disable automatic translations for this variant

            // AI model settings (provider is set manually via Panel)
            'provider' => null, // Will be set by user in Panel
            'temperature' => $variantData['temperature'] ?? null,
            'ai_system_prompt' => $variantData['ai_system_prompt'] ?? '',

            // Filtering
            'opt_out_templates' => [], // Templates to exclude from translation
            'opt_out_fields' => $variantData['privacy']['opt_out_fields'] ?? $variantData['defaults']['opt_out_fields'] ?? [], // Field names to exclude (supports wildcards)
            'opt_out_fieldtypes' => [], // Field types to exclude (empty = all enabled by default)

            // Privacy (masking before API call)
            // Support both new structure (privacy.masking) and old structure (defaults)
            'mask_emails' => $variantData['privacy']['masking']['mask_emails'] ?? $variantData['defaults']['mask_emails'] ?? true,
            'mask_phones' => $variantData['privacy']['masking']['mask_phones'] ?? $variantData['defaults']['mask_phones'] ?? true,

            // Field type categories for prompt strategy
            'field_type_categories' => $variantData['defaults']['field_type_categories'] ?? [
                'strict' => ['text'],
                'structured' => ['blocks', 'layout', 'structure', 'object'],
                'elaborate' => ['textarea', 'list', 'writer']
            ],

            // Category-specific prompts for field types
            'category_prompts' => $variantData['defaults']['category_prompts'] ?? [],

            // Field type configurations with AI instructions
            'field_type_instructions' => $fieldTypeInstructions,

            // Per-page translation modes
            'pages' => $pages
        ];

        return $config;
    }

    /**
     * Load technical field type configurations from rules/fieldtypes/*.json
     *
     * @return array Array of field type configurations
     */
    private static function loadFieldTypeConfigs(): array
    {
        $fieldTypes = [];
        $fieldTypesPath = dirname(__DIR__, 2) . '/rules/fieldtypes';

        if (!is_dir($fieldTypesPath)) {
            return $fieldTypes;
        }

        $files = scandir($fieldTypesPath);
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                $fieldType = pathinfo($file, PATHINFO_FILENAME);
                $filePath = $fieldTypesPath . '/' . $file;

                $content = file_get_contents($filePath);
                $data = json_decode($content, true);

                if (is_array($data)) {
                    $fieldTypes[$fieldType] = $data;
                }
            }
        }

        return $fieldTypes;
    }

    /**
     * Check if a variant config file exists
     *
     * @param string $languageCode The Kirby language code
     * @return bool True if config exists
     */
    public static function configExists(string $languageCode): bool
    {
        $configPath = \chrfickinger\Simplify\Helpers\PathHelper::getConfigPath($languageCode . '.json');
        return file_exists($configPath);
    }
}
