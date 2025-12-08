<?php

namespace kirbydesk\Simplify\Config;

use Kirby\Cms\App as Kirby;

class ConfigHelper
{
    /**
     * Get the plugin configuration with dynamic languages detection
     *
     * @return array
     */
    public static function getConfig(): array
    {
        $kirby = Kirby::instance();
        $config = $kirby->option("kirbydesk.simplify", []);

        // If languages is a callable, evaluate it
        if (isset($config['languages']) && is_callable($config['languages'])) {
            $config['languages'] = $config['languages']();
        }

        // If languages not set, build it dynamically
        if (!isset($config['languages']) || !is_array($config['languages'])) {
            $config['languages'] = self::detectLanguages();
        }

        return $config;
    }

    /**
     * Dynamically detect language variants from Kirby languages
     *
     * Reads 'source' field from Kirby language files
     *
     * @return array
     */
    private static function detectLanguages(): array
    {
        $kirby = Kirby::instance();

        // Return empty array if not multilang
        if (!$kirby->multilang()) {
            return [];
        }

        $languages = [];

        foreach ($kirby->languages() as $language) {
            $code = $language->code();

            // Only process language variants (with -x- in code)
            if (strpos($code, '-x-') === false) {
                continue;
            }

            // Read source language from language file
            $langFile = $kirby->root('languages') . '/' . $code . '.php';
            if (!file_exists($langFile)) {
                continue;
            }

            $langData = include $langFile;
            if (!is_array($langData) || !isset($langData['source'])) {
                continue;
            }

            $languages[$code] = [
                'source' => $langData['source'],
            ];
        }

        return $languages;
    }

    /**
     * Get the rule file path for a language variant
     *
     * Rule files are named by source language code (e.g., 'de.json', 'en.json', 'is_IS.json')
     *
     * @param string $languageCode The Kirby language code (e.g., 'de-x-ls')
     * @return string|null The rule file path, or null if not found
     */
    public static function getRuleFileForVariant(string $languageCode): ?string
    {
        $kirby = Kirby::instance();

        // Get source language from variant language file
        $variantLangFile = $kirby->root('languages') . '/' . $languageCode . '.php';
        if (!file_exists($variantLangFile)) {
            return null;
        }

        $variantData = include $variantLangFile;
        $sourceCode = $variantData['source'] ?? null;
        if (!$sourceCode) {
            return null;
        }

        // Rule file is named by source language code (e.g., 'de.json', 'en.json', 'is_IS.json')
        $ruleFile = dirname(__DIR__, 2) . '/rules/variants/' . $sourceCode . '.json';

        return file_exists($ruleFile) ? $ruleFile : null;
    }

    /**
     * Get the variant configuration from the variant config file
     *
     * This is the "Single Source of Truth" - reads complete config from /site/config/simplify/{languageCode}.json
     * The file is created by ConfigInitializer when the variant is first created.
     *
     * @param string $languageCode The Kirby language code (e.g., 'de-x-ls')
     * @return array|null The variant configuration, or null if not found
     */
    public static function getVariantConfig(string $languageCode): ?array
    {
        $kirby = Kirby::instance();

        // Read variant config file (Single Source of Truth)
        $configPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($languageCode . '.json');

        if (!file_exists($configPath)) {
            // Config doesn't exist yet - trigger initialization
            ConfigInitializer::initializeVariantConfig($languageCode);

            // Check again
            if (!file_exists($configPath)) {
                return null;
            }
        }

        $jsonContent = file_get_contents($configPath);
        $config = json_decode($jsonContent, true);

        return is_array($config) ? $config : null;
    }

    /**
     * Get all available variants for a source language locale
     *
     * @param string $sourceLanguageCode The source language code (e.g., 'de', 'en')
     * @return array Array of variant configurations
     */
    public static function getAvailableVariantsForSource(string $sourceLanguageCode): array
    {
        // Load rule file by source language code (e.g., 'de', 'en', 'fr')
        $ruleFile = dirname(__DIR__, 2) . '/rules/variants/' . $sourceLanguageCode . '.json';
        if (!file_exists($ruleFile)) {
            return [];
        }

        $variants = json_decode(file_get_contents($ruleFile), true);
        return is_array($variants) ? $variants : [];
    }

