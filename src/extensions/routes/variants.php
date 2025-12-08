<?php

/**
 * Variant Routes for Kirby Simplify Plugin
 *
 * Routes for managing variant configurations, settings, and testing.
 * Handles: simplify/variant/*
 */

use chrfickinger\Simplify\Helpers\RouteHelper;
use chrfickinger\Simplify\Config\ConfigFileManager;
use chrfickinger\Simplify\Config\ConfigHelper;
use chrfickinger\Simplify\Helpers\ModelQualityChecker;

return [
    [
        "pattern" => "simplify/variant/save-all",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            // Validate required parameters
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $aiSystemPrompt = $data['ai_system_prompt'] ?? null;
            $fieldTypeInstructions = $data['field_type_instructions'] ?? null;
            $privacy = $data['privacy'] ?? null;

            return RouteHelper::handleAction(function () use (
                $variantCode,
                $aiSystemPrompt,
                $fieldTypeInstructions,
                $privacy,
                $context
            ) {
                if ($context['logger']) {
                    $context['logger']->info("save-all: Saving ALL settings for variant {$variantCode}");
                }

                // Load existing config - we need to preserve the complete structure
                $existingConfig = [];
                try {
                    $existingConfig = ConfigFileManager::loadVariantConfig($variantCode);
                } catch (\Exception $e) {
                    // File doesn't exist yet, start with empty config
                }

                // Update ai_system_prompt
                if ($aiSystemPrompt !== null) {
                    $existingConfig['ai_system_prompt'] = $aiSystemPrompt;
                }

                // Update field_type_instructions
                if ($fieldTypeInstructions !== null && is_array($fieldTypeInstructions)) {
                    // Ensure field_type_instructions exists
                    if (!isset($existingConfig['field_type_instructions']) || !is_array($existingConfig['field_type_instructions'])) {
                        $existingConfig['field_type_instructions'] = [];
                    }

                    // Update field_type_instructions
                    foreach ($fieldTypeInstructions as $fieldType => $data) {
                        if (is_array($data) && isset($data['instruction'])) {
                            // Full structure with enabled + instruction
                            $existingConfig['field_type_instructions'][$fieldType] = $data;
                        } else {
                            // Just instruction string (shouldn't happen, but handle it)
                            $existingConfig['field_type_instructions'][$fieldType] = [
                                'enabled' => true,
                                'instruction' => $data
                            ];
                        }
                    }
                }



                // Update privacy settings - FLAT STRUCTURE (root level)
                if ($privacy !== null) {
                    if (isset($privacy['opt_out_fields'])) {
                        $existingConfig['opt_out_fields'] = $privacy['opt_out_fields'];
                    }
                    if (isset($privacy['opt_out_templates'])) {
                        $existingConfig['opt_out_templates'] = $privacy['opt_out_templates'];
                    }
                    if (isset($privacy['opt_out_fieldtypes'])) {
                        $existingConfig['opt_out_fieldtypes'] = $privacy['opt_out_fieldtypes'];
                    }
                    if (isset($privacy['masking']['mask_emails'])) {
                        $existingConfig['mask_emails'] = $privacy['masking']['mask_emails'];
                    }
                    if (isset($privacy['masking']['mask_phones'])) {
                        $existingConfig['mask_phones'] = $privacy['masking']['mask_phones'];
                    }
                }

                // Save config using ConfigFileManager
                ConfigFileManager::saveVariantConfig($variantCode, $existingConfig, $context['logger']);

                return RouteHelper::successResponse('');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/variant/save-optout-templates",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            // Validate required parameters
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $optOutTemplates = $data['opt_out_templates'] ?? [];

            return RouteHelper::handleAction(function () use ($variantCode, $optOutTemplates, $context) {
                if ($context['logger']) {
                    $context['logger']->info("save-optout-templates: Saving ONLY opt_out_templates for variant {$variantCode}");
                }

                // Load existing config
                $config = ConfigFileManager::loadVariantConfig($variantCode);

                // Update ONLY opt_out_templates
                $config['opt_out_templates'] = $optOutTemplates;

                // Save config
                ConfigFileManager::saveVariantConfig($variantCode, $config, $context['logger']);

                if ($context['logger']) {
                    $context['logger']->info("save-optout-templates: Successfully saved opt_out_templates for {$variantCode}");
                }

                return RouteHelper::successResponse('');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/variant/save-config",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            // Validate required parameters
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $model = $data['model'] ?? null;
            $templates = $data['templates'] ?? null;
            $autoSimplify = $data['auto_simplify'] ?? null;
            $autoSimplifyDraft = $data['auto_simplify_draft'] ?? null;
            $systemPrompt = $data['system_prompt'] ?? null;
            $fieldTypeInstructions = $data['field_type_instructions'] ?? null;
            $postProcessing = $data['post_processing'] ?? null;
            $optOutFieldTypes = $data['opt_out_field_types'] ?? null;
            $maskingPatterns = $data['masking_patterns'] ?? null;

            return RouteHelper::handleAction(function () use (
                $variantCode,
                $model,
                $templates,
                $autoSimplify,
                $autoSimplifyDraft,
                $systemPrompt,
                $fieldTypeInstructions,
                $postProcessing,
                $optOutFieldTypes,
                $maskingPatterns,
                $context
            ) {
                if ($context['logger']) {
                    $context['logger']->info("save-config: Saving variant config for {$variantCode}");
                }

                // Load existing config
                $existingConfig = [];
                try {
                    $existingConfig = ConfigFileManager::loadVariantConfig($variantCode);
                } catch (\Exception $e) {
                    // File doesn't exist yet, start with empty config
                }

                // Update model (stored in 'provider' field for backward compatibility)
                if ($model !== null) {
                    $existingConfig['provider'] = $model;
                }

                // Update templates
                if ($templates !== null) {
                    $existingConfig['templates'] = $templates;
                }

                // Update auto_simplify
                if ($autoSimplify !== null) {
                    $existingConfig['auto_simplify'] = $autoSimplify;
                }

                // Update auto_simplify_draft
                if ($autoSimplifyDraft !== null) {
                    $existingConfig['auto_simplify_draft'] = $autoSimplifyDraft;
                }

                // Update system_prompt
                if ($systemPrompt !== null) {
                    $existingConfig['system_prompt'] = $systemPrompt;
                }

                // Update field_type_instructions
                if ($fieldTypeInstructions !== null) {
                    $existingConfig['field_type_instructions'] = $fieldTypeInstructions;
                }

                // Update post_processing
                if ($postProcessing !== null) {
                    $existingConfig['post_processing'] = $postProcessing;
                }

                // Update opt_out_field_types
                if ($optOutFieldTypes !== null) {
                    $existingConfig['opt_out_field_types'] = $optOutFieldTypes;
                }

                // Update masking_patterns
                if ($maskingPatterns !== null) {
                    $existingConfig['masking_patterns'] = $maskingPatterns;
                }

                // Save config
                ConfigFileManager::saveVariantConfig($variantCode, $existingConfig, $context['logger']);

                return RouteHelper::successResponse('');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/variant/assign-model",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            // Validate required parameters
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $modelConfigId = $data['modelConfigId'] ?? null;

            return RouteHelper::handleAction(function () use (
                $variantCode,
                $modelConfigId,
                $context
            ) {
                if ($context['logger']) {
                    $context['logger']->info("assign-model: Assigning model {$modelConfigId} to variant {$variantCode}");
                }

                // Load existing variant config
                $existingConfig = [];
                try {
                    $existingConfig = ConfigFileManager::loadVariantConfig($variantCode);
                } catch (\Exception $e) {
                    // File doesn't exist yet, start with empty config
                }

                // Update provider field with model config ID (or set to null if empty)
                if (empty($modelConfigId)) {
                    $existingConfig['provider'] = null;
                } else {
                    $existingConfig['provider'] = $modelConfigId;
                }

                // Save config
                ConfigFileManager::saveVariantConfig($variantCode, $existingConfig, $context['logger']);

                return RouteHelper::successResponse('');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/variant/toggle-enabled",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            // Validate required parameters
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];

            return RouteHelper::handleAction(function () use ($variantCode, $context) {
                // Load existing config
                $existingConfig = ConfigFileManager::loadVariantConfig($variantCode);

                // Toggle enabled status
                $currentEnabled = $existingConfig['enabled'] ?? true;
                $existingConfig['enabled'] = !$currentEnabled;

                // Save config
                ConfigFileManager::saveVariantConfig($variantCode, $existingConfig, $context['logger']);

                if ($context['logger']) {
                    $status = $existingConfig['enabled'] ? 'enabled' : 'disabled';
                    $context['logger']->info("Variant {$variantCode} automatic translation {$status}");
                }

                return RouteHelper::successResponse(
                    '',
                    ['enabled' => $existingConfig['enabled']]
                );
            }, $context['logger']);
        },
    ],
];
