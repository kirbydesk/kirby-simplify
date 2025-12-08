<?php

namespace chrfickinger\Simplify\Processing;

use Kirby\Cms\Page;

class TranslationFilter
{
    /**
     * Level 1: Check if page mode allows translation
     *
     * @param Page $page The page to check
     * @param array $config Variant configuration
     * @return bool True if translation should proceed
     */
    public static function checkPageMode(Page $page, array $config): bool
    {
        $uuid = $page->uuid();
        if (!$uuid) {
            return false; // No UUID, cannot determine mode
        }

        $pageUuid = $uuid->toString();

        // Find this page in the pages array
        $pages = $config['pages'] ?? [];
        foreach ($pages as $pageEntry) {
            if (($pageEntry['uuid'] ?? null) === $pageUuid) {
                $mode = $pageEntry['mode'] ?? 'auto';
                // Only 'auto' mode triggers automatic translation
                return $mode === 'auto';
            }
        }

        // Page not found in config, default to 'auto'
        return true;
    }

    /**
     * Level 2: Check if page template is excluded
     *
     * @param Page $page The page to check
     * @param array $config Variant configuration
     * @return bool True if template is NOT excluded (translation should proceed)
     */
    public static function checkTemplate(Page $page, array $config): bool
    {
        $optOutTemplates = $config['opt_out_templates'] ?? [];

        if (empty($optOutTemplates)) {
            return true;
        }

        $pageTemplate = $page->intendedTemplate()->name();

        // Return false if template is in exclusion list
        return !in_array($pageTemplate, $optOutTemplates);
    }

    /**
     * Level 3: Get changed fields from page
     *
     * Returns only fields that have been modified
     *
     * @param Page $page The page to check
     * @return array Array of field names that have changed
     */
    public static function getChangedFields(Page $page): array
    {
        $changes = $page->changes();

        if (!is_array($changes) || empty($changes)) {
            return [];
        }

        return array_keys($changes);
    }

    /**
     * Level 4: Check if field type is enabled for translation
     *
     * @param string $fieldType The field type (e.g., 'text', 'blocks')
     * @param array $config Variant configuration
     * @return bool True if field type is enabled
     */
    public static function checkFieldType(string $fieldType, array $config): bool
    {
        // Check if field type is in opt-out list
        $optOutFieldTypes = $config['opt_out_fieldtypes'] ?? [];
        if (in_array($fieldType, $optOutFieldTypes)) {
            return false; // Field type is excluded
        }

        $fieldTypeInstructions = $config['field_type_instructions'] ?? [];

        // Field type not in config
        if (!isset($fieldTypeInstructions[$fieldType])) {
            return false;
        }

        // Check if enabled
        $enabled = $fieldTypeInstructions[$fieldType]['enabled'] ?? true;

        return $enabled === true;
    }

    /**
     * Level 5: Check if field name is excluded
     *
     * Supports wildcard patterns using fnmatch (e.g., "seo_*" matches "seo_title", "seo_description")
     *
     * @param string $fieldName The field name to check
     * @param array $config Variant configuration
     * @return bool True if field is excluded
     */
    public static function isFieldExcluded(string $fieldName, array $config): bool
    {
        $optOutFields = $config['opt_out_fields'] ?? [];

        if (empty($optOutFields)) {
            return false;
        }

        foreach ($optOutFields as $pattern) {
            // Wildcard matching: "seo_*" matches "seo_title", "seo_description", etc.
            if (fnmatch($pattern, $fieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get field type from page blueprint
     *
     * @param Page $page The page
     * @param string $fieldName The field name
     * @return string|null The field type, or null if not found
     */
    public static function getFieldType(Page $page, string $fieldName): ?string
    {
        $blueprint = $page->blueprint();

        // Get all fields from blueprint
        // This includes fields from all tabs and plugins, with 'extends' already resolved
        $allFields = $blueprint->fields();

        // Kirby converts field names to lowercase in content files
        // So we need to search case-insensitively
        $fieldNameLower = strtolower($fieldName);

        // First try exact match (lowercase)
        if (isset($allFields[$fieldNameLower]['type'])) {
            return $allFields[$fieldNameLower]['type'];
        }

        // Then try case-insensitive search in all blueprint fields
        foreach ($allFields as $blueprintFieldName => $fieldConfig) {
            if (strtolower($blueprintFieldName) === $fieldNameLower) {
                return $fieldConfig['type'] ?? null;
            }
        }

        // Fallback: Map Kirby's built-in standard fields only
        // Note: Only include fields that are guaranteed Kirby defaults
        $standardFieldTypes = [
            'title' => 'text',  // Always present in Kirby pages
            'slug' => 'text',   // Always present in Kirby pages
            'uuid' => 'text',   // Kirby 4+ UUID field
        ];

        return $standardFieldTypes[$fieldName] ?? null;
    }

    /**
     * Filter fields through all 5 levels and return translatable fields
     *
     * Combines all filtering logic into one method
     *
     * @param Page $page The page
     * @param array $config Variant configuration
     * @return array Array of field names that should be translated
     */
    public static function getTranslatableFields(Page $page, array $config): array
    {
        // Level 1: Check page mode
        if (!self::checkPageMode($page, $config)) {
            return [];
        }

        // Level 2: Check template
        if (!self::checkTemplate($page, $config)) {
            return [];
        }

        // Level 3: Get changed fields
        $changedFields = self::getChangedFields($page);

        if (empty($changedFields)) {
            return [];
        }

        // Level 4 & 5: Filter by field type and field name
        $translatableFields = [];

        foreach ($changedFields as $fieldName) {
            // Get field type
            $fieldType = self::getFieldType($page, $fieldName);

            if (!$fieldType) {
                continue; // Unknown field type
            }

            // Level 4: Check field type
            if (!self::checkFieldType($fieldType, $config)) {
                continue; // Field type disabled
            }

            // Level 5: Check field name exclusion
            if (self::isFieldExcluded($fieldName, $config)) {
                continue; // Field name excluded
            }

            // Field passed all filters
            $translatableFields[] = $fieldName;
        }

        return $translatableFields;
    }
}
