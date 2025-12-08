<?php

/**
 * Hooks for Kirby Simplify Plugin
 *
 * This file contains all hooks for the plugin.
 * Hooks are included in index.php via require_once.
 */

use Kirby\Cms\App as Kirby;

/**
 * Helper function to get page mode from variant config
 */
function getPageMode($page, $targetLang) {
    $kirby = Kirby::instance();
    $customConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($targetLang . '.json');
    $pageMode = 'auto'; // Default mode

    if (file_exists($customConfigPath)) {
        $jsonContent = file_get_contents($customConfigPath);
        $customConfig = json_decode($jsonContent, true) ?: [];

        // Get page UUID (without 'page://' prefix)
        $pageUuid = $page->uuid() ? $page->uuid()->toString() : null;

        if (!$pageUuid) {
            return $pageMode;
        }

        // Check if page has specific mode set
        // The pages array contains objects with 'uuid' and 'mode' properties
        if (isset($customConfig['pages']) && is_array($customConfig['pages'])) {
            foreach ($customConfig['pages'] as $pageEntry) {
                if (isset($pageEntry['uuid']) && $pageEntry['uuid'] === $pageUuid) {
                    $pageMode = $pageEntry['mode'] ?? 'auto';
                    break;
                }
            }
        }
    }

    return $pageMode;
}

