<?php

namespace chrfickinger\Simplify\Core;

use Kirby\Cms\App;
use Exception;
use chrfickinger\Simplify\Providers\Gemini;
use chrfickinger\Simplify\Providers\OpenAI;
use chrfickinger\Simplify\Providers\ProviderInterface;
use chrfickinger\Simplify\Queue\TranslationQueue;
use chrfickinger\Simplify\Processing\FieldGrouper;
use chrfickinger\Simplify\Processing\ContentMasker;
use chrfickinger\Simplify\Processing\DiffDetector;
use chrfickinger\Simplify\Processing\TranslationFilter;
use chrfickinger\Simplify\Config\ConfigHelper;
use chrfickinger\Simplify\Helpers\ProviderFactory;
use chrfickinger\Simplify\Helpers\FieldFilter;
use chrfickinger\Simplify\Cache\TranslationCache;
use chrfickinger\Simplify\Logging\Logger;
use chrfickinger\Simplify\Logging\StatsLogger;
use chrfickinger\Simplify\Logging\WorkerLogger;
use chrfickinger\Simplify\Logging\ReportsLogger;
use chrfickinger\Simplify\Helpers\PageWriter;

/**
 * Translation Worker
 * Processes background translation jobs
 */
class TranslationWorker
{
    private TranslationQueue $queue;
    private Logger $logger;

    public function __construct()
    {
        $kirby = App::instance();

        // Ensure unlimited execution time for long translations
        set_time_limit(0);

        $this->queue = new TranslationQueue();

        // Use worker logger from global instances (or create fallback)
        $this->logger = $GLOBALS["simplify_instances"]["worker_logger"] ?? null;

        if (!$this->logger) {
            // Fallback: Create logger if not initialized (CLI context)
            $logPath = $kirby->root('logs') . '/simplify/logs/worker.log';
            $this->logger = new Logger($logPath, 'info');
        }
    }