    /**
     * Get rule data for a specific variant
     *
     * Returns the rule data (defaults) for a variant from rules/variants/*.json
     * This contains defaults like ai_system_prompt, post_processing defaults, privacy defaults, etc.
     *
     * @param string $languageCode The variant language code (e.g., 'de-x-ls')
     * @return array|null The rule data for this variant, or null if not found
     */
    public static function getRuleDataForVariant(string $languageCode): ?array
    {
        $kirby = Kirby::instance();

        // Get variant_code from language file
        $variantLangFile = $kirby->root('languages') . '/' . $languageCode . '.php';
        if (!file_exists($variantLangFile)) {
            return null;
        }

        $variantData = include $variantLangFile;
        $variantCode = $variantData['variant'] ?? null;
        $sourceCode = $variantData['source'] ?? null;

        if (!$variantCode || !$sourceCode) {
            return null;
        }

        // Load rule file by source language code (e.g., 'de.json', 'en.json', 'is_IS.json')
        $ruleFile = dirname(__DIR__, 2) . '/rules/variants/' . $sourceCode . '.json';
        if (!file_exists($ruleFile)) {
            return null;
        }

        $allVariants = json_decode(file_get_contents($ruleFile), true);
        if (!is_array($allVariants)) {
            return null;
        }

        // Find the matching variant by variant_code
        foreach ($allVariants as $variant) {
            if (isset($variant['variant_code']) && $variant['variant_code'] === $variantCode) {
                // Transform defaults structure to new flat structure for Frontend
                $ruleData = $variant;

                // Load field_type_instructions from rules/fieldtypes/*.json
                // These are the DEFAULTS only - user edits come from variantConfig
                $fieldTypesPath = dirname(__DIR__, 2) . '/rules/fieldtypes/';
                $fieldTypeInstructions = [];

                if (is_dir($fieldTypesPath)) {
                    foreach (glob($fieldTypesPath . '*.json') as $fieldTypeFile) {
                        $fieldTypeName = basename($fieldTypeFile, '.json');
                        $fieldTypeData = json_decode(file_get_contents($fieldTypeFile), true);

                        if (is_array($fieldTypeData)) {
                            // Use default instruction from variant rules (NOT user edits)
                            $instruction = $variant['field_instructions'][$fieldTypeName] ?? $fieldTypeData['instruction'] ?? '';

                            $fieldTypeInstructions[$fieldTypeName] = array_merge(
                                $fieldTypeData,
                                ['instruction' => $instruction]
                            );
                        }
                    }
                }

                $ruleData['field_type_instructions'] = $fieldTypeInstructions;

                // Add privacy with defaults
                if (isset($variant['defaults'])) {
                    $ruleData['privacy'] = [
                        'opt_out_fields' => $variant['defaults']['opt_out_fields'] ?? [],
                        // opt_out_templates has NO defaults - templates are site-specific
                        'masking' => [
                            'mask_emails' => $variant['defaults']['mask_emails'] ?? false,
                            'mask_phones' => $variant['defaults']['mask_phones'] ?? false,
                        ],
                    ];
                }

                return $ruleData;
            }
        }

        return null;
    }

    /**
     * Get field type instructions from variant config
     *
     * Returns field_type_instructions from the variant config file (Single Source of Truth)
     *
     * @param string $languageCode Language code to get field type instructions for
     * @return array Array of field type configurations
     */
    public static function getFieldTypeInstructions(string $languageCode): array
    {
        $variantConfig = self::getVariantConfig($languageCode);

        if (!$variantConfig || !isset($variantConfig['field_type_instructions'])) {
            return [];
        }

        return $variantConfig['field_type_instructions'];
    }
}
