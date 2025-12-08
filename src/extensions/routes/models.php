<?php

/**
 * Model Management Routes for Kirby Simplify Plugin
 *
 * Routes for managing model configurations.
 * Handles: simplify/models/*
 */

use chrfickinger\Simplify\Helpers\RouteHelper;
use chrfickinger\Simplify\Config\ModelConfig;
use chrfickinger\Simplify\Helpers\ModelQualityChecker;
use chrfickinger\Simplify\Logging\StatsLogger;
use Kirby\Http\Remote;

return [
    /**
     * GET simplify/models
     *
     * Get all configured models
     */
    [
        "pattern" => "simplify/models",
        "method" => "GET",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            return RouteHelper::handleAction(function () use ($context) {
                $models = ModelConfig::getAll();

                // Load provider names
                $providerConfig = include __DIR__ . '/../../../lib/Config/providers.php';
                $providerNames = [];
                foreach ($providerConfig as $type => $config) {
                    $providerNames[$type] = $config['name'];
                }

                if ($context['logger']) {
                    $context['logger']->info("Retrieved " . count($models) . " configured models");
                }

                return RouteHelper::successResponse('Models loaded', [
                    'models' => $models,
                    'providerNames' => $providerNames
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * GET simplify/models/available
     *
     * Get available models from /lib/Data/models.php
     */
    [
        "pattern" => "simplify/models/available",
        "method" => "GET",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            return RouteHelper::handleAction(function () use ($context) {
                // Get configured providers from config
                $kirby = $context['kirby'];
                $config = $kirby->option('chrfickinger.simplify');
                $configuredProviders = $config['providers'] ?? [];

                // Fetch models and status from kirbydesk.com API for each provider
                $models = [];
                $providerInfo = [];

                foreach ($configuredProviders as $providerId => $providerConfig) {
                    // Skip providers without API key
                    if (!isset($providerConfig['apikey']) || empty($providerConfig['apikey'])) {
                        continue;
                    }

                    // Fetch models from kirbydesk.com
                    $apiResponse = ModelQualityChecker::checkAllModels($providerId);

                    if ($apiResponse && isset($apiResponse['models'], $apiResponse['provider'])) {
                        $models[$providerId] = array_keys($apiResponse['models']);
                        $providerInfo[$providerId] = $apiResponse['provider'];
                    }
                }

                if ($context['logger']) {
                    $context['logger']->info("Retrieved available models from GitHub");
                }

                return RouteHelper::successResponse('Available models loaded', [
                    'models' => $models,
                    'providers' => $providerInfo
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * GET simplify/models/community-status/:provider
     *
     * Get community status for all models of a provider
     */
    [
        "pattern" => "simplify/models/community-status/(:any)",
        "method" => "GET",
        "action" => function (string $providerType) {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            return RouteHelper::handleAction(function () use ($providerType, $context) {
                // Fetch community status for all models from kirbydesk.com API
                $apiResponse = ModelQualityChecker::checkAllModels($providerType);

                if ($context['logger']) {
                    $context['logger']->info("Retrieved community status for provider: {$providerType}");
                }

                // Extract just the model data (without provider info)
                $statusData = $apiResponse && isset($apiResponse['models']) ? $apiResponse['models'] : [];

                return RouteHelper::successResponse('Community status loaded', [
                    'status' => $statusData
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * POST simplify/models/add
     *
     * Add new model configuration
     * Fetches data from kirbydesk.com API and saves to config file
     */
    [
        "pattern" => "simplify/models/add",
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
            $validation = RouteHelper::validateRequired($data, ['provider_type', 'model']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $providerType = $data['provider_type'];
            $model = $data['model'];
            $customName = $data['custom_name'] ?? null;

            return RouteHelper::handleAction(function () use (
                $providerType,
                $model,
                $customName,
                $context
            ) {
                // Use custom name if provided, otherwise use model name
                $modelName = $customName ?: $model;
                $configId = ModelConfig::buildId($providerType, $modelName);

                if ($context['logger']) {
                    $context['logger']->info("Adding model: {$configId}");
                }

                // Check if config already exists
                if (ModelConfig::exists($configId)) {
                    throw new \Exception("Model {$configId} already exists");
                }

                // Fetch model data from kirbydesk.com API
                $apiData = ModelQualityChecker::check($providerType, $model);

                if ($context['logger']) {
                    $context['logger']->info("API response for {$configId}: " . json_encode($apiData));
                }

                // Build config data with defaults
                $configData = [
                    'provider_type' => $providerType,
                    'model' => $modelName,
                    'community_status' => 'unknown',
                    'community_quality' => null,
                    'supports_temperature' => false,
                    'output_token_limit' => null,
                    'pricing' => null, // Manual user input only
                ];

                if ($apiData) {
                    // Update with API data (only status, quality, temperature, output_token_limit)
                    $configData['community_status'] = $apiData['status'] ?? 'unknown';
                    $configData['community_quality'] = $apiData['quality'] ?? null;

                    // Temperature support
                    $configData['supports_temperature'] = $apiData['temperature'] ?? false;

                    // Output token limit (null for unlimited/provider decides)
                    $configData['output_token_limit'] = $apiData['output_token_limit'] ?? null;

                    if ($context['logger']) {
                        $context['logger']->info("Fetched API data for {$configId}: status={$configData['community_status']}, quality=" . ($configData['community_quality'] ?? 'null') . ", supports_temperature=" . ($configData['supports_temperature'] ? 'true' : 'false'));
                    }
                } else {
                    if ($context['logger']) {
                        $context['logger']->info("No API data available for {$configId} - using defaults");
                    }
                }

                // Save config
                ModelConfig::save($configId, $configData, $context['logger']);

                return RouteHelper::successResponse('Model added successfully', [
                    'config_id' => $configId,
                    'config' => $configData
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * PATCH simplify/models/(:all)
     *
     * Update model configuration
     */
    [
        "pattern" => "simplify/models/(:all)",
        "method" => "PATCH",
        "action" => function (string $configId) {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();

            return RouteHelper::handleAction(function () use ($configId, $data, $context) {
                if ($context['logger']) {
                    $context['logger']->info("Updating model: {$configId}");
                }

                // Load existing config
                $config = ModelConfig::load($configId);

                if (!$config) {
                    throw new \Exception("Model {$configId} not found");
                }

                // Update pricing if provided
                if (isset($data['pricing'])) {
                    $config['pricing'] = [
                        'input' => $data['pricing']['input'] !== null ? (float) $data['pricing']['input'] : null,
                        'output' => $data['pricing']['output'] !== null ? (float) $data['pricing']['output'] : null,
                        'per_tokens' => $data['pricing']['per_tokens'] !== null ? (int) $data['pricing']['per_tokens'] : \chrfickinger\Simplify\Core\BudgetManager::DEFAULT_PER_TOKENS,
                    ];
                }

                // Save updated config
                ModelConfig::save($configId, $config, $context['logger']);

                return RouteHelper::successResponse('Model updated successfully', [
                    'config_id' => $configId,
                    'config' => $config
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * DELETE simplify/models/(:all)
     *
     * Delete model configuration
     */
    [
        "pattern" => "simplify/models/(:all)",
        "method" => "DELETE",
        "action" => function (string $configId) {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            return RouteHelper::handleAction(function () use ($configId, $context) {
                if ($context['logger']) {
                    $context['logger']->info("Deleting model: {$configId}");
                }

                // Check if config exists
                if (!ModelConfig::exists($configId)) {
                    throw new \Exception("Model {$configId} not found");
                }

                // Delete config
                ModelConfig::delete($configId, $context['logger']);

                return RouteHelper::successResponse('Model deleted successfully', [
                    'config_id' => $configId
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * GET simplify/models/(:all)
     *
     * Get model details (for loading in detail view)
     */
    [
        "pattern" => "simplify/models/(:all)",
        "method" => "GET",
        "action" => function (string $configId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($configId, $context) {
                $modelConfig = ModelConfig::load($configId);

                if (!$modelConfig) {
                    return RouteHelper::errorResponse("Model not found");
                }

                // Load budget settings from SQLite database
                $budgetManager = new \chrfickinger\Simplify\Core\BudgetManager($configId);
                $settings = $budgetManager->loadSettings();

                // Load GitHub data to get provider URL and model URL
                $providerType = $modelConfig['provider_type'];
                $githubData = ModelQualityChecker::checkAllModels($providerType);

                // Get provider currency from config (fallback to USD)
                $kirby = $context['kirby'];
                $config = $kirby->option('chrfickinger.simplify');
                $providerConfig = $config['providers'][$providerType] ?? [];
                $modelConfig['provider_currency'] = $providerConfig['currency'] ?? 'USD';

                // Add provider URL from GitHub data
                if ($githubData && isset($githubData['provider']['url'])) {
                    $modelConfig['provider_url'] = $githubData['provider']['url'];
                }

                // Add model data from GitHub if available
                if ($githubData && isset($githubData['models'][$modelConfig['model']])) {
                    $githubModel = $githubData['models'][$modelConfig['model']];

                    // Add model URL
                    if (isset($githubModel['url'])) {
                        $modelConfig['url'] = $githubModel['url'];
                    }

                    // Add GitHub metadata for display
                    $modelConfig['github_status'] = $githubModel['status'] ?? 'unknown';
                    $modelConfig['github_quality'] = $githubModel['quality'] ?? 0;
                    $modelConfig['github_recommended'] = $githubModel['recommended'] ?? false;
                    $modelConfig['github_temperature'] = $githubModel['temperature'] ?? false;
                    $modelConfig['github_output_token_limit'] = $githubModel['output_token_limit'] ?? null;
                }

                return RouteHelper::successResponse('', [
                    'provider' => $modelConfig,
                    'settings' => $settings
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * POST simplify/models/(:any)/settings
     *
     * Save model settings (budget limits)
     */
    [
        "pattern" => "simplify/models/(:any)/settings",
        "method" => "POST",
        "action" => function (string $configId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            return RouteHelper::handleAction(function () use ($configId, $data, $context) {
                $dailyBudget = $data['dailyBudget'] ?? 0;
                $monthlyBudget = $data['monthlyBudget'] ?? 0;

                // Validate ranges
                if ($dailyBudget < 0) {
                    return RouteHelper::errorResponse("Daily budget must be 0 or greater");
                }

                if ($monthlyBudget < 0) {
                    return RouteHelper::errorResponse("Monthly budget must be 0 or greater");
                }

                // Save settings to SQLite database
                $settings = [
                    'dailyBudget' => (float) $dailyBudget,
                    'monthlyBudget' => (float) $monthlyBudget,
                ];

                $budgetManager = new \chrfickinger\Simplify\Core\BudgetManager($configId);
                $success = $budgetManager->saveSettings($settings);

                if (!$success) {
                    return RouteHelper::errorResponse("Failed to save budget settings");
                }

                return RouteHelper::successResponse('Settings saved successfully', [
                    'settings' => $settings
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * GET simplify/models/(:any)/budget
     *
     * Get budget summary for model
     */
    [
        "pattern" => "simplify/models/(:any)/budget",
        "method" => "GET",
        "action" => function (string $configId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($configId, $context) {
                // Create BudgetManager instance and get summary
                $budgetManager = new \chrfickinger\Simplify\Core\BudgetManager($configId);
                $summary = $budgetManager->getSummary();

                return RouteHelper::successResponse('', [
                    'summary' => $summary
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * POST simplify/models/(:any)/budget/reset
     *
     * Reset budget usage for model
     */
    [
        "pattern" => "simplify/models/(:any)/budget/reset",
        "method" => "POST",
        "action" => function (string $configId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();
            $periodType = $data['periodType'] ?? 'monthly';

            return RouteHelper::handleAction(function () use ($configId, $periodType, $context) {
                // Create BudgetManager and reset usage for specified period
                $budgetManager = new \chrfickinger\Simplify\Core\BudgetManager($configId);
                $budgetManager->reset($periodType);

                return RouteHelper::successResponse('Budget reset successfully');
            }, $context['logger']);
        },
    ],

    /**
     * GET simplify/models/(:any)/stats
     *
     * Get statistics for model
     */
    [
        "pattern" => "simplify/models/(:any)/stats",
        "method" => "GET",
        "action" => function (string $configId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $period = $context['kirby']->request()->get('period', 'month');
            $from = $context['kirby']->request()->get('from', null);
            $to = $context['kirby']->request()->get('to', null);

            return RouteHelper::handleAction(function () use ($configId, $period, $from, $to, $context) {
                $modelConfig = ModelConfig::load($configId);
                $providerType = $modelConfig['provider_type'];

                // Get provider currency from Kirby config (fallback to USD)
                $kirby = $context['kirby'];
                $config = $kirby->option('chrfickinger.simplify');
                $providerConfig = $config['providers'][$providerType] ?? [];
                $currency = $providerConfig['currency'] ?? 'USD';

                $statsLogger = new StatsLogger();
                $rawStats = $statsLogger->getStats($configId, $period, $from, $to);

                // Get translation snippets
                $langVariantSingular = t('simplify.provider.stats.languageVariant.singular');
                $langVariantPlural = t('simplify.provider.stats.languageVariant.plural');
                $labelApiCalls = t('simplify.provider.stats.box.apiCalls');
                $labelPagesTranslatedSingular = t('simplify.provider.stats.box.pagesTranslated.singular');
                $labelPagesTranslatedPlural = t('simplify.provider.stats.box.pagesTranslated.plural');
                $labelTokensUsed = t('simplify.provider.stats.box.tokensUsed');
                $labelTotalCost = t('simplify.provider.stats.box.totalCost');
                $labelPerCall = t('simplify.provider.stats.box.perCall');
                $labelTokensIn = t('simplify.provider.stats.tokens.in');
                $labelTokensOut = t('simplify.provider.stats.tokens.out');

                // Format stats for k-stats component
                $stats = [
                    [
                        'label' => $labelApiCalls,
                        'value' => number_format($rawStats['total_calls'], 0, ',', '.'),
                        'info' => $period === 'all' ? t('simplify.provider.stats.period.all') : t('simplify.provider.stats.period.per') . ' ' . ($period === 'custom' ? t('simplify.provider.stats.period.range') : t($period)),
                        'icon' => 'apicall',
                        'theme' => 'info'
                    ],
                    [
                        'label' => $rawStats['unique_pages'] === 1 ? $labelPagesTranslatedSingular : $labelPagesTranslatedPlural,
                        'value' => number_format($rawStats['unique_pages'], 0, ',', '.'),
                        'info' => sprintf('%s %s',
                            number_format($rawStats['unique_languages'], 0, ',', '.'),
                            $rawStats['unique_languages'] === 1 ? $langVariantSingular : $langVariantPlural
                        ),
                        'icon' => 'pages',
                        'theme' => 'positive'
                    ],
                    [
                        'label' => $labelTokensUsed,
                        'value' => number_format($rawStats['total_tokens'], 0, ',', '.'),
                        'info' => sprintf('%s %s / %s %s',
                            number_format($rawStats['total_input_tokens'], 0, ',', '.'),
                            $labelTokensIn,
                            number_format($rawStats['total_output_tokens'], 0, ',', '.'),
                            $labelTokensOut
                        ),
                        'icon' => 'code',
                        'theme' => 'info'
                    ],
                    [
                        'label' => $labelTotalCost,
                        'value' => number_format($rawStats['total_cost'], 2, ',', '.') . ' ' . $currency,
                        'info' => sprintf('≈ %.4f %s %s',
                            $rawStats['total_calls'] > 0 ? $rawStats['total_cost'] / $rawStats['total_calls'] : 0,
                            $currency,
                            $labelPerCall
                        ),
                        'icon' => 'cart',
                        'theme' => 'warning'
                    ]
                ];

                return RouteHelper::successResponse('', [
                    'stats' => $stats,
                    'rawStats' => $rawStats
                ]);
            }, $context['logger']);
        },
    ],

    /**
     * POST simplify/models/(:any)/stats/reset
     *
     * Reset statistics for model
     */
    [
        "pattern" => "simplify/models/(:any)/stats/reset",
        "method" => "POST",
        "action" => function (string $configId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($configId, $context) {
                $statsLogger = new StatsLogger();
                $statsLogger->clearStats($configId);

                return RouteHelper::successResponse('Statistics reset successfully');
            }, $context['logger']);
        },
    ],
];
