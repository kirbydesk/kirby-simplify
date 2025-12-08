<?php

namespace chrfickinger\Simplify\Processing;

use Kirby\Cms\Page;

/**
 * Field Filter
 *
 * Handles field filtering and validation logic:
 * - Include/exclude patterns
 * - System field detection
 * - Lock checking
 * - Field type detection
 */
class FieldFilter
{
    /**
     * Get fields to process for a page
     *
     * @param Page $page Source page
     * @param array $langConfig Language configuration
     * @return array Field name => value pairs
     */
    public static function getFieldsToProcess(Page $page, array $langConfig): array
    {
        $fields = [];
        $blueprint = $page->blueprint();

        // Get source language from langConfig, fallback to 'de'
        $sourceLang = $langConfig["source"] ?? "de";
        $content = $page->content($sourceLang);

        // Get all fields from blueprint
        foreach ($blueprint->fields() as $fieldName => $fieldConfig) {
            // Skip system fields
            if (self::isSystemField($fieldName)) {
                continue;
            }

            // Check if field is locked
            if (self::isFieldLocked($page, $fieldName)) {
                continue;
            }

            // Apply include/exclude filters
            if (!self::shouldProcessField($fieldName, $langConfig)) {
                continue;
            }

            // Get field value
            $value = $content->get($fieldName)->value();

            if (empty($value)) {
                continue;
            }

            $fields[$fieldName] = $value;
        }

        return $fields;
    }

    /**
     * Check if field should be processed based on include/exclude rules
     *
     * @param string $fieldName Field name to check
     * @param array $langConfig Language configuration
     * @return bool True if field should be processed
     */
    public static function shouldProcessField(
        string $fieldName,
        array $langConfig,
    ): bool {
        $include = $langConfig["include"] ?? [];
        $exclude = $langConfig["exclude"] ?? [];

        // If include list exists, field must be in it
        if (!empty($include)) {
            foreach ($include as $pattern) {
                if (self::matchesPattern($fieldName, $pattern)) {
                    return true;
                }
            }
            return false;
        }

        // Check exclude list
        if (!empty($exclude)) {
            foreach ($exclude as $pattern) {
                if (self::matchesPattern($fieldName, $pattern)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if field name matches pattern (supports wildcards)
     *
     * @param string $fieldName Field name
     * @param string $pattern Pattern with wildcards (*, ?)
     * @return bool True if matches
     */
    public static function matchesPattern(string $fieldName, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = "/^" . str_replace(["*", "."], [".*", "\\."], $pattern) . '$/';
        return preg_match($regex, $fieldName) === 1;
    }

    /**
     * Check if page is locked for automatic simplification
     *
     * @param Page $page Page to check
     * @return bool True if locked
     */
    public static function isPageLocked(Page $page): bool
    {
        return $page->content()->get("simplifyLock")->toBool();
    }

    /**
     * Check if specific field is locked
     *
     * @param Page $page Page containing the field
     * @param string $fieldName Field name to check
     * @return bool True if field is locked
     */
    public static function isFieldLocked(Page $page, string $fieldName): bool
    {
        $locks = $page->content()->get("simplifyFieldLocks")->split();
        return in_array($fieldName, $locks);
    }

    /**
     * Check if field is a system field (should not be processed)
     *
     * @param string $fieldName Field name
     * @return bool True if system field
     */
    public static function isSystemField(string $fieldName): bool
    {
        $systemFields = [
            "title",
            "slug",
            "uuid",
            "template",
            "status",
            "simplifyLock",
            "simplifyFieldLocks",
            "simplifyAuto",
        ];

        return in_array($fieldName, $systemFields);
    }

    /**
     * Get field type from blueprint
     *
     * @param Page $page Page containing the field
     * @param string $fieldName Field name
     * @return string Field type (e.g., 'text', 'textarea', 'blocks')
     */
    public static function getFieldType(Page $page, string $fieldName): string
    {
        $blueprint = $page->blueprint();
        $field = $blueprint->field($fieldName);

        return $field["type"] ?? "text";
    }
}
