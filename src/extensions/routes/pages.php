<?php

/**
 * Page Routes for Kirby Simplify Plugin
 *
 * Routes for managing page translations and page-specific settings.
 * Handles: simplify/page/*
 */

use chrfickinger\Simplify\Helpers\RouteHelper;
use chrfickinger\Simplify\Queue\WorkerManager;
use chrfickinger\Simplify\Config\ConfigFileManager;
use chrfickinger\Simplify\Config\ModelConfig;
use chrfickinger\Simplify\Queue\TranslationQueue;
use chrfickinger\Simplify\Processing\DiffDetector;

return [
    [
        "pattern" => "simplify/page/update-mode",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            // Validate required parameters
            $data = $context['kirby']->request()->data();
            $validation = RouteHelper::validateRequired($data, ['variantCode', 'pageUuid', 'mode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $pageUuid = $data['pageUuid'];
            $newMode = $data['mode'];

            // Validate mode value
            if (!in_array($newMode, ['auto', 'manual', 'off'])) {
                return RouteHelper::errorResponse('Invalid mode value');
            }

            return RouteHelper::handleAction(function () use ($variantCode, $pageUuid, $newMode, $context) {
                // Load config
                $config = ConfigFileManager::loadVariantConfig($variantCode);

                // Update mode for the specific page
                $pageFound = false;
                if (isset($config['pages']) && is_array($config['pages'])) {
                    foreach ($config['pages'] as &$page) {
                        if ($page['uuid'] === $pageUuid) {
                            $page['mode'] = $newMode;
                            $pageFound = true;
                            break;
                        }
                    }
                }

                if (!$pageFound) {
                    return RouteHelper::errorResponse('Page not found in config');
                }

                // Save config
                ConfigFileManager::saveVariantConfig($variantCode, $config, $context['logger']);

                if ($context['logger']) {
                    $context['logger']->info("Updated page mode: {$pageUuid} -> {$newMode} in variant {$variantCode}");
                }

                return RouteHelper::successResponse('Mode updated successfully');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/page/translate",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            // Check authorization
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            // Validate required parameters
            $data = $context['kirby']->request()->data();
            $validation = RouteHelper::validateRequired($data, ['variantCode', 'pageId']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $pageId = $data['pageId'];

            return RouteHelper::handleAction(function () use ($variantCode, $pageId, $context) {
                // Get the page
                $page = $context['kirby']->page($pageId);
                if (!$page) {
                    return RouteHelper::errorResponse('Page not found');
                }

                // Check if provider is configured for this variant
                $variantConfig = ConfigFileManager::loadVariantConfig($variantCode);
                $modelConfigId = $variantConfig['provider'] ?? null;

                // Check if model is configured
                if (empty($modelConfigId)) {
                    return RouteHelper::errorResponse(t('simplify.pages.translate.noProvider'));
                }

                // Load model config to get provider type
                $modelConfig = ModelConfig::load($modelConfigId);
                if (!$modelConfig) {
                    return RouteHelper::errorResponse(t('simplify.pages.translate.noProvider'));
                }

                $providerType = $modelConfig['provider_type'] ?? null;
                if (!$providerType) {
                    return RouteHelper::errorResponse(t('simplify.pages.translate.noProvider'));
                }

                // Check if provider has API key configured
                $globalConfig = $context['kirby']->option('chrfickinger.simplify', []);
                $providerConfig = $globalConfig['providers'][$providerType] ?? null;
                if (!$providerConfig || empty($providerConfig['apikey'])) {
                    return RouteHelper::errorResponse(t('simplify.pages.translate.noProvider'));
                }

                if ($context['logger']) {
                    $context['logger']->info("Translation requested for page {$pageId} -> {$variantCode}");
                }

                // Initialize queue
                $queue = new TranslationQueue();

                // Check if job already running for this page
                $runningJob = $queue->getRunningJobForPage($pageId, $variantCode);
                if ($runningJob) {
                    return RouteHelper::successResponse('Translation already in progress', [
                        'jobId' => $runningJob['id'],
                        'status' => 'already-running',
                    ]);
                }

                // Clear cache for this page before manual translation
                // This ensures fresh translation even if content hasn't changed
                $pageUuid = $page->uuid() ? $page->uuid()->toString() : null;
                if ($pageUuid) {
                    $cache = new \chrfickinger\Simplify\Cache\TranslationCache();
                    $languageCode = $variantConfig['language_code'] ?? $variantCode;
                    $cache->clearPage($pageUuid, $languageCode);

                    if ($context['logger']) {
                        $context['logger']->info("Cleared cache for page {$pageUuid}, language {$languageCode}");
                    }
                }

                // Create snapshot and job
                $snapshot = DiffDetector::createSnapshot($page);
                $job = $queue->addJob($pageId, $variantCode, $snapshot);

                // Check if a worker is already running
                if ($queue->isWorkerRunning()) {
                    if ($context['logger']) {
                        $context['logger']->info("Worker already running, job queued: {$job['id']}");
                    }

                    return RouteHelper::successResponse('Job queued, worker already running', [
                        'jobId' => $job['id'],
                        'status' => 'queued',
                    ]);
                }

                // Start worker using WorkerManager
                $workerPath = __DIR__ . '/../../../cli/worker.php';
                $result = WorkerManager::startWorker($job, $workerPath, $context['logger']);
                return $result;

            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/pages/translate-missing",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];

            return RouteHelper::handleAction(function () use ($variantCode, $context) {
                $kirby = $context['kirby'];
                $variantConfig = ConfigFileManager::loadVariantConfig($variantCode);
                $pages = $variantConfig['pages'] ?? [];
                $optOutTemplates = $variantConfig['opt_out_templates'] ?? [];

                // Add each page without translation to queue
                $queue = new TranslationQueue();
                $count = 0;

                foreach ($pages as $pageData) {
                    // Skip pages with mode 'off'
                    if (($pageData['mode'] ?? 'auto') === 'off') continue;

                    // Use uuid to find page
                    $pageUuid = $pageData['uuid'] ?? null;
                    if (!$pageUuid) continue;

                    $page = $kirby->page($pageUuid);
                    if (!$page) continue;

                    $pageId = $page->id();

                    // Skip pages with opt-out templates
                    if (in_array($page->intendedTemplate()->name(), $optOutTemplates)) {
                        continue;
                    }

                    // Check if translation file exists (live check, not cached)
                    $translation = $page->translation($variantCode);
                    if ($translation->exists()) {
                        continue; // Skip pages that already have translation
                    }

                    // Check if already in queue
                    $runningJob = $queue->getRunningJobForPage($pageId, $variantCode);
                    if ($runningJob) continue;

                    // Create snapshot and add job
                    $snapshot = DiffDetector::createSnapshot($page);
                    $isManual = ($pageData['mode'] ?? 'auto') === 'manual';
                    $queue->addJob($pageId, $variantCode, $snapshot, $isManual);
                    $count++;
                }

                if ($count === 0) {
                    return RouteHelper::successResponse('No missing translations found', ['count' => 0]);
                }

                // Start worker if not running
                if (!$queue->isWorkerRunning()) {
                    $workerPath = __DIR__ . '/../../../cli/worker.php';
                    $job = $queue->getNextPendingJob();
                    if ($job) {
                        WorkerManager::startWorker($job, $workerPath, $context['logger']);
                    }
                }

                return RouteHelper::successResponse("Added {$count} pages to queue", ['count' => $count]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/pages/translate-all",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];

            return RouteHelper::handleAction(function () use ($variantCode, $context) {
                $kirby = $context['kirby'];
                $variantConfig = ConfigFileManager::loadVariantConfig($variantCode);
                $pages = $variantConfig['pages'] ?? [];

                if (empty($pages)) {
                    return RouteHelper::successResponse('No pages found', ['count' => 0]);
                }

                // Add all pages to queue
                $queue = new TranslationQueue();
                $count = 0;
                $optOutTemplates = $variantConfig['opt_out_templates'] ?? [];

                foreach ($pages as $pageData) {
                    // Skip pages with mode 'off'
                    if (($pageData['mode'] ?? 'auto') === 'off') continue;

                    // Use uuid to find page
                    $pageUuid = $pageData['uuid'] ?? null;
                    if (!$pageUuid) continue;

                    $page = $kirby->page($pageUuid);
                    if (!$page) continue;

                    // Skip pages with excluded templates
                    $template = $page->intendedTemplate()->name();
                    if (in_array($template, $optOutTemplates)) continue;

                    $pageId = $page->id();

                    // Check if already in queue
                    $runningJob = $queue->getRunningJobForPage($pageId, $variantCode);
                    if ($runningJob) continue;

                    // Clear cache for fresh translation
                    $cache = new \chrfickinger\Simplify\Cache\TranslationCache();
                    $cache->clearPage($pageUuid, $variantCode);

                    // Create snapshot and add job
                    $snapshot = DiffDetector::createSnapshot($page);
                    $isManual = ($pageData['mode'] ?? 'auto') === 'manual';
                    $queue->addJob($pageId, $variantCode, $snapshot, $isManual);
                    $count++;
                }

                // Start worker if not running
                if ($count > 0 && !$queue->isWorkerRunning()) {
                    $workerPath = __DIR__ . '/../../../cli/worker.php';
                    $job = $queue->getNextPendingJob();
                    if ($job) {
                        WorkerManager::startWorker($job, $workerPath, $context['logger']);
                    }
                }

                return RouteHelper::successResponse("Added {$count} pages to queue", ['count' => $count]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/pages/delete-all-translations",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();
            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];

            return RouteHelper::handleAction(function () use ($variantCode, $context) {
                $kirby = $context['kirby'];
                $variantLang = $kirby->language($variantCode);

                if (!$variantLang) {
                    return RouteHelper::errorResponse('Variant language not found');
                }

                $variantConfig = ConfigFileManager::loadVariantConfig($variantCode);
                $pages = $variantConfig['pages'] ?? [];
                $count = 0;

                // Delete all page translations
                foreach ($pages as $pageData) {
                    // Use uuid to find page
                    $pageUuid = $pageData['uuid'] ?? null;
                    if (!$pageUuid) {
                        if ($context['logger']) {
                            $context['logger']->warning("Page entry missing uuid");
                        }
                        continue;
                    }

                    $page = $kirby->page($pageUuid);
                    if (!$page) {
                        if ($context['logger']) {
                            $context['logger']->warning("Page not found for uuid: {$pageUuid}");
                        }
                        continue;
                    }

                    $pageId = $page->id();

                    try {
                        // Check if translation exists
                        $translation = $page->translation($variantCode);

                        if ($context['logger']) {
                            $context['logger']->info("Checking page {$pageId}, translation exists: " . ($translation->exists() ? 'yes' : 'no'));
                        }

                        if ($translation->exists()) {
                            // Build translation file path manually since contentFile() is deprecated
                            $root = $page->root();
                            $filename = $page->intendedTemplate()->name() . '.' . $variantCode . '.txt';
                            $translationFile = $root . '/' . $filename;

                            if ($context['logger']) {
                                $context['logger']->info("Translation file path: {$translationFile}");
                                $context['logger']->info("File exists: " . (file_exists($translationFile) ? 'yes' : 'no'));
                            }

                            // Delete the translation content file
                            if (file_exists($translationFile)) {
                                \Kirby\Filesystem\F::remove($translationFile);

                                // Verify deletion
                                if (!file_exists($translationFile)) {
                                    $count++;
                                    if ($context['logger']) {
                                        $context['logger']->info("Successfully deleted translation file for page {$pageId}");
                                    }
                                } else {
                                    if ($context['logger']) {
                                        $context['logger']->error("Failed to delete translation file for page {$pageId} - file still exists");
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        if ($context['logger']) {
                            $context['logger']->error("Failed to delete translation for page {$pageId}: " . $e->getMessage());
                        }
                    }
                }

                return RouteHelper::successResponse("Deleted {$count} translations", ['count' => $count]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/page/delete-translation",
        "method" => "POST",
        "action" => function () {
            $context = RouteHelper::getContext();

            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $data = $context['kirby']->request()->data();
            $validation = RouteHelper::validateRequired($data, ['variantCode', 'pageId']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            $variantCode = $data['variantCode'];
            $pageId = $data['pageId'];

            return RouteHelper::handleAction(function () use ($variantCode, $pageId, $context) {
                $kirby = $context['kirby'];

                $page = $kirby->page($pageId);
                if (!$page) {
                    return RouteHelper::errorResponse('Page not found');
                }

                try {
                    // Check if translation exists
                    $translation = $page->translation($variantCode);

                    if (!$translation->exists()) {
                        return RouteHelper::errorResponse('Translation does not exist');
                    }

                    // Build translation file path manually since contentFile() is deprecated
                    $root = $page->root();
                    $filename = $page->intendedTemplate()->name() . '.' . $variantCode . '.txt';
                    $translationFile = $root . '/' . $filename;

                    if ($context['logger']) {
                        $context['logger']->info("Deleting translation file: {$translationFile}");
                    }

                    // Delete the translation content file
                    if (file_exists($translationFile)) {
                        \Kirby\Filesystem\F::remove($translationFile);

                        // Verify deletion
                        if (!file_exists($translationFile)) {
                            if ($context['logger']) {
                                $context['logger']->info("Successfully deleted translation for page {$pageId}");
                            }
                            return RouteHelper::successResponse("Translation deleted successfully");
                        } else {
                            if ($context['logger']) {
                                $context['logger']->error("Failed to delete translation file - file still exists");
                            }
                            return RouteHelper::errorResponse("Failed to delete translation file");
                        }
                    } else {
                        return RouteHelper::errorResponse("Translation file not found");
                    }
                } catch (\Exception $e) {
                    if ($context['logger']) {
                        $context['logger']->error("Failed to delete translation for page {$pageId}: " . $e->getMessage());
                    }
                    return RouteHelper::errorResponse($e->getMessage());
                }
            }, $context['logger']);
        },
    ],
];
