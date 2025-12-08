<?php

namespace kirbydesk\Simplify\Helpers;

use Kirby\Cms\Page;

/**
 * Field Filter
 *
 * Determines which fields should be translated based on opt-out rules
 */
class FieldFilter
{
    /**
     * Check if a field should be translated
     *
     * @param Page $page The page
     * @param string $fieldName Field name
     * @param string $fieldType Field type (e.g., 'text', 'blocks', etc.)
     * @param array $variantConfig Variant configuration
     * @return bool True if field should be translated
     */
    public static function shouldTranslate(Page $page, string $fieldName, string $fieldType, array $variantConfig): bool
    {
        // Check template opt-out
        if (self::isTemplateOptedOut($page, $variantConfig)) {
            return false;
        }

        // Check field name opt-out
        if (self::isFieldNameOptedOut($fieldName, $variantConfig)) {
            return false;
        }

        // Check if field is empty (before expensive checks)
        $fieldValue = $page->content()->get($fieldName)->value();
        if (empty(trim($fieldValue))) {
            return false;
        }

        // Check if field type is in allowed categories (WHITELIST approach)
        // This requires file system access, so do it last
        if (!self::isFieldTypeAllowed($fieldType, $variantConfig)) {
            return false;
        }

        return true;
    }

    /**
     * Check if template is opted out
     *
     * @param Page $page The page
     * @param array $variantConfig Variant configuration
     * @return bool True if template is opted out
     */
    private static function isTemplateOptedOut(Page $page, array $variantConfig): bool
    {
        $optOutTemplates = $variantConfig['opt_out_templates'] ?? [];
        $templateName = $page->intendedTemplate()->name();

        return in_array($templateName, $optOutTemplates);
    }

    /**
     * Check if field name is opted out (supports wildcards)
     *
     * @param string $fieldName Field name
     * @param array $variantConfig Variant configuration
     * @return bool True if field is opted out
     */
    private static function isFieldNameOptedOut(string $fieldName, array $variantConfig): bool
    {
        $optOutFields = $variantConfig['opt_out_fields'] ?? [];

        foreach ($optOutFields as $pattern) {
            // Convert wildcard pattern to regex
            // e.g., "internal*" becomes "/^internal.*$/i"
            $regexPattern = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/i';

            if (preg_match($regexPattern, $fieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if field type has a rule file (WHITELIST)
     *
     * @param string $fieldType Field type
     * @return bool True if rule file exists
     */
    private static function hasRuleFile(string $fieldType): bool
    {
        $kirby = \Kirby\Cms\App::instance();
        $rulesPath = $kirby->root('site') . '/plugins/kirby-simplify/rules/fieldtypes';
        $fieldTypeFile = $rulesPath . '/' . $fieldType . '.json';

        return file_exists($fieldTypeFile);
    }

    /**
     * Check if field type is in user opt-out list
     *
     * @param string $fieldType Field type
     * @param array $variantConfig Variant configuration
     * @return bool True if opted out
     */
    private static function isFieldTypeOptedOut(string $fieldType, array $variantConfig): bool
    {
        $optOutFieldTypes = $variantConfig['opt_out_fieldtypes'] ?? [];
        return in_array($fieldType, $optOutFieldTypes);
    }

    /**
     * Check if field type is allowed for translation (WHITELIST + USER OPT-OUT approach)
     * 1. Field type must have a rule file in rules/fieldtypes/ (WHITELIST)
     * 2. Field type must NOT be in opt_out_fieldtypes (USER OPT-OUT)
     *
     * @param string $fieldType Field type
     * @param array $variantConfig Variant configuration
     * @return bool True if field type is allowed
     */
    private static function isFieldTypeAllowed(string $fieldType, array $variantConfig): bool
    {
        if (!self::hasRuleFile($fieldType)) {
            return false;
        }

        if (self::isFieldTypeOptedOut($fieldType, $variantConfig)) {
            return false;
        }

        return true;
    }

    /**
     * Filter fields to only include those that should be translated
     *
     * @param Page $page The page
     * @param array $fieldNames Array of field names
     * @param array $variantConfig Variant configuration
     * @return array Filtered array of field names
     */
    public static function filterFields(Page $page, array $fieldNames, array $variantConfig): array
    {
        $filtered = [];

        foreach ($fieldNames as $fieldName) {
            // Get field type from blueprint
            $field = $page->blueprint()->field($fieldName);

            // Skip fields that are not defined in the blueprint
            if (empty($field)) {
                continue;
            }

            $fieldType = $field['type'] ?? 'text';

            if (self::shouldTranslate($page, $fieldName, $fieldType, $variantConfig)) {
                $filtered[] = $fieldName;
            }
        }

        return $filtered;
    }

    /**
     * Get reason why a field is not translated (for debugging)
     *
     * @param Page $page The page
     * @param string $fieldName Field name
     * @param string $fieldType Field type
     * @param array $variantConfig Variant configuration
     * @return string|null Reason or null if field should be translated
     */
    public static function getSkipReason(Page $page, string $fieldName, string $fieldType, array $variantConfig): ?string
    {
        // Check if field is defined in blueprint
        $field = $page->blueprint()->field($fieldName);
        if (empty($field)) {
            return "Field '{$fieldName}' is not defined in blueprint";
        }

        if (self::isTemplateOptedOut($page, $variantConfig)) {
            return "Template '{$page->intendedTemplate()->name()}' is in opt_out_templates";
        }

        if (self::isFieldNameOptedOut($fieldName, $variantConfig)) {
            return "Field name '{$fieldName}' matches opt_out_fields pattern";
        }

        $fieldValue = $page->content()->get($fieldName)->value();
        if (empty(trim($fieldValue))) {
            return "Field is empty";
        }

        if (!self::isFieldTypeAllowed($fieldType, $variantConfig)) {
            if (!self::hasRuleFile($fieldType)) {
                return "Field type '{$fieldType}' has no rule file in rules/fieldtypes/";
            }

            if (self::isFieldTypeOptedOut($fieldType, $variantConfig)) {
                return "Field type '{$fieldType}' is in opt_out_fieldtypes";
            }
        }

        return null;
    }
}
