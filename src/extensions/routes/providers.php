<?php

/**
 * Provider Routes for Kirby Simplify Plugin
 *
 * Routes for managing AI providers, their settings, testing, and statistics.
 * Handles: simplify/provider/*, simplify/providers/*
 */

use kirbydesk\Simplify\Helpers\RouteHelper;
use kirbydesk\Simplify\Helpers\ProviderFactory;
use kirbydesk\Simplify\Helpers\ProviderTester;
use kirbydesk\Simplify\Config\ConfigHelper;
use kirbydesk\Simplify\Logging\StatsLogger;

return [
    [
        "pattern" => "simplify/providers",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($context) {
                $config = ConfigHelper::getConfig();
                $providers = [];

                // Get all models grouped by provider
                $allModels = \kirbydesk\Simplify\Config\ModelConfig::getAll();
                $modelsByProvider = [];

                if ($context['logger']) {
                    $context['logger']->info("Total models found: " . count($allModels));
                }

                foreach ($allModels as $configId => $model) {
                    $providerType = $model['provider_type'] ?? 'unknown';

                    if ($context['logger']) {
                        $context['logger']->info("Model {$configId} has provider_type: {$providerType}");
                    }

                    if (!isset($modelsByProvider[$providerType])) {
                        $modelsByProvider[$providerType] = 0;
                    }
                    $modelsByProvider[$providerType]++;
                }

                if ($context['logger']) {
                    $context['logger']->info("Models by provider: " . json_encode($modelsByProvider));
                }

                // Iterate through providers array
                foreach ($config['providers'] as $id => $providerConfig) {
                    $providerName = $providerConfig['name'] ?? ucfirst($id);
                    $label = $providerConfig['label'] ?? $providerName;
                    $model = $providerConfig['model'] ?? 'unknown';
                    $endpoint = $providerConfig['endpoint'] ?? '';
                    $providerType = $providerConfig['provider'] ?? $id;

                    // Determine icon
                    $icon = 'openai';
                    if ($providerType === 'gemini') {
                        $icon = 'gemini';
                    } elseif ($providerType === 'anthropic') {
                        $icon = 'anthropic';
                    }

                    // Get model count for this provider
                    $modelCount = $modelsByProvider[$providerType] ?? 0;

                    $providers[] = [
                        'id' => $id,
                        'name' => $providerName,
                        'label' => $label,
                        'model' => $model,
                        'endpoint' => $endpoint,
                        'icon' => $icon,
                        'modelCount' => $modelCount,
                    ];
                }

                return RouteHelper::successResponse('', ['providers' => $providers]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/provider/(:any)",
        "method" => "GET",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($providerId, $context) {
                $config = ConfigHelper::getConfig();
                $providers = $config['providers'] ?? [];

                // Find provider
                $provider = null;
                foreach ($providers as $id => $providerData) {
                    if ($id === $providerId) {
                        $provider = array_merge($providerData, ['id' => $id]);
                        break;
                    }
                }

                if (!$provider) {
                    return RouteHelper::errorResponse("Provider not found");
                }

                // Get provider currency from Kirby config (fallback to USD)
                $kirby = $context['kirby'];
                $kirbyConfig = $kirby->option('kirbydesk.simplify');
                $providerConfig = $kirbyConfig['providers'][$providerId] ?? [];
                $provider['provider_currency'] = $providerConfig['currency'] ?? 'USD';

                // Load provider-specific settings from SQLite database
                $budgetManager = new \kirbydesk\Simplify\Core\BudgetManager($providerId);
                $settings = $budgetManager->loadSettings();

                return RouteHelper::successResponse('', [
                    'provider' => $provider,
                    'settings' => $settings
                ]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/provider/(:any)/settings",
        "method" => "POST",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            return RouteHelper::handleAction(function () use ($providerId, $data, $context) {
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

                $budgetManager = new \kirbydesk\Simplify\Core\BudgetManager($providerId);
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
    [
        "pattern" => "simplify/providers/(:any)/test",
        "method" => "POST",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            return RouteHelper::handleAction(function () use ($providerId, $data, $context) {
                $config = ConfigHelper::getConfig();
                $prompt = $data['prompt'] ?? 'Hello, this is a test.';

                $result = ProviderTester::test($providerId, $config, $prompt, null, $context['logger']);

                return $result['success']
                    ? RouteHelper::successResponse($result['message'] ?? 'Test successful')
                    : RouteHelper::errorResponse($result['message'] ?? 'Test failed');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/providers/(:any)/stats",
        "method" => "GET",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $period = $context['kirby']->request()->get('period', 'month');
            $from = $context['kirby']->request()->get('from', null);
            $to = $context['kirby']->request()->get('to', null);

            return RouteHelper::handleAction(function () use ($providerId, $period, $from, $to, $context) {
                // Get provider currency from Kirby config (fallback to USD)
                $kirby = $context['kirby'];
                $kirbyConfig = $kirby->option('kirbydesk.simplify');
                $providerConfig = $kirbyConfig['providers'][$providerId] ?? [];
                $currency = $providerConfig['currency'] ?? 'USD';

                $statsLogger = new StatsLogger();
                $rawStats = $statsLogger->getStats($providerId, $period, $from, $to);

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

                // Format stats for k-stats component (like pages view)
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
                        'value' => $rawStats['total_cost'] !== null
                            ? number_format($rawStats['total_cost'], 2, ',', '.') . ' ' . $currency
                            : '?',
                        'info' => $rawStats['total_cost'] !== null && $rawStats['total_calls'] > 0
                            ? sprintf('≈ %.4f %s %s',
                                $rawStats['total_cost'] / $rawStats['total_calls'],
                                $currency,
                                $labelPerCall
                              )
                            : t('simplify.provider.stats.noPricing'),
                        'icon' => 'cart',
                        'theme' => 'warning'
                    ]
                ];

                return RouteHelper::successResponse('', [
                    'stats' => $stats,
                    'rawStats' => $rawStats  // Keep raw data for backwards compatibility
                ]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/providers/(:any)/budget",
        "method" => "GET",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($providerId, $context) {
                // Create BudgetManager instance and get summary
                $budgetManager = new \kirbydesk\Simplify\Core\BudgetManager($providerId);
                $summary = $budgetManager->getSummary();

                return RouteHelper::successResponse('', [
                    'summary' => $summary
                ]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/providers/(:any)/budget/reset",
        "method" => "POST",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();
            $periodType = $data['periodType'] ?? 'monthly';

            return RouteHelper::handleAction(function () use ($providerId, $periodType, $context) {
                // Create BudgetManager and reset usage for specified period
                $budgetManager = new \kirbydesk\Simplify\Core\BudgetManager($providerId);
                $budgetManager->reset($periodType);

                return RouteHelper::successResponse('Budget reset successfully');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/providers/(:any)/stats/reset",
        "method" => "POST",
        "action" => function (string $providerId) {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();

            return RouteHelper::handleAction(function () use ($providerId, $context) {
                // Reset stats for this provider
                $statsLogger = new StatsLogger();
                $statsLogger->resetStats($providerId);

                return RouteHelper::successResponse('Stats reset successfully');
            }, $context['logger']);
        },
    ],
];