return [
    /**
     * Initialize variant config when language is created
     */
    "language.create:after" => function ($language) {
        $kirby = Kirby::instance();
        $logger = $GLOBALS["simplify_instances"]["hooks_logger"] ?? null;
        $code = $language->code();

        // Only initialize for language variants (with -x- in code)
        if (strpos($code, '-x-') !== false) {
            $initialized = \kirbydesk\Simplify\Config\ConfigInitializer::initializeVariantConfig($code);

            if ($initialized && $logger) {
                $logger->info("Initialized variant config for new language: {$code}");
            }
            // Note: If initialization fails (language file not ready yet), the Panel will create the config on first access
        }
    },

    /**
     * Auto-simplification on page create
     */
    "page.create:after" => function ($page) {
            $kirby = Kirby::instance();

            // Get logger first for debugging
            $logger = $GLOBALS["simplify_instances"]["hooks_logger"] ?? null;

            if ($logger) {
                $logger->info(
                    "Hook page.create:after triggered for page: {$page->id()}",
                );
            }

            // Get current language
            $currentLang = $kirby->language()
                ? $kirby->language()->code()
                : "de";

            if ($logger) {
                $logger->info("Current language: {$currentLang}");
            }

            // Get all language variants from Kirby languages (those with -x- in code)
            $languages = $kirby->languages();
            if (!$languages || $languages->count() === 0) {
                if ($logger) {
                    $logger->debug("No languages configured, skipping auto-simplification");
                }
                return;
            }

            // Process each language variant that has current language as source
            foreach ($languages as $language) {
                $targetLang = $language->code();

                // Skip current language
                if ($targetLang === $currentLang) {
                    continue;
                }

                // Get source from language file
                $langFile = $kirby->root('languages') . '/' . $targetLang . '.php';
                if (!file_exists($langFile)) {
                    continue;
                }

                $langData = include $langFile;
                $sourceLang = $langData['source'] ?? null;

                // Skip if no source defined (not a Simplify variant)
                if (!$sourceLang) {
                    continue;
                }

                if ($sourceLang !== $currentLang) {
                    if ($logger) {
                        $logger->debug(
                            "Skipping {$targetLang} (source: {$sourceLang}, current: {$currentLang})",
                        );
                    }
                    continue;
                }

                // Check if variant is enabled
                $customConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($targetLang . '.json');
                $variantEnabled = true; // Default to enabled
                if (file_exists($customConfigPath)) {
                    $jsonContent = file_get_contents($customConfigPath);
                    $customConfig = json_decode($jsonContent, true) ?: [];
                    $variantEnabled = $customConfig['enabled'] ?? true;
                }

                if (!$variantEnabled) {
                    if ($logger) {
                        $logger->debug("Skipping {$targetLang} - variant is paused");
                    }
                    continue;
                }

                // Get page mode from variant config
                $pageMode = getPageMode($page, $targetLang);

                if ($logger) {
                    $logger->debug("Page {$page->id()} mode for {$targetLang}: {$pageMode}");
                }

                if ($pageMode === 'off') {
                    if ($logger) {
                        $logger->debug("Skipping {$targetLang} - mode is 'off'");
                    }
                    continue;
                }

                if ($pageMode === 'manual') {
                    if ($logger) {
                        $logger->debug("Skipping {$targetLang} - mode is 'manual'");
                    }
                    continue;
                }

                try {
                    if ($logger) {
                        $logger->info(
                            "Processing new page {$page->id()} for language {$targetLang} (source: {$sourceLang}) in mode: {$pageMode}",
                        );
                    }

                    // Mode is 'auto' - use background job system
                    $queue = new \kirbydesk\Simplify\Queue\TranslationQueue();

                    // Check if job already running for this page+variant
                    $existingJob = $queue->getRunningJobForPage($page->id(), $targetLang);
                    if ($existingJob) {
                        if ($logger) {
                            $logger->info("Job already running for {$page->id()} -> {$targetLang}, skipping");
                        }
                        continue;
                    }

                    // Create snapshot
                    $snapshot = \kirbydesk\Simplify\Processing\DiffDetector::createSnapshot($page);

                    // Create job (automatic translation)
                    $job = $queue->addJob($page->id(), $targetLang, $snapshot, false);

                    if ($logger) {
                        $logger->info("Created background job {$job['id']} for auto-create translation");
                    }

                    // Check if a worker is already running
                    if ($queue->isWorkerRunning()) {
                        if ($logger) {
                            $logger->info("Worker already running, job queued: {$job['id']}");
                        }
                        continue; // Skip to next variant
                    }

                    // Start worker using WorkerManager
                    $workerPath = __DIR__ . '/../../cli/worker.php';
                    \kirbydesk\Simplify\Queue\WorkerManager::startWorker($job, $workerPath, $logger);

                } catch (\Exception $e) {
                    if ($logger) {
                        $logger->error(
                            "Hook failed for new page {$page->id()} ({$targetLang}): " .
                                $e->getMessage(),
                        );
                    }
                }
            }
        },

        /**
         * Auto-simplification on page update
         */
        "page.update:after" => function ($newPage, $oldPage) {
            $kirby = Kirby::instance();

            // Get logger first for debugging
            $logger = $GLOBALS["simplify_instances"]["hooks_logger"] ?? null;

            if ($logger) {
                $logger->info(
                    "Hook page.update:after triggered for page: {$newPage->id()}",
                );
            }

            // Get current language
            $currentLang = $kirby->language()
                ? $kirby->language()->code()
                : "de";

            // Check if this is a language variant page being saved
            if ($currentLang && strpos($currentLang, '-x-') !== false) {
                if ($logger) {
                    $logger->info("Detected language variant save: {$currentLang}");
                }

                // This is a language variant - set mode to manual
                $customConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($currentLang . '.json');

                if ($logger) {
                    $logger->info("Config path: {$customConfigPath}, exists: " . (file_exists($customConfigPath) ? 'yes' : 'no'));
                }

                if (file_exists($customConfigPath)) {
                    $jsonContent = file_get_contents($customConfigPath);
                    $variantConfig = json_decode($jsonContent, true);

                    if (is_array($variantConfig) && isset($variantConfig['pages'])) {
                        $pageUuid = $newPage->uuid() ? $newPage->uuid()->toString() : null;

                        if ($logger) {
                            $logger->info("Page UUID: {$pageUuid}");
                        }

                        if ($pageUuid) {
                            // Find and update the page mode
                            $found = false;
                            foreach ($variantConfig['pages'] as &$pageEntry) {
                                $entryUuid = strpos($pageEntry['uuid'], 'page://') === 0
                                    ? $pageEntry['uuid']
                                    : 'page://' . $pageEntry['uuid'];

                                if ($entryUuid === $pageUuid || $entryUuid === 'page://' . $pageUuid) {
                                    $pageEntry['mode'] = 'manual';
                                    $found = true;
                                    if ($logger) {
                                        $logger->info("Found page in config, setting mode to manual");
                                    }
                                    break;
                                }
                            }

                            if (!$found && $logger) {
                                $logger->warning("Page UUID not found in config pages array");
                            }

                            // Save updated config
                            file_put_contents($customConfigPath, json_encode($variantConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                            if ($logger) {
                                $logger->info("Config file saved");
                            }
                        }
                    } else {
                        if ($logger) {
                            $logger->warning("Config is not an array or pages key missing");
                        }
                    }
                }
            } else {
                if ($logger) {
                    $logger->info("Not a language variant (currentLang: {$currentLang})");
                }
            }

            if ($logger) {
                $logger->info("Current language: {$currentLang}");
            }

            // Get all language variants from Kirby languages (those with -x- in code)
            $languages = $kirby->languages();
            if (!$languages || $languages->count() === 0) {
                if ($logger) {
                    $logger->debug("No languages configured, skipping auto-simplification");
                }
                return;
            }

            // Process each language variant that has current language as source
            foreach ($languages as $language) {
                $targetLang = $language->code();

                // Skip current language
                if ($targetLang === $currentLang) {
                    continue;
                }

                // Get source from language file
                $langFile = $kirby->root('languages') . '/' . $targetLang . '.php';
                if (!file_exists($langFile)) {
                    continue;
                }

                $langData = include $langFile;
                $sourceLang = $langData['source'] ?? null;

                // Skip if no source defined (not a Simplify variant)
                if (!$sourceLang) {
                    continue;
                }

                if ($sourceLang !== $currentLang) {
                    if ($logger) {
                        $logger->debug(
                            "Skipping {$targetLang} (source: {$sourceLang}, current: {$currentLang})",
                        );
                    }
                    continue;
                }

                // Check if variant is enabled
                $customConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($targetLang . '.json');
                $variantEnabled = true; // Default to enabled
                if (file_exists($customConfigPath)) {
                    $jsonContent = file_get_contents($customConfigPath);
                    $customConfig = json_decode($jsonContent, true) ?: [];
                    $variantEnabled = $customConfig['enabled'] ?? true;
                }

                if (!$variantEnabled) {
                    if ($logger) {
                        $logger->debug("Skipping {$targetLang} - variant is paused");
                    }
                    continue;
                }

                // Get page mode from variant config
                $pageMode = getPageMode($newPage, $targetLang);

                if ($logger) {
                    $logger->debug("Page {$newPage->id()} mode for {$targetLang}: {$pageMode}");
                }

                if ($pageMode === 'off') {
                    if ($logger) {
                        $logger->debug("Skipping {$targetLang} - mode is 'off'");
                    }
                    continue;
                }

                if ($pageMode === 'manual') {
                    if ($logger) {
                        $logger->debug("Skipping {$targetLang} - mode is 'manual'");
                    }
                    continue;
                }

                try {
                    if ($logger) {
                        $logger->info(
                            "Processing page {$newPage->id()} for language {$targetLang} (source: {$sourceLang}) in mode: {$pageMode}",
                        );
                    }

                    // Mode is 'auto' - use background job system
                    $queue = new \kirbydesk\Simplify\Queue\TranslationQueue();

                    // Check if job already running
                    $existingJob = $queue->getRunningJobForPage($newPage->id(), $targetLang);

                    if ($existingJob) {
                        if ($logger) {
                            $logger->info("Job already running for {$newPage->id()} -> {$targetLang}, skipping");
                        }
                        continue;
                    }

                    // Create snapshot
                    $snapshot = \kirbydesk\Simplify\Processing\DiffDetector::createSnapshot($newPage);

                    // Create job (automatic translation)
                    $job = $queue->addJob($newPage->id(), $targetLang, $snapshot, false);

                    if ($logger) {
                        $logger->info("Created background job {$job['id']} for auto-translation");
                    }

                    // Check if a worker is already running
                    if ($queue->isWorkerRunning()) {
                        if ($logger) {
                            $logger->info("Worker already running, job queued: {$job['id']}");
                        }
                        continue; // Skip to next variant
                    }

                    // Start worker using WorkerManager
                    $workerPath = __DIR__ . '/../../cli/worker.php';
                    \kirbydesk\Simplify\Queue\WorkerManager::startWorker($job, $workerPath, $logger);

                } catch (\Exception $e) {
                    if ($logger) {
                        $logger->error(
                            "Hook failed for {$newPage->id()} ({$targetLang}): " .
                                $e->getMessage(),
                        );
                    }
                }
            }
        },

        /**
         * Delete custom config JSON file when language is deleted
         */
        "language.delete:after" => function ($language) {
            $kirby = Kirby::instance();
            $logger = $GLOBALS["simplify_instances"]["hooks_logger"] ?? null;
            $code = $language->code();

            // Only delete for language variants (with -x- in code)
            if (strpos($code, '-x-') !== false) {
                // 1. Delete config file
                $customConfigPath = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath($code . '.json');
                if (file_exists($customConfigPath)) {
                    unlink($customConfigPath);
                    if ($logger) {
                        $logger->info("Deleted custom config file for deleted variant: {$code}");
                    }
                }

                // 2. Delete worker directory
                $workerDir = $kirby->root('logs') . '/simplify/workers/' . $code;
                if (is_dir($workerDir)) {
                    \Kirby\Toolkit\Dir::remove($workerDir);
                    if ($logger) {
                        $logger->info("Deleted worker directory for deleted variant: {$code}");
                    }
                }

                // 3. Delete all queue jobs for this variant
                $queue = new \kirbydesk\Simplify\Queue\TranslationQueue();
                $jobs = $queue->getJobsForVariant($code);
                foreach ($jobs as $job) {
                    $queue->deleteJob($job['id']);
                }
                if ($logger) {
                    $logger->info("Deleted " . count($jobs) . " queue jobs for deleted variant: {$code}");
                }

                // 4. Clear variant reports (not provider stats - those remain for accounting)
                try {
                    $reportsLogger = new \kirbydesk\Simplify\Logging\ReportsLogger();
                    $reportsLogger->clearReports($code);
                    if ($logger) {
                        $logger->info("Cleared variant reports for deleted variant: {$code}");
                    }
                } catch (\Exception $e) {
                    if ($logger) {
                        $logger->error("Failed to clear variant reports for {$code}: " . $e->getMessage());
                    }
                }

                // 5. Clear translation cache for this variant
                try {
                    $translationCache = new \kirbydesk\Simplify\Cache\TranslationCache();
                    $translationCache->clearLanguage($code);
                    if ($logger) {
                        $logger->info("Cleared translation cache for deleted variant: {$code}");
                    }
                } catch (\Exception $e) {
                    if ($logger) {
                        $logger->error("Failed to clear translation cache for {$code}: " . $e->getMessage());
                    }
                }
            }
        },

        /**
         * Remove deleted page from all variant configs
         */
        "page.delete:after" => function ($status, $page) {
            $kirby = Kirby::instance();
            $logger = $GLOBALS["simplify_instances"]["hooks_logger"] ?? null;

            // Get page UUID
            $pageUuid = $page->uuid();
            if (!$pageUuid) {
                return; // No UUID, nothing to clean up
            }

            $uuidString = $pageUuid->toString();
            // Normalize: remove page:// prefix if present
            if (strpos($uuidString, 'page://') === 0) {
                $uuidString = substr($uuidString, 7);
            }

            // Find all variant config files
            $configDir = \kirbydesk\Simplify\Helpers\PathHelper::getConfigPath();
            if (!is_dir($configDir)) {
                return;
            }

            $configFiles = glob($configDir . '/*.json');
            foreach ($configFiles as $configFile) {
                $variantCode = basename($configFile, '.json');

                // Load config
                $config = \kirbydesk\Simplify\Config\ConfigFileManager::loadVariantConfig($variantCode);
                if (!$config || !isset($config['pages']) || !is_array($config['pages'])) {
                    continue;
                }

                // Remove page from config
                $originalCount = count($config['pages']);
                $config['pages'] = array_values(array_filter($config['pages'], function($pageEntry) use ($uuidString) {
                    if (!isset($pageEntry['uuid'])) {
                        return true;
                    }

                    $entryUuid = $pageEntry['uuid'];
                    // Normalize entry UUID
                    if (strpos($entryUuid, 'page://') === 0) {
                        $entryUuid = substr($entryUuid, 7);
                    }

                    return $entryUuid !== $uuidString;
                }));

                // Save if something was removed
                if (count($config['pages']) < $originalCount) {
                    \kirbydesk\Simplify\Config\ConfigFileManager::saveVariantConfig($variantCode, $config, $logger);
                    if ($logger) {
                        $logger->info("Removed deleted page {$uuidString} from variant config: {$variantCode}");
                    }
                }
            }
        },
];