    /**
     * Process a specific job by ID
     */
    public function processJob(string $jobId): bool
    {
        $job = $this->queue->getJob($jobId);

        if (!$job) {
            $this->logger->error("Job not found: {$jobId}");
            echo "ERROR: Job not found: {$jobId}\n";
            return false;
        }

        // Create variant-specific worker logger
        $workerLogger = new WorkerLogger($job['variantCode']);
        $startTime = microtime(true);

        $this->logger->info("Starting job {$jobId} for page {$job['pageId']}");
        $workerLogger->logJobStart($job['pageId'], $job['pageTitle'] ?? $job['pageId']);
        echo "Starting job {$jobId} for page {$job['pageId']}\n";

        try {
            // Set status to processing
            echo "Setting job status to processing...\n";
            $this->queue->setJobStatus($jobId, 'processing');
            echo "Job status set to processing\n";

            // Load the source page
            $kirby = App::instance();
            $page = $kirby->page($job['pageId']);

            if (!$page) {
                throw new Exception("Page not found: {$job['pageId']}");
            }

            // Load variant config to check if content file exists
            $variantConfig = ConfigHelper::getVariantConfig($job['variantCode']);
            if (!$variantConfig) {
                throw new Exception("No configuration found for variant: {$job['variantCode']}");
            }

            // Check if variant content file exists
            $languageCode = $variantConfig['language_code'];
            $contentFile = $page->contentFile($languageCode);
            $variantExists = file_exists($contentFile);

            // Read source content for diff detection (always from default language)
            $sourceContent = $page->content()->toArray();

            // Read target content for merging (from variant if exists, otherwise from source)
            // This preserves existing translations when updating
            if ($variantExists) {
                $targetContent = $page->content($languageCode)->toArray();
            } else {
                $targetContent = $sourceContent;
            }

            // Determine if we should force full translation
            $isManual = $job['isManual'] ?? true; // Default to manual for backward compatibility

            // Force full translation if:
            // 1. Variant doesn't exist, OR
            // 2. This is a manual translation
            if (!$variantExists || $isManual) {
                $reason = !$variantExists ? 'variant does not exist' : 'manual translation requested';

                // Get all fields but exclude 'title' (hardcoded exclusion - Kirby system field)
                $allFields = array_keys($sourceContent);
                $allFields = array_filter($allFields, fn($f) => $f !== 'title');

                // Use FieldFilter to properly filter fields (includes field type whitelist check)
                $filteredFields = \chrfickinger\Simplify\Helpers\FieldFilter::filterFields($page, $allFields, $variantConfig);

                $changes = [
                    'strategy' => 'full',
                    'fields' => $filteredFields,
                    'changePercentage' => 100,
                    'totalFields' => count($filteredFields),
                    'changedFields' => count($filteredFields),
                ];
                $this->logger->info("Forcing full translation - reason: {$reason}");
            } else {
                $changes = DiffDetector::detectChanges($sourceContent, $job['sourceSnapshot']);

                // Exclude 'title' (hardcoded exclusion - Kirby system field)
                $changes['fields'] = array_filter($changes['fields'], fn($f) => $f !== 'title');

                // Use FieldFilter to properly filter detected changes (includes field type whitelist check)
                $changes['fields'] = \chrfickinger\Simplify\Helpers\FieldFilter::filterFields($page, $changes['fields'], $variantConfig);
            }

            $this->logger->info("Diff detection: {$changes['strategy']} - {$changes['changedFields']} of {$changes['totalFields']} fields changed");

            // Log which fields will be translated with their types
            $this->logger->info("Fields to translate (" . count($changes['fields']) . "): " . implode(', ', $changes['fields']));

            // Log field types for debugging
            foreach ($changes['fields'] as $fieldName) {
                $fieldType = TranslationFilter::getFieldType($page, $fieldName);
                $this->logger->info("  - {$fieldName}: type={$fieldType}");
            }

            // Update job with strategy and fields
            $this->queue->updateJobStrategy($jobId, $changes['strategy'], $changes['fields']);

            // Reload job to get updated data
            $job = $this->queue->getJob($jobId);

            // Translate fields
            $result = $this->translateFields($job, $page);

            if ($result['success']) {
                // Merge target fields with translated fields
                // Target fields are used as base (preserves existing translations), translated fields override them
                $allFields = array_merge($targetContent, $result['translatedFields']);

                // Add Simplify metadata field
                $provider = $variantConfig['provider'] ?? 'unknown';
                $timestamp = date('Y-m-d H:i:s');
                $allFields['simplify'] = "{$provider} | {$timestamp}";

                // Write all fields to page
                $pageWriter = new PageWriter($this->logger);
                $pageWriter->writeToPageManual($page, $job['variantCode'], $allFields);

                // Mark as completed
                $this->queue->setJobStatus($jobId, 'completed');

                // Update final result
                $this->queue->updateJobResult($jobId, [
                    'translatedFields' => count($result['translatedFields']),
                    'tokensUsed' => $result['tokensUsed'],
                    'promptTokens' => $result['promptTokens'] ?? 0,
                    'completionTokens' => $result['completionTokens'] ?? 0,
                    'cost' => $result['cost'],
                ]);

                // Reload job to get updated result data
                $job = $this->queue->getJob($jobId);

                // Calculate duration
                $duration = microtime(true) - $startTime;

                // Log success to worker log
                $workerLogger->logJobSuccess(
                    $job['pageId'],
                    $job['pageTitle'] ?? $job['pageId'],
                    $result['tokensUsed'] ?? 0,
                    $result['cost'] ?? 0,
                    $duration
                );

                // Finalize: Write to report and delete job
                $this->finalizeJob($job, $result);

                $this->logger->info("Job {$jobId} completed successfully");
                return true;
            } else {
                throw new Exception($result['error'] ?? 'Translation failed');
            }

        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;

            $this->logger->error("Job {$jobId} failed: " . $e->getMessage());
            $workerLogger->logJobFailure(
                $job['pageId'],
                $job['pageTitle'] ?? $job['pageId'],
                $e->getMessage(),
                $duration
            );

            // Mark as failed
            try {
                $this->queue->setJobStatus($jobId, 'failed', $e->getMessage());
            } catch (Exception $statusError) {
                $this->logger->error("Failed to set job status to failed: " . $statusError->getMessage());
            }

            // Reload job to get error (might fail if job was corrupted)
            try {
                $job = $this->queue->getJob($jobId);

                if ($job) {
                    // Write failure to log and delete job
                    $this->finalizeJob($job, ['success' => false, 'error' => $e->getMessage()]);
                } else {
                    $this->logger->error("Could not reload job {$jobId} for finalization");
                }
            } catch (Exception $finalizeError) {
                $this->logger->error("Failed to finalize job {$jobId}: " . $finalizeError->getMessage());
            }

            return false;
        } catch (\Throwable $e) {
            // Catch any other errors (PHP errors, etc.)
            $duration = microtime(true) - $startTime;

            $this->logger->error("Job {$jobId} failed with fatal error: " . $e->getMessage());

            if (isset($workerLogger) && isset($job)) {
                $workerLogger->logJobFailure(
                    $job['pageId'],
                    $job['pageTitle'] ?? $job['pageId'],
                    "Fatal error: " . $e->getMessage(),
                    $duration
                );
            }

            try {
                $this->queue->setJobStatus($jobId, 'failed', "Fatal error: " . $e->getMessage());
                $job = $this->queue->getJob($jobId);

                if ($job) {
                    $this->finalizeJob($job, ['success' => false, 'error' => "Fatal error: " . $e->getMessage()]);
                }
            } catch (\Throwable $cleanupError) {
                $this->logger->error("Failed to cleanup after fatal error: " . $cleanupError->getMessage());
            }

            return false;
        }
    }

