<?php

namespace chrfickinger\Simplify\Core;

use Kirby\Cms\Page;
use Kirby\Cms\App as Kirby;
use chrfickinger\Simplify\Providers\ProviderInterface;
use chrfickinger\Simplify\Providers\Gemini;
use chrfickinger\Simplify\Providers\OpenAI;
use chrfickinger\Simplify\Providers\Anthropic;
use chrfickinger\Simplify\Providers\Mistral;
use chrfickinger\Simplify\Processing\TranslationFilter;
use chrfickinger\Simplify\Processing\FieldGrouper;
use chrfickinger\Simplify\Processing\ContentMasker;
use chrfickinger\Simplify\Config\ConfigHelper;
use chrfickinger\Simplify\Helpers\PageWriter;
use chrfickinger\Simplify\Logging\Logger;
use chrfickinger\Simplify\Logging\StatsLogger;

/**
 * TranslationService - Option B Implementation
 *
 * Implements grouped API calls by field type with complete filtering hierarchy
 *
 * Features:
 * - 5-level filtering (mode, template, changes, field type, field name)
 * - Grouped API calls by field type (max 8 calls per page)
 * - Email/phone masking and de-masking
 * - Post-processing (AI artifacts removal)
 */
class TranslationService
{
    private Logger $logger;
    private ?BudgetManager $budget;
    private ?ProviderInterface $provider;
    private PageWriter $pageWriter;
    private StatsLogger $statsLogger;

    public function __construct(
        Logger $logger,
        ?BudgetManager $budget,
        ?ProviderInterface $provider = null
    ) {
        $this->logger = $logger;
        $this->budget = $budget;
        $this->provider = $provider;
        $this->pageWriter = new PageWriter($logger);
        $this->statsLogger = new StatsLogger();
    }

