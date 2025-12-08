<?php

use Kirby\Cms\App as Kirby;

/**
 * Load and sync all pages for a variant
 * Ensures all pages (including drafts) are in the config
 *
 * @param Kirby $kirby Kirby instance
 * @param string $variantCode Variant code (e.g., 'de-x-ls')
 * @param array $variantConfig Current variant configuration
 * @return array Array of all pages with metadata
 */
function loadVariantPages($kirby, $variantCode, &$variantConfig) {
    $allPages = [];

    // Build UUID index first for fast lookup (includes drafts)
    // Store without page:// prefix for consistent comparison
    $uuidIndex = [];
    foreach ($kirby->site()->index(true) as $p) {
        if ($p->uuid()) {
            $uuidString = $p->uuid()->toString();
            // Remove page:// prefix if present
            if (strpos($uuidString, 'page://') === 0) {
                $uuidString = substr($uuidString, 7);
            }
            $uuidIndex[$uuidString] = $p;
        }
    }

    // Ensure pages array exists in config
    if (!isset($variantConfig['pages']) || !is_array($variantConfig['pages'])) {
        $variantConfig['pages'] = [];
    }

    // Build index of existing pages in config for fast lookup
    $configPageUuids = [];
    foreach ($variantConfig['pages'] as $pageEntry) {
        if (isset($pageEntry['uuid'])) {
            // Normalize UUID - remove page:// prefix if present
            $normalizedUuid = $pageEntry['uuid'];
            if (strpos($normalizedUuid, 'page://') === 0) {
                $normalizedUuid = substr($normalizedUuid, 7);
            }
            $configPageUuids[$normalizedUuid] = true;
        }
    }

    // Add any missing pages (including drafts) to config
    $configChanged = false;
    foreach ($uuidIndex as $uuid => $page) {
        // $uuid from $uuidIndex is already without page:// prefix
        if (!isset($configPageUuids[$uuid])) {
            $variantConfig['pages'][] = [
                'uuid' => $uuid,
                'mode' => 'auto'
            ];
            $configChanged = true;
        }
    }

    // Save config if new pages were added
    if ($configChanged) {
        \kirbydesk\Simplify\Config\ConfigFileManager::saveVariantConfig($variantCode, $variantConfig);
    }

    // Now build the display list from config
    foreach ($variantConfig['pages'] as $pageEntry) {
        if (isset($pageEntry['uuid'])) {
            // Normalize UUID for lookup (remove page:// prefix if present)
            $uuid = $pageEntry['uuid'];
            if (strpos($uuid, 'page://') === 0) {
                $uuid = substr($uuid, 7);
            }

            if (isset($uuidIndex[$uuid])) {
                $page = $uuidIndex[$uuid];

                // Check if translated content file exists
                $languageCode = $variantConfig['language_code'] ?? $variantCode;
                $contentFile = $page->root() . '/' . $page->intendedTemplate()->name() . '.' . $languageCode . '.txt';
                $translatedFileExists = file_exists($contentFile);

                // Get source language for this variant
                $sourceLanguage = $variantConfig['source_language'];

                // Get page title in source language by loading content file directly
                $pageTitle = $page->content($sourceLanguage)->get('title')->value();

                // Fallback: try default language title if source language title is empty
                if (empty($pageTitle)) {
                    $pageTitle = $page->title()->value();
                }

                $allPages[] = [
                    'uuid' => $pageEntry['uuid'],
                    'mode' => $pageEntry['mode'] ?? 'auto',
                    'title' => $pageTitle,
                    'template' => $page->intendedTemplate()->name(),
                    'id' => $page->id(),
                    'status' => $page->status(),
                    'hasTranslation' => $translatedFileExists,
                ];
            }
        }
    }

    return $allPages;
}