    /**
     * Process the next pending job in queue
     */
    public function processNextJob(): bool
    {
        $job = $this->queue->getNextPendingJob();

        if (!$job) {
            $this->logger->info("No pending jobs in queue");
            return false;
        }

        return $this->processJob($job['id']);
    }

    /**
     * Translate fields individually (field-by-field) with progress tracking
     */
    private function translateFields(array $job, $page): array
    {
        $jobId = $job['id'];
        $pageId = $page->id(); // Store page ID for reloading
        $variantCode = $job['variantCode'];
        $fieldsToTranslate = $job['fieldsToTranslate'];

        // Load variant config
        $variantConfig = ConfigHelper::getVariantConfig($variantCode);

        if (!$variantConfig) {
            throw new Exception("No configuration found for variant: {$variantCode}");
        }

        // Load project settings from source language config
        $sourceLanguage = $variantConfig['source_language'] ?? null;
        if ($sourceLanguage) {
            $kirby = \Kirby\Cms\App::instance();

            // Load project-specific config (keywords, prompt)
            $projectConfigPath = \chrfickinger\Simplify\Helpers\PathHelper::getConfigPath($sourceLanguage . '.json');
            if (file_exists($projectConfigPath)) {
                $projectConfigJson = file_get_contents($projectConfigPath);
                $projectConfig = json_decode($projectConfigJson, true);

                $variantConfig['project_prompt'] = $projectConfig['project_prompt'] ?? '';
            }
        }

        // Hardcoded settings for field-by-field translation
        $retryLimit = 3; // Retry up to 3 times on failure
        $delayBetweenFields = 2; // 2 seconds between fields (rate limiting)

        // Get provider (delegated to ProviderFactory)
        $provider = ProviderFactory::createFromVariantConfig($variantConfig);

        if (!$provider) {
            throw new Exception("Could not create provider for variant: {$variantCode}");
        }

        // Initialize BudgetManager for this provider
        $providerId = $this->getProviderIdFromVariantConfig($variantConfig);
        $budgetManager = new BudgetManager($providerId);

        // Create translation service
        $translationService = new TranslationService($this->logger, $budgetManager, $provider);

        $totalFields = count($fieldsToTranslate);
        $translatedFields = [];
        $totalTokens = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalCost = 0;

        $this->logger->info("Processing {$totalFields} fields individually with {$delayBetweenFields}s delay between fields");

        foreach ($fieldsToTranslate as $fieldIndex => $fieldName) {
            $fieldNumber = $fieldIndex + 1;
            $retries = 0;
            $success = false;

            $this->logger->info("Processing field {$fieldNumber}/{$totalFields}: {$fieldName}");

            // Update progress
            $this->queue->updateJobProgress(
                $jobId,
                $fieldNumber - 1,
                $totalFields,
                $fieldName
            );

            while ($retries <= $retryLimit && !$success) {
                try {
                    // Reload page to get fresh data
                    $kirby = App::instance();
                    $page = $kirby->page($pageId);

                    if (!$page) {
                        throw new Exception("Page not found: {$pageId}");
                    }

                    // Translate this single field
                    $result = $this->translateSingleField($page, $fieldName, $variantConfig, $translationService, $budgetManager);

                    if ($result['success']) {
                        $translatedFields[$fieldName] = $result['translation'];

                        // Track tokens
                        $inputTokens = $result['usage']['promptTokens'] ?? 0;
                        $outputTokens = $result['usage']['completionTokens'] ?? 0;
                        $fieldTokens = $result['usage']['totalTokens'] ?? 0;
                        $totalTokens += $fieldTokens;
                        $totalInputTokens += $inputTokens;
                        $totalOutputTokens += $outputTokens;

                        // Calculate cost using model config pricing
                        $modelConfigId = $variantConfig['provider'] ?? '';
                        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
                        $pricing = $modelConfig['pricing'] ?? null;

                        $cost = $this->calculateCost($modelConfigId, $inputTokens, $outputTokens, ['pricing' => $pricing]);

                        // Add to total cost only if pricing data is available
                        if ($cost !== null) {
                            $totalCost += $cost;
                        }

                        // Record in BudgetManager (aggregated tracking)
                        $budgetManager->record($inputTokens, $outputTokens, $cost);

                        $success = true;
                        $costDisplay = $cost !== null ? "\${$cost}" : '?';
                        $this->logger->info("Field {$fieldName} completed successfully (tokens: {$fieldTokens}, cost: {$costDisplay})");
                    } else {
                        throw new Exception($result['message'] ?? 'Unknown error');
                    }

                } catch (Exception $e) {
                    $retries++;

                    // Check if it's a rate limit error (429)
                    $isRateLimit = strpos($e->getMessage(), '429') !== false ||
                                   strpos($e->getMessage(), 'quota') !== false ||
                                   strpos($e->getMessage(), 'rate limit') !== false;

                    $isBudgetError = strpos($e->getMessage(), 'Budget limit exceeded') !== false;

                    // Don't retry budget errors - they won't succeed
                    if ($isBudgetError) {
                        $errorMsg = "Failed to translate field {$fieldName}: " . $e->getMessage();
                        $this->logger->error($errorMsg);
                        throw new Exception($errorMsg);
                    }

                    if ($retries <= $retryLimit) {
                        // Longer wait for rate limit errors
                        $waitTime = $isRateLimit ? 30 : 5;
                        $this->logger->warning("Field {$fieldName} failed (attempt {$retries}), waiting {$waitTime}s before retry: " . $e->getMessage());
                        sleep($waitTime);
                    } else {
                        // Final retry failed - log and throw exception to abort entire job
                        $errorMsg = "Failed to translate field {$fieldName} after {$retryLimit} retries: " . $e->getMessage();
                        $this->logger->error($errorMsg);
                        throw new Exception($errorMsg);
                    }
                }
            }

            // Safety check
            if (!$success) {
                $errorMsg = "Field {$fieldName} did not complete successfully after {$retries} attempts";
                $this->logger->error($errorMsg);
                throw new Exception($errorMsg);
            }

            // Update progress after field completion
            $this->queue->updateJobProgress(
                $jobId,
                $fieldNumber,
                $totalFields,
                null
            );

            // Rate limiting: Wait between fields (except for last field)
            if ($fieldNumber < $totalFields && $delayBetweenFields > 0) {
                $this->logger->info("Waiting {$delayBetweenFields}s before next field (rate limiting)");
                sleep($delayBetweenFields);
            }
        }

        return [
            'success' => true,
            'translatedFields' => $translatedFields,
            'tokensUsed' => $totalTokens,
            'promptTokens' => $totalInputTokens,
            'completionTokens' => $totalOutputTokens,
            'cost' => $totalCost,
        ];
    }