    /**
     * Translate a page to target language using Option B (grouped field types)
     *
     * @param Page $page The page to translate
     * @param string $targetLanguageCode Target language code (e.g., 'de-x-ls')
     * @param bool $preview If true, return preview without writing
     * @return array Result with status, fields, usage, etc.
     */
    public function translatePage(
        Page $page,
        string $targetLanguageCode,
        bool $preview = false
    ): array {
        $startTime = microtime(true);

        try {
            // Load variant config (Single Source of Truth)
            $config = ConfigHelper::getVariantConfig($targetLanguageCode);

            if (!$config) {
                throw new \Exception("No configuration found for language: {$targetLanguageCode}");
            }

            $this->logger->info("Starting translation for page {$page->id()} to {$targetLanguageCode}");

            // Get fields that should be translated (all 5 filtering levels)
            $translatableFields = TranslationFilter::getTranslatableFields($page, $config);

            if (empty($translatableFields)) {
                $this->logger->info("No fields to translate after filtering");
                return [
                    'success' => true,
                    'message' => 'No fields to translate',
                    'fields' => [],
                    'usage' => null
                ];
            }

            $this->logger->info("Found " . count($translatableFields) . " fields to translate: " . implode(', ', $translatableFields));

            // Group fields by field type
            $fieldsByType = FieldGrouper::groupFieldsByType($page, $translatableFields);

            $this->logger->info("Grouped into " . count($fieldsByType) . " field types: " . implode(', ', array_keys($fieldsByType)));

            // Get provider for this language's model
            $providerData = $this->getProviderForLanguage($config);

            if (!$providerData || !$providerData['provider']) {
                throw new \Exception("Could not create provider for language: {$targetLanguageCode}");
            }

            $provider = $providerData['provider'];
            $config['provider_id'] = $providerData['provider_id'];

            // Translate each field type group
            $translatedFields = [];
            $totalPromptTokens = 0;
            $totalCompletionTokens = 0;

            foreach ($fieldsByType as $fieldType => $fieldNames) {
                $this->logger->info("Translating field type '{$fieldType}' with " . count($fieldNames) . " fields");

                $result = $this->translateFieldGroup(
                    $page,
                    $fieldType,
                    $fieldNames,
                    $config,
                    $provider
                );

                if ($result['success']) {
                    $translatedFields = array_merge($translatedFields, $result['fields']);
                    $totalPromptTokens += $result['promptTokens'];
                    $totalCompletionTokens += $result['completionTokens'];
                } else {
                    $this->logger->error("Failed to translate field type '{$fieldType}': {$result['error']}");
                    // Continue with other field types
                }
            }

            // Record usage (if budget manager is available)
            if ($this->budget) {
                // Get model name from config_id
                $modelConfigId = $config['provider'] ?? 'unknown';
                $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
                $model = $modelConfig['model'] ?? $modelConfigId;
                $this->budget->record(
                    $page->id(),
                    $model,
                    $totalPromptTokens,
                    $totalCompletionTokens,
                    "Translated {$targetLanguageCode} (" . count($translatedFields) . " fields)"
                );
            }

            // Write to page (if not preview)
            if (!$preview && !empty($translatedFields)) {
                $this->pageWriter->writeToPage($page, $targetLanguageCode, $translatedFields);
            }

            $duration = microtime(true) - $startTime;

            return [
                'success' => true,
                'message' => 'Translation completed',
                'fields' => $translatedFields,
                'usage' => [
                    'promptTokens' => $totalPromptTokens,
                    'completionTokens' => $totalCompletionTokens,
                    'totalTokens' => $totalPromptTokens + $totalCompletionTokens,
                    'model' => $model,
                    'duration' => round($duration, 2)
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error("Translation failed for {$page->id()}: " . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'fields' => [],
                'usage' => null
            ];
        }
    }

    /**
     * Translate a group of fields of the same type (Option B)
     *
     * @param Page $page The page
     * @param string $fieldType Field type (e.g., 'text', 'blocks')
     * @param array $fieldNames Array of field names
     * @param array $config Variant configuration
     * @param ProviderInterface $provider AI provider
     * @return array Result with success, fields, tokens
     */
    private function translateFieldGroup(
        Page $page,
        string $fieldType,
        array $fieldNames,
        array $config,
        ProviderInterface $provider
    ): array {
        // Extract provider ID from config for stats logging
        $modelConfigId = $config['provider'] ?? null;
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
        $providerId = $modelConfig['provider_type'] ?? null;
        try {
            // Get field type configuration
            $fieldTypeConfig = $config['field_type_instructions'][$fieldType] ?? null;

            if (!$fieldTypeConfig) {
                throw new \Exception("No configuration for field type: {$fieldType}");
            }

            // Get field contents
            $fieldContents = FieldGrouper::getFieldContents($page, $fieldNames);

            // Mask contents (email/phone)
            $maskingConfig = $config['masking'] ?? [];
            $maskedData = FieldGrouper::maskFieldContents($fieldContents, $maskingConfig);
            $maskedContents = $maskedData['contents'];
            $maskingMaps = $maskedData['maps'];

            // Build API request
            $apiRequest = $this->buildApiRequest(
                $fieldType,
                $maskedContents,
                $fieldTypeConfig,
                $config
            );

            // Estimate tokens for budget check
            $estimatedPrompt = BudgetManager::estimateTokens(json_encode($apiRequest));
            $estimatedCompletion = 0;
            foreach ($maskedContents as $content) {
                $estimatedCompletion += BudgetManager::estimateTokens($content) * 1.2;
            }

            // Check budget (if budget manager is available)
            // Get model name from config_id
            $modelConfigId = $config['provider'] ?? null;
            $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
            $model = $modelConfig['model'] ?? $modelConfigId;
            if (!$model) {
                throw new \Exception("No provider configured for this variant");
            }
            if ($this->budget) {
                $this->budget->ensureWithinLimit(
                    $estimatedPrompt,
                    (int) $estimatedCompletion,
                    $model
                );
            }

            // Call provider
            $pageId = $page->id();
            $languageCode = $config['language_code'] ?? null;
            $result = $this->callProvider($provider, $apiRequest, $config, $pageId, $providerId, $languageCode, $model);

            // De-mask contents
            $translatedFields = FieldGrouper::demaskFieldContents($result['fields'], $maskingMaps);

            return [
                'success' => true,
                'fields' => $translatedFields,
                'promptTokens' => $result['promptTokens'],
                'completionTokens' => $result['completionTokens']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fields' => [],
                'promptTokens' => 0,
                'completionTokens' => 0
            ];
        }
    }

    /**
     * Build API request for grouped fields
     *
     * @param string $fieldType Field type
     * @param array $maskedContents Field contents (masked)
     * @param array $fieldTypeConfig Field type configuration
     * @param array $config Variant configuration
     * @return array API request structure
     */
    private function buildApiRequest(
        string $fieldType,
        array $maskedContents,
        array $fieldTypeConfig,
        array $config
    ): array {
        // Build system prompt
        $systemPrompt = $config['ai_system_prompt'] ?? '';
        $fieldInstruction = $fieldTypeConfig['instruction'] ?? '';
        $fullSystemPrompt = $systemPrompt . "\n\n" . $fieldInstruction;

        // Build user content with all fields
        $userContent = "Translate the following fields:\n\n";
        foreach ($maskedContents as $fieldName => $content) {
            $userContent .= "Field: {$fieldName}\nContent: {$content}\n\n";
        }

        return [
            'field_type' => $fieldType,
            'system_prompt' => $fullSystemPrompt,
            'user_content' => $userContent,
            'fields' => $maskedContents,
            'source_language' => $config['source_language'] ?? 'de',
            'target_language' => $config['language_code'] ?? '',
            'format' => $fieldTypeConfig['format'] ?? 'text',
            'preserve_formatting' => $fieldTypeConfig['preserve_formatting'] ?? false
        ];
    }

    /**
     * Call AI provider with grouped fields
     *
     * @param ProviderInterface $provider AI provider
     * @param array $apiRequest API request
     * @param array $config Variant configuration
     * @param string|null $pageId Page ID for logging
     * @param string|null $providerId Provider ID for logging
     * @param string|null $languageCode Language/variant code for logging
     * @return array Result with fields and token counts
     */
    private function callProvider(
        ProviderInterface $provider,
        array $apiRequest,
        array $config,
        ?string $pageId = null,
        ?string $providerId = null,
        ?string $languageCode = null,
        ?string $model = null
    ): array {
        // Build messages in provider format
        $messages = [
            [
                'role' => 'system',
                'content' => $apiRequest['system_prompt']
            ],
            [
                'role' => 'user',
                'content' => $apiRequest['user_content']
            ]
        ];

        // If model not provided, extract from config
        if (!$model) {
            $modelConfigId = $config['provider'] ?? null;
            $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
            $model = $modelConfig['model'] ?? $modelConfigId;
            if (!$model) {
                throw new \Exception("No provider configured for this variant");
            }
        }

        $success = false;
        $error = null;

        try {
            // Build options
            $options = [];

            // Load model config to check if temperature is supported
            $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($model);
            if ($modelConfig && isset($modelConfig['supports_temperature']) && $modelConfig['supports_temperature'] === true) {
                // Only set temperature if variant config provides it
                if (isset($config['temperature'])) {
                    $options['temperature'] = $config['temperature'];
                }
            }

            // Add output_token_limit for Anthropic models
            if ($modelConfig && isset($modelConfig['output_token_limit'])) {
                $options['output_token_limit'] = $modelConfig['output_token_limit'];
            }

            // Call provider
            $result = $provider->complete($messages, $model, $options);

            $success = true;

            // Parse response - expect format: "Field: fieldname\nContent: translated content\n\n"
            $translatedFields = $this->parseGroupedResponse($result->text, array_keys($apiRequest['fields']));

            // Calculate cost and log API call
            if ($providerId) {
                $pricing = $config['pricing'] ?? [];
                $cost = $this->calculateApiCost($result->promptTokens, $result->completionTokens, $model, $pricing);

                $this->statsLogger->logApiCall(
                    $providerId,
                    $model,
                    $result->promptTokens,
                    $result->completionTokens,
                    $cost,
                    true,
                    null,
                    'translation',
                    $pageId,
                    $languageCode
                );
            }

            return [
                'fields' => $translatedFields,
                'promptTokens' => $result->promptTokens,
                'completionTokens' => $result->completionTokens
            ];

        } catch (\Exception $e) {
            // Log failed API call
            if ($providerId) {
                $this->statsLogger->logApiCall(
                    $providerId,
                    $model,
                    0,
                    0,
                    0,
                    false,
                    $e->getMessage(),
                    'translation',
                    $pageId,
                    $languageCode
                );
            }
            throw $e;
        }
    }

    /**
     * Calculate API call cost based on token usage
     *
     * @param int $promptTokens Prompt tokens used
     * @param int $completionTokens Completion tokens used
     * @param string $model Model name
     * @param array $pricing Pricing configuration
     * @return float Cost in USD
     */
    private function calculateApiCost(
        int $promptTokens,
        int $completionTokens,
        string $model,
        array $pricing
    ): float {
        // Default pricing (GPT-3.5-turbo as fallback)
        $inputPrice = 0.0015; // per 1k tokens
        $outputPrice = 0.002; // per 1k tokens

        // Look up model-specific pricing
        foreach ($pricing as $pattern => $prices) {
            if (strpos($model, $pattern) !== false) {
                $inputPrice = $prices['input'] ?? $inputPrice;
                $outputPrice = $prices['output'] ?? $outputPrice;
                break;
            }
        }

        $inputCost = ($promptTokens / 1000) * $inputPrice;
        $outputCost = ($completionTokens / 1000) * $outputPrice;

        return $inputCost + $outputCost;
    }

    /**
     * Parse AI response for grouped fields
     *
     * Expected format:
     * Field: fieldname1
     * Content: translated content 1
     *
     * Field: fieldname2
     * Content: translated content 2
     *
     * @param string $response AI response text
     * @param array $expectedFields Expected field names
     * @return array Parsed fields
     */
    private function parseGroupedResponse(string $response, array $expectedFields): array
    {
        $fields = [];

        // Try to parse structured response
        $pattern = '/Field:\s*(\S+)\s*\nContent:\s*(.*?)(?=\n\nField:|\z)/s';

        if (preg_match_all($pattern, $response, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $fieldName = trim($match[1]);
                $content = trim($match[2]);
                $fields[$fieldName] = $content;
            }
        }

        // Fallback: if parsing failed, try simpler approach or use original
        if (empty($fields)) {
            $this->logger->warning("Could not parse grouped response, using simple fallback");

            // Simple fallback: split by double newlines and assign to fields in order
            $parts = preg_split('/\n\n+/', trim($response));
            $parts = array_filter($parts, function($p) { return trim($p) !== ''; });

            $index = 0;
            foreach ($expectedFields as $fieldName) {
                if (isset($parts[$index])) {
                    $fields[$fieldName] = trim($parts[$index]);
                    $index++;
                } else {
                    $fields[$fieldName] = ''; // Empty if not enough parts
                }
            }
        }

        return $fields;
    }

    /**
     * Get provider instance for language configuration
     *
     * @param array $config Variant configuration
     * @return array|null Array with 'provider' and 'provider_id' keys or null
     */
    private function getProviderForLanguage(array $config): ?array
    {
        if ($this->provider) {
            // If provider is injected, we don't know the provider_id, use model name as fallback
            return [
                'provider' => $this->provider,
                'provider_id' => $config['provider'] ?? 'unknown'
            ];
        }

        // Get model from config (set by user in Panel)
        // Get model name from config_id
        $modelConfigId = $config['provider'] ?? null;
        $modelConfig = \chrfickinger\Simplify\Config\ModelConfig::load($modelConfigId);
        $model = $modelConfig['model'] ?? $modelConfigId;

        if (!$model) {
            $this->logger->warning('No provider model configured for this variant');
            return null;
        }

        // Get provider_type from model config
        $providerType = $modelConfig['provider_type'] ?? 'openai';

        // Get global config for API key
        $kirby = Kirby::instance();
        $globalConfig = $kirby->option('chrfickinger.simplify', []);
        $providerConfig = $globalConfig['providers'][$providerType] ?? [];

        $providerInstance = null;
        if ($providerType === 'gemini') {
            $providerInstance = new Gemini($providerConfig, $this->logger);
        } elseif ($providerType === 'anthropic') {
            $providerInstance = new Anthropic($providerConfig, $this->logger);
        } elseif ($providerType === 'mistral') {
            $providerInstance = new Mistral($providerConfig, $this->logger);
        } else {
            // OpenAI and other OpenAI-compatible providers
            $providerInstance = new OpenAI($providerConfig, $this->logger);
        }

        if ($providerInstance) {
            return [
                'provider' => $providerInstance,
                'provider_id' => $providerType
            ];
        }

        $this->logger->warning("No provider found for model: {$model}");
        return null;
    }

    /**
     * Write translated content to page
     *
     * @param Page $page The page
     * @param string $targetLanguageCode Target language code
     */
}