return function ($kirby) {
    return [
        'label' => 'Simplify',
        'icon' => 'kirby-simplify',
        'menu' => true,
        'link' => 'simplify/variants',
        'access' => function () use ($kirby) {
            return $kirby->user() !== null;
        },
        'views' => [
            [
                'pattern' => 'simplify',
                'action' => function () {
                    return \Kirby\Panel\Panel::go('simplify/variants');
                }
            ],
            [
                'pattern' => 'simplify/(:any)',
                'action' => function (string $tab) use ($kirby) {
                    $config = \kirbydesk\Simplify\Config\ConfigHelper::getConfig();

                    $pages = $kirby->site()->index();
                    $data = [
                        'total' => $pages->count(),
                        'byLanguage' => []
                    ];

                    foreach (($config['languages'] ?? []) as $targetLang => $langConfig) {
                        $count = 0;
                        foreach ($pages as $page) {
                            $blueprint = $page->intendedTemplate()->name();
                            $targetFile = $page->root() . '/' . $blueprint . '.' . $targetLang . '.txt';
                            if (file_exists($targetFile)) {
                                $count++;
                            }
                        }
                        $data['byLanguage'][$targetLang] = $count;
                    }

                    // Get all site languages
                    $siteLanguages = [];
                    foreach ($kirby->languages() as $language) {
                        $code = $language->code();
                        if (strpos($code, '-x-') !== false) {
                            continue;
                        }
                        $siteLanguages[] = [
                            'code' => $code,
                            'name' => $language->name(),
                            'default' => $language->isDefault(),
                            'locale' => $language->locale(),
                            'direction' => $language->direction(),
                        ];
                    }
                    $data['siteLanguages'] = $siteLanguages;

                    // Get language variants
                    $languageVariants = [];

                    foreach ($kirby->languages() as $language) {
                        $code = $language->code();
                        if (strpos($code, '-x-') === false) {
                            continue;
                        }
                        $sourceCode = null;
                        if (isset($config['languages'][$code]['source'])) {
                            $sourceCode = $config['languages'][$code]['source'];
                        } elseif (!isset($config['languages'])) {
                            // If no languages configured, try to extract from code
                            if (preg_match('/^([a-z]{2}(?:-[a-z]{2})?)-x-/', $code, $matches)) {
                                $sourceCode = $matches[1];
                            }
                        } else {
                            if (preg_match('/^([a-z]{2}(?:-[a-z]{2})?)-x-/', $code, $matches)) {
                                $sourceCode = $matches[1];
                            }
                        }

                        // Read variant_code from language file
                        $variantCode = null;
                        $langFile = $kirby->root('languages') . '/' . $code . '.php';
                        if (file_exists($langFile)) {
                            $langData = include $langFile;
                            $variantCode = $langData['variant'] ?? null;
                        }

                        // Load provider model from variant config
                        $providerModel = null;
                        $providerLabel = null;
                        $variantConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($code . '.json');
                        if (file_exists($variantConfigPath)) {
                            $variantConfigJson = file_get_contents($variantConfigPath);
                            $variantConfig = json_decode($variantConfigJson, true);
                            $providerModel = $variantConfig['provider'] ?? null;

                            // Get label from model config
                            if ($providerModel) {
                                // Load model config to get provider name
                                $modelData = \kirbydesk\Simplify\Config\ModelConfig::load($providerModel);
                                if ($modelData) {
                                    $providerType = $modelData['provider_type'] ?? 'unknown';
                                    $modelName = $modelData['model'] ?? $providerModel;

                                    // Load provider names
                                    $providerNames = include __DIR__ . '/../../lib/Config/providers.php';
                                    $providerDisplayName = $providerNames[$providerType]['name'] ?? null;

                                    if ($providerDisplayName) {
                                        $providerLabel = $providerDisplayName . ': ' . $modelName;
                                    }
                                }
                            }
                        }

                        $languageVariants[] = [
                            'code' => $code,
                            'name' => $language->name(),
                            'locale' => $language->locale(),
                            'direction' => $language->direction(),
                            'source' => $sourceCode,
                            'variant' => $variantCode,
                            'providerModel' => $providerModel,
                            'providerLabel' => $providerLabel,
                            'enabled' => $variantConfig['enabled'] ?? true,
                        ];
                    }
                    $data['languageVariants'] = $languageVariants;

                    // Get available rule variants for each source language
                    $availableRuleVariants = [];
                    foreach ($siteLanguages as $siteLang) {
                        $variants = \kirbydesk\Simplify\Config\ConfigHelper::getAvailableVariantsForSource($siteLang['code']);
                        if (!empty($variants)) {
                            $availableRuleVariants[$siteLang['code']] = $variants;
                        }
                    }
                    $data['availableRuleVariants'] = $availableRuleVariants;

                    // Enrich provider data with metadata from central config
                    $enrichedConfig = $config;
                    if (isset($config['providers']) && is_array($config['providers'])) {
                        // Get all models grouped by provider
                        $allModels = \kirbydesk\Simplify\Config\ModelConfig::getAll();
                        $modelsByProvider = [];
                        foreach ($allModels as $model) {
                            $providerType = $model['provider_type'] ?? 'unknown';
                            if (!isset($modelsByProvider[$providerType])) {
                                $modelsByProvider[$providerType] = 0;
                            }
                            $modelsByProvider[$providerType]++;
                        }

                        foreach ($config['providers'] as $providerId => $providerData) {
                            $providerType = $providerData['provider'] ?? $providerId;
                            $enrichedConfig['providers'][$providerId]['displayName'] =
                                \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderName($providerId);
                            $enrichedConfig['providers'][$providerId]['icon'] =
                                \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderIcon($providerId);
                            $enrichedConfig['providers'][$providerId]['modelCount'] =
                                $modelsByProvider[$providerType] ?? 0;
                        }
                    }

                    // Get translated tab label
                    $tabLabel = $tab === 'models'
                        ? t('simplify.models.title')
                        : t('simplify.' . $tab);

                    return [
                        'component' => 'simplifyview',
                        'title' => 'Simplify',
                        'breadcrumb' => [
                            ['label' => $tabLabel, 'link' => 'simplify/' . $tab]
                        ],
                        'props' => [
                            'tab' => $tab,
                            'data' => $data,
                            'config' => $enrichedConfig
                        ]
                    ];
                }
            ],
            [
                'pattern' => 'simplify/variants/(:any)/(:any?)',
                'action' => function (string $variantCode, string $tab = 'pages') use ($kirby) {
                    $config = \kirbydesk\Simplify\Config\ConfigHelper::getConfig();

                    // Get variant language info
                    $language = $kirby->language($variantCode);
                    if (!$language) {
                        return [
                            'component' => 'k-error-view',
                            'props' => [
                                'error' => 'Language variant not found'
                            ]
                        ];
                    }

                    // Get variant config from site config
                    $variantConfig = $config['languages'][$variantCode] ?? [];

                    // Load custom config if exists (per-variant JSON file)
                    $customConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($variantCode . '.json');
                    if (file_exists($customConfigPath)) {
                        $jsonContent = file_get_contents($customConfigPath);
                        $customConfig = json_decode($jsonContent, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($customConfig)) {
                            $variantConfig = array_merge($variantConfig, $customConfig);
                        }
                    }

                    // Load rule data (defaults) for this variant from rules/variants/*.json
                    $ruleData = \kirbydesk\Simplify\Config\ConfigHelper::getRuleDataForVariant($variantCode);

                    // Load and sync all pages (including drafts)
                    $allPages = loadVariantPages($kirby, $variantCode, $variantConfig);

                    return [
                        'component' => 'simplify-variant-detail',
                        'title' => $language->name(),
                        'breadcrumb' => [
                            ['label' => t('simplify.languages'), 'link' => 'simplify/variants'],
                            ['label' => $language->name(), 'link' => 'simplify/variants/' . $variantCode]
                        ],
                        'props' => [
                            'tab' => $tab,
                            'variant' => [
                                'code' => $language->code(),
                                'name' => $language->name(),
                                'locale' => $language->locale(),
                                'direction' => $language->direction(),
                            ],
                            'variantConfig' => $variantConfig,
                            'ruleData' => $ruleData,
                            'config' => $config,
                            'allPages' => $allPages,
                        ]
                    ];
                }
            ],

            [
                'pattern' => 'simplify/project',
                'action' => function () use ($kirby) {
                    $config = \kirbydesk\Simplify\Config\ConfigHelper::getConfig();

                    return [
                        'component' => 'simplifyview',
                        'title' => t('simplify.project'),
                        'breadcrumb' => [
                            ['label' => 'Simplify', 'link' => 'simplify'],
                            ['label' => t('simplify.project'), 'link' => 'simplify/project']
                        ],
                        'props' => [
                            'tab' => 'project',
                            'config' => $config,
                            'data' => []
                        ]
                    ];
                }
            ],
            [
                'pattern' => 'simplify/providers/(:any)',
                'action' => function (string $providerId) use ($kirby) {
                    $config = \kirbydesk\Simplify\Config\ConfigHelper::getConfig();
                    $providers = $config['providers'] ?? [];

                    // Find provider by ID
                    if (!isset($providers[$providerId])) {
                        return [
                            'component' => 'k-error-view',
                            'props' => [
                                'error' => 'Provider not found'
                            ]
                        ];
                    }

                    $provider = array_merge($providers[$providerId], ['id' => $providerId]);

                    // Enrich provider with metadata from central config
                    $provider['displayName'] = \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderName($providerId);
                    $provider['icon'] = \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderIcon($providerId);

                    // Load models for this provider
                    $allModels = \kirbydesk\Simplify\Config\ModelConfig::getAll();
                    $providerModels = array_filter($allModels, function($model) use ($providerId) {
                        return ($model['provider_type'] ?? '') === $providerId;
                    });

                    return [
                        'component' => 'simplify-provider-detail',
                        'title' => $provider['displayName'],
                        'breadcrumb' => [
                            ['label' => t('simplify.providers'), 'link' => 'simplify/providers'],
                            ['label' => $provider['displayName'], 'link' => 'simplify/providers/' . $providerId]
                        ],
                        'props' => [
                            'providerId' => $providerId,
                            'providerData' => $provider,
                            'providerModels' => array_values($providerModels),
                            'tab' => 'settings'
                        ]
                    ];
                }
            ],
            [
                'pattern' => 'simplify/providers/(:any)/(:any)',
                'action' => function (string $providerId, string $tab) use ($kirby) {
                    $config = \kirbydesk\Simplify\Config\ConfigHelper::getConfig();
                    $providers = $config['providers'] ?? [];

                    // Find provider by ID
                    if (!isset($providers[$providerId])) {
                        return [
                            'component' => 'k-error-view',
                            'props' => [
                                'error' => 'Provider not found'
                            ]
                        ];
                    }

                    $provider = array_merge($providers[$providerId], ['id' => $providerId]);

                    // Enrich provider with metadata from central config
                    $provider['displayName'] = \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderName($providerId);
                    $provider['icon'] = \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderIcon($providerId);

                    // Load models for this provider
                    $allModels = \kirbydesk\Simplify\Config\ModelConfig::getAll();
                    $providerModels = array_filter($allModels, function($model) use ($providerId) {
                        return ($model['provider_type'] ?? '') === $providerId;
                    });

                    return [
                        'component' => 'simplify-provider-detail',
                        'title' => $provider['displayName'],
                        'breadcrumb' => [
                            ['label' => t('simplify.providers'), 'link' => 'simplify/providers'],
                            ['label' => $provider['displayName'], 'link' => 'simplify/providers/' . $providerId]
                        ],
                        'props' => [
                            'providerId' => $providerId,
                            'providerData' => $provider,
                            'providerModels' => array_values($providerModels),
                            'tab' => $tab
                        ]
                    ];
                }
            ],

            [
                'pattern' => 'simplify/providers/(:any)/models/(:all)',
                'action' => function (string $providerType, string $modelPath) use ($kirby) {
                    // Build config ID from provider and model path
                    $configId = $providerType . '/' . $modelPath;

                    // Load model config
                    $modelConfig = \kirbydesk\Simplify\Config\ModelConfig::load($configId);

                    if (!$modelConfig) {
                        return [
                            'component' => 'k-error-view',
                            'props' => [
                                'error' => 'Model not found'
                            ]
                        ];
                    }

                    $modelName = $modelConfig['model'] ?? $modelPath;
                    $providerName = \kirbydesk\Simplify\Helpers\ProviderHelper::getProviderName($providerType);

                    return [
                        'component' => 'simplify-model-detail',
                        'title' => $modelName,
                        'breadcrumb' => [
                            ['label' => t('simplify.providers'), 'link' => 'simplify/providers'],
                            ['label' => $providerName, 'link' => 'simplify/providers/' . $providerType],
                            ['label' => $modelName, 'link' => 'simplify/providers/' . $providerType . '/models/' . $modelPath]
                        ],
                        'props' => [
                            'providerId' => $configId,
                            'providerData' => $modelConfig
                        ]
                    ];
                }
            ]
        ]
    ];
};
