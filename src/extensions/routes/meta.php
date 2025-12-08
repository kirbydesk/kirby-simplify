<?php

/**
 * Meta Routes for Kirby Simplify Plugin
 *
 * Routes for retrieving metadata about templates and field types.
 * Handles: simplify/templates, simplify/field-types
 */

use chrfickinger\Simplify\Helpers\RouteHelper;
use chrfickinger\Simplify\Helpers\BlueprintScanner;

return [
    [
        "pattern" => "simplify/templates",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function() {
                $templates = BlueprintScanner::getTemplates();
                return RouteHelper::successResponse('', ['templates' => $templates]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/field-types",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function() use ($context) {
                $fieldTypes = BlueprintScanner::getFieldTypes();

                // Build field type categories from fieldtype definitions
                $fieldTypeCategories = [
                    'strict' => [],
                    'structured' => [],
                    'elaborate' => [],
                    'default' => []
                ];

                $fieldTypesPath = dirname(__DIR__, 3) . '/rules/fieldtypes/';

                if (is_dir($fieldTypesPath)) {
                    foreach (glob($fieldTypesPath . '*.json') as $fieldTypeFile) {
                        $fieldTypeName = basename($fieldTypeFile, '.json');
                        $fieldTypeData = json_decode(file_get_contents($fieldTypeFile), true);

                        if (is_array($fieldTypeData) && isset($fieldTypeData['category'])) {
                            $category = $fieldTypeData['category'];
                            if (isset($fieldTypeCategories[$category])) {
                                $fieldTypeCategories[$category][] = $fieldTypeName;
                            }
                        }
                    }
                }

                return RouteHelper::successResponse('', [
                    'fieldTypes' => $fieldTypes,
                    'total' => count($fieldTypes),
                    'categories' => $fieldTypeCategories
                ]);
            }, $context['logger']);
        },
    ],
];