    /**
     * Translate a single field with caching, budget checks, and error handling
     */
    private function translateSingleField($page, string $fieldName, array $variantConfig, TranslationService $translationService, BudgetManager $budgetManager): array
    {
        $cache = new TranslationCache();
        $pageUuid = $page->uuid() ? $page->uuid()->toString() : null;
        $languageCode = $variantConfig['language_code'];

        // Check if field should be translated
        $filteredFields = FieldFilter::filterFields($page, [$fieldName], $variantConfig);
        if (empty($filteredFields)) {
            return [
                'success' => false,
                'message' => "Field {$fieldName} is filtered out (opt-out or disabled)",
                'translation' => null,
                'usage' => ['totalTokens' => 0]
            ];
        }

        // Get field type and check if it's enabled
        $blueprint = $page->blueprint();
        $fieldConfig = $blueprint->field($fieldName);
        $fieldType = $fieldConfig['type'] ?? 'text';

        $fieldTypeConfig = $variantConfig['field_type_instructions'][$fieldType] ?? null;
        if (!$fieldTypeConfig) {
            return [
                'success' => false,
                'message' => "No configuration for field type: {$fieldType}",
                'translation' => null,
                'usage' => ['totalTokens' => 0]
            ];
        }

        // Get source content
        $sourceContent = $page->content()->get($fieldName)->value();
        if (empty(trim($sourceContent))) {
            return [
                'success' => true,
                'translation' => '',
                'usage' => ['totalTokens' => 0]
            ];
        }

        // Normalize typographic quotes to ASCII to prevent JSON issues
        $sourceContent = $this->normalizeQuotes($sourceContent);

        // Build prompts BEFORE cache check (needed for prompt hash)
        $fieldInstruction = $fieldTypeConfig['instruction'] ?? '';

        // Field type-based prompt strategy - get category from fieldtype config
        $categoryPrompts = $variantConfig['category_prompts'] ?? [];
        $fieldCategory = $fieldTypeConfig['category'] ?? 'default';

        $singleFieldPrompt = $categoryPrompts[$fieldCategory] ?? $categoryPrompts['default'] ?? '';

        // Use PromptBuilder to build the system prompt (includes project_prompt, etc.)
        $fullSystemPrompt = \chrfickinger\Simplify\Processing\PromptBuilder::buildSystemPromptFromConfig(
            $sourceContent,
            $variantConfig,
            $fieldType,
            $fieldInstruction,
            $singleFieldPrompt
        );

        // Calculate prompt hash for cache validation
        $promptHash = md5($fullSystemPrompt);

        // Check if target file exists (cache should be invalidated if not)
        $targetFileExists = $page->translation($languageCode)->exists();

        // Check cache (only if target file exists)
        $cachedTranslation = null;
        if ($pageUuid && $targetFileExists) {
            $cachedTranslation = $cache->get($pageUuid, $languageCode, $fieldName, $sourceContent, $promptHash);
        }

        if ($cachedTranslation !== null) {
            $this->logger->info("Cache HIT for field: {$fieldName}");
            return [
                'success' => true,
                'translation' => $cachedTranslation,
                'usage' => ['totalTokens' => 0] // No API call needed
            ];
        }

        // Cache miss - translate
        $this->logger->info("Cache MISS for field: {$fieldName} - translating...");

        // Additional masking for special content (URLs, etc.)
        $maskingConfig = $variantConfig['masking'] ?? [];
        $maskedData = FieldGrouper::maskFieldContents([$fieldName => $sourceContent], $maskingConfig);
        $fullyMaskedContent = $maskedData['contents'][$fieldName] ?? $sourceContent;
        $maskingMap = $maskedData['maps'];

        // Estimate tokens and cost for budget check
        $estimatedInputTokens = BudgetManager::estimateTokens($fullSystemPrompt . $fullyMaskedContent);
        $estimatedOutputTokens = BudgetManager::estimateTokens($fullyMaskedContent) * 2; // Rough estimate: output might be ~2x input for explanations

        // Get pricing from model config for cost calculation
        $modelConfigId = $variantConfig['provider'];
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
        $pricing = $modelConfig['pricing'] ?? null;

        $estimatedCost = $this->calculateCost(
            $modelConfigId,
            $estimatedInputTokens,
            $estimatedOutputTokens,
            ['pricing' => $pricing]
        );

        // Budget check BEFORE API call (only if cost can be calculated)
        if ($estimatedCost !== null) {
            try {
                $budgetManager->ensureWithinLimit($estimatedInputTokens + $estimatedOutputTokens, $estimatedCost);
            } catch (Exception $e) {
                // Budget exceeded - return error without making API call
                $this->logger->error("Budget limit exceeded for field {$fieldName}: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Budget limit exceeded: ' . $e->getMessage(),
                    'translation' => null,
                    'usage' => ['totalTokens' => 0]
                ];
            }
        }

        // Call provider
        $provider = ProviderFactory::createFromVariantConfig($variantConfig);
        $messages = [
            ['role' => 'system', 'content' => $fullSystemPrompt],
            ['role' => 'user', 'content' => $fullyMaskedContent]
        ];

        // Get model name from config_id (e.g., "openai/gpt-4o" -> "gpt-4o")
        $modelConfigId = $variantConfig['provider'];
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
        $modelName = $modelConfig['model'] ?? $modelConfigId;

        // Build options based on model capabilities
        $options = [];

        // Only set temperature if model supports it AND variant config provides it
        if (isset($modelConfig['supports_temperature']) && $modelConfig['supports_temperature'] === true) {
            if (isset($variantConfig['temperature'])) {
                $options['temperature'] = $variantConfig['temperature'];
            }
        }

        // Add output_token_limit for Anthropic models
        if (isset($modelConfig['output_token_limit'])) {
            $options['output_token_limit'] = $modelConfig['output_token_limit'];
        }

        $result = $provider->complete($messages, $modelName, $options);

        // Process result
        $translatedContent = trim($result->text);

        // De-mask URL/special content
        $translatedContent = FieldGrouper::demaskFieldContents([$fieldName => $translatedContent], $maskingMap)[$fieldName] ?? $translatedContent;

        // Normalize text (remove AI artifacts, code blocks, etc.)
        $translatedContent = \chrfickinger\Simplify\Processing\PromptBuilder::normalizeText($translatedContent, $sourceContent, $fieldType);

        // Compact JSON for structured fields
        // Some AI models return pretty-printed JSON which breaks Kirby's content files
        if (in_array($fieldType, ['blocks', 'layout', 'structure', 'object'])) {
            $decoded = json_decode($translatedContent, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                // Re-encode compactly without formatting
                $translatedContent = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        // Store in cache with prompt hash
        if ($pageUuid) {
            $cache->set($pageUuid, $languageCode, $fieldName, $fieldType, $sourceContent, $translatedContent, $promptHash);
            $this->logger->info("Cached translation for field: {$fieldName}");
        }

        return [
            'success' => true,
            'translation' => $translatedContent,
            'usage' => [
                'totalTokens' => $result->promptTokens + $result->completionTokens,
                'promptTokens' => $result->promptTokens,
                'completionTokens' => $result->completionTokens
            ]
        ];
    }



    /**
     * Finalize job: Write to all 3 databases (Stats, Reports, Budget is already recorded) and delete job file
     */
    private function finalizeJob(array $job, array $result): void
    {
        $kirby = App::instance();
        $statsLogger = new StatsLogger();
        $reportsLogger = new ReportsLogger();

        // Get page for UUID
        $page = $kirby->page($job['pageId']);
        $pageUuid = $page && $page->uuid() ? $page->uuid()->toString() : null;

        // Get variant config for provider info
        $variantConfig = ConfigHelper::getVariantConfig($job['variantCode']);
        $modelConfigId = $variantConfig['provider'] ?? ''; // e.g., "openai/gpt-4o"

        // Load model config to get provider_type and actual model name
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
        $providerId = $modelConfig['provider_type'] ?? $modelConfigId; // e.g., "openai"
        $modelName = $modelConfig['model'] ?? $modelConfigId; // e.g., "gpt-4o"

        // Determine action based on isManual flag in job
        $isManual = $job['isManual'] ?? true; // Default to manual for backward compatibility
        $action = $isManual ? 'manual' : 'auto';

        // Prepare common data
        $logData = [
            'pageId' => $job['pageId'],
            'pageUuid' => $pageUuid,
            'pageTitle' => $job['pageTitle'],
            'languageCode' => $job['variantCode'],
            'providerId' => $providerId,
            'model' => $modelName,
            'action' => $action,
            'strategy' => $job['strategy'] ?? 'unknown',
            'status' => $result['success'] ? 'SUCCESS' : 'FAILED',
            'success' => $result['success'],
            'fieldsTranslated' => $result['success'] ? ($job['result']['translatedFields'] ?? 0) : 0,
            'inputTokens' => $result['success'] ? ($job['result']['promptTokens'] ?? 0) : 0,
            'outputTokens' => $result['success'] ? ($job['result']['completionTokens'] ?? 0) : 0,
            'cost' => $result['success'] ? ($job['result']['cost'] ?? null) : null,
            'error' => $result['success'] ? null : ($job['error'] ?? $result['error'] ?? 'Unknown error'),
        ];

        // Write to Provider Stats (stats.sqlite)
        $statsLogger->logTranslation($logData);

        // Write to Variant Reports (reports.sqlite)
        $reportsLogger->logTranslation($logData);

        // Budget is already recorded during translation in translateFields via budgetManager->record()

        // Delete job file
        $this->queue->deleteJob($job['id']);
        $this->logger->info("Job {$job['id']} finalized and deleted");
    }



    /**
     * Calculate cost based on model and tokens using provider pricing
     *
     * @return float|null Cost in dollars, or null if pricing data is not available
     */
    private function calculateCost(string $model, int $inputTokens, int $outputTokens, array $providerConfig): ?float
    {
        $pricing = $providerConfig['pricing'] ?? null;

        if (!$pricing) {
            // No pricing data available - return null
            return null;
        }

        // Use provider-specific pricing
        $inputPrice = $pricing['input'] ?? 0;
        $outputPrice = $pricing['output'] ?? 0;
        $perTokens = $pricing['per_tokens'] ?? BudgetManager::DEFAULT_PER_TOKENS;

        $inputCost = ($inputTokens / $perTokens) * $inputPrice;
        $outputCost = ($outputTokens / $perTokens) * $outputPrice;

        return $inputCost + $outputCost;
    }

    /**
     * Get provider ID from variant config
     */
    private function getProviderIdFromVariantConfig(array $variantConfig): string
    {
        // Get model config ID from variant config (e.g., "openai/gpt-4o")
        $modelConfigId = $variantConfig['provider'] ?? '';

        // Load model config to get provider type
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);

        // Return provider_type (e.g., "openai")
        return $modelConfig['provider_type'] ?? $modelConfigId;
    }

    /**
     * Normalize typographic quotes to escaped ASCII quotes
     * Prevents JSON parsing issues when AI removes/changes special characters
     */
    private function normalizeQuotes(string $content): string
    {
        // Double quotes: „ " " → \" (escaped for JSON safety)
        $content = str_replace(["\u{201E}", "\u{201C}", "\u{201D}"], '\\"', $content);

        // Single quotes: ‚ ' ' → '
        $content = str_replace(["\u{201A}", "\u{2018}", "\u{2019}"], "'", $content);

        return $content;
    }

}
