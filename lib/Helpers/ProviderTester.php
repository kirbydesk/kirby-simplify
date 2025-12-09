<?php

namespace kirbydesk\Simplify\Helpers;

use kirbydesk\Simplify\Logging\StatsLogger;
use kirbydesk\Simplify\Core\BudgetManager;
use kirbydesk\Simplify\Queue\WorkerManager;

/**
 * Provider Tester Class
 *
 * Tests AI providers with prompts and logs statistics.
 */
class ProviderTester
{
    /**
     * Test a provider with a prompt
     *
     * @param string $providerId Provider ID
     * @param array $config Global config
     * @param string $prompt Test prompt
     * @param string|null $systemPrompt Optional system prompt
     * @param object|null $logger Optional logger
     * @return array Test result
     */
    public static function test(
        string $providerId,
        array $config,
        string $prompt,
        ?string $systemPrompt = null,
        ?object $logger = null
    ): array {
        try {
            if ($logger) {
                $logger->info("Testing provider {$providerId}");
            }

            $provider = ProviderFactory::create($providerId, $config);
            $provider->validateConfig();

            // Strategy: Try local models first, then GitHub API
            $model = null;
            $modelData = null;
            $useLocalConfig = false;
            $apiResponse = null;

            // 1. Check if we have local model configs for this provider
            $allModels = \kirbydesk\Simplify\Config\ModelConfig::getAll();
            $localModels = array_filter($allModels, function($modelConfig) use ($providerId) {
                return isset($modelConfig['provider_type']) && $modelConfig['provider_type'] === $providerId;
            });

            if (!empty($localModels)) {
                // Use first local model (alphabetically sorted)
                ksort($localModels);
                $firstModel = reset($localModels);
                $model = $firstModel['model'];
                $useLocalConfig = true;

                if ($logger) {
                    $logger->info("Using local model for test: {$model}");
                }
            } else {
                // 2. No local models - get from GitHub API
                try {
                    $apiResponse = ModelQualityChecker::checkAllModels($providerId);
                    if ($apiResponse && isset($apiResponse['models'])) {
                        // Find first recommended model
                        foreach ($apiResponse['models'] as $modelName => $modelInfo) {
                            if (isset($modelInfo['recommended']) && $modelInfo['recommended'] === true) {
                                $model = $modelName;
                                $modelData = $modelInfo;
                                break;
                            }
                        }

                        // If no recommended model, use first working model
                        if (!$model) {
                            foreach ($apiResponse['models'] as $modelName => $modelInfo) {
                                if (isset($modelInfo['status']) && $modelInfo['status'] === 'working') {
                                    $model = $modelName;
                                    $modelData = $modelInfo;
                                    break;
                                }
                            }
                        }

                        // If still no model, use first available
                        if (!$model && count($apiResponse['models']) > 0) {
                            $model = array_key_first($apiResponse['models']);
                            $modelData = $apiResponse['models'][$model];
                        }

                        if ($logger && $model) {
                            $logger->info("Using GitHub API model for test: {$model}");
                        }
                    }
                } catch (\Exception $e) {
                    if ($logger) {
                        $logger->warning("Could not fetch models from API: " . $e->getMessage());
                    }
                }
            }

            if (!$model) {
                throw new \Exception("No test model available for provider: {$providerId}");
            }

            // Make a minimal API call to test the key
            $messages = [
                ['role' => 'user', 'content' => 'Hi']
            ];

            // Build options based on source (local config or GitHub API data)
            $options = [];

            if ($useLocalConfig) {
                // Load from local model config
                $modelConfigId = \kirbydesk\Simplify\Config\ModelConfig::buildId($providerId, $model);
                $modelConfig = \kirbydesk\Simplify\Config\ModelConfig::load($modelConfigId);

                // Only set temperature if model supports it
                if ($modelConfig && isset($modelConfig['supports_temperature']) && $modelConfig['supports_temperature'] === true) {
                    $options['temperature'] = 0.3;
                }

                // Add output_token_limit if set
                if ($modelConfig && isset($modelConfig['output_token_limit'])) {
                    $options['output_token_limit'] = $modelConfig['output_token_limit'];
                }
            } else {
                // Use GitHub API data
                // Only set temperature if model supports it
                if ($modelData && isset($modelData['temperature']) && $modelData['temperature'] === true) {
                    $options['temperature'] = 0.3;
                }

                // Add output_token_limit for models that need it (e.g., Anthropic)
                if ($modelData && isset($modelData['output_token_limit'])) {
                    $options['output_token_limit'] = $modelData['output_token_limit'];
                }
            }

            $response = $provider->complete($messages, $model, $options);

            if ($logger) {
                $logger->info("API key test successful for provider: {$providerId} with model: {$model}");
            }

            // Log test statistics
            self::logTestStats($providerId, $model, $config, $response, $logger);

            // Log budget usage
            self::logBudget($providerId, $model, $config, $response, $logger);

            // Test PHP CLI configuration
            $phpCliStatus = self::testPhpCli($logger);

            return [
                'success' => true,
                'message' => 'API key is valid',
                'phpCli' => $phpCliStatus,
            ];

        } catch (\Exception $e) {
            if ($logger) {
                $logger->error("Test ERROR: " . $e->getMessage());
            }

            self::logFailedTest($providerId, $config, $e);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Log test statistics
     *
     * @param string $providerId
     * @param string $model
     * @param array $config
     * @param object $response
     * @param object|null $logger
     */
    private static function logTestStats($providerId, $model, $config, $response, $logger)
    {
        try {
            $statsLogger = new StatsLogger();

            // Load pricing from model config (not provider config)
            $modelConfigId = \kirbydesk\Simplify\Config\ModelConfig::buildId($providerId, $model);
            $modelConfig = \kirbydesk\Simplify\Config\ModelConfig::load($modelConfigId);
            $pricing = $modelConfig['pricing'] ?? null;

            // Calculate cost only if pricing data is available
            $cost = null;
            if ($pricing && isset($pricing['input']) && isset($pricing['output'])) {
                $perTokens = $pricing['per_tokens'] ?? \kirbydesk\Simplify\Core\BudgetManager::DEFAULT_PER_TOKENS;
                $inputCost = (($response->promptTokens ?? 0) / $perTokens) * $pricing['input'];
                $outputCost = (($response->completionTokens ?? 0) / $perTokens) * $pricing['output'];
                $cost = $inputCost + $outputCost;
            }

            $statsLogger->logApiCall(
                $providerId,
                $model,
                $response->promptTokens ?? 0,
                $response->completionTokens ?? 0,
                $cost,
                true,
                null,
                'test'
            );
        } catch (\Exception $e) {
            if ($logger) {
                $logger->warning("Stats logging failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Log failed test
     *
     * @param string $providerId
     * @param array $config
     * @param \Exception $exception
     */
    private static function logFailedTest($providerId, $config, $exception)
    {
        try {
            $statsLogger = new StatsLogger();
            $providerConfig = $config['providers'][$providerId] ?? [];

            $statsLogger->logApiCall(
                $providerId,
                'unknown', // Model unknown when test fails early
                0,
                0,
                null, // No cost data when test fails
                false,
                $exception->getMessage(),
                'test'
            );
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Log budget usage for test
     *
     * @param string $providerId
     * @param string $model Model name used in test
     * @param array $config
     * @param object $response
     * @param object|null $logger
     */
    private static function logBudget($providerId, $model, $config, $response, $logger)
    {
        try {
            // Load pricing from model config (not provider config)
            $modelConfigId = \kirbydesk\Simplify\Config\ModelConfig::buildId($providerId, $model);
            $modelConfig = \kirbydesk\Simplify\Config\ModelConfig::load($modelConfigId);
            $pricing = $modelConfig['pricing'] ?? null;

            $inputTokens = $response->promptTokens ?? 0;
            $outputTokens = $response->completionTokens ?? 0;

            $totalCost = null;
            if ($pricing && isset($pricing['input']) && isset($pricing['output'])) {
                $perTokens = $pricing['per_tokens'] ?? \kirbydesk\Simplify\Core\BudgetManager::DEFAULT_PER_TOKENS;
                $inputCost = ($inputTokens / $perTokens) * $pricing['input'];
                $outputCost = ($outputTokens / $perTokens) * $pricing['output'];
                $totalCost = $inputCost + $outputCost;
            }

            // Record in BudgetManager (use model config ID)
            $modelConfigId = \kirbydesk\Simplify\Config\ModelConfig::buildId($providerId, $model);
            $budgetManager = new BudgetManager($modelConfigId);
            $budgetManager->record($inputTokens, $outputTokens, $totalCost);

            if ($logger) {
                $costDisplay = $totalCost !== null ? "\${$totalCost}" : '? (no pricing)';
                $logger->info("Budget recorded: {$costDisplay} ({$inputTokens} in, {$outputTokens} out tokens)");
            }
        } catch (\Exception $e) {
            if ($logger) {
                $logger->warning("Budget logging failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Test PHP CLI configuration
     *
     * @param object|null $logger Optional logger
     * @return array PHP CLI status information
     */
    private static function testPhpCli(?object $logger = null): array
    {
        $phpBinary = WorkerManager::detectPhpBinary();
        $status = [
            'binary' => $phpBinary,
            'version' => null,
            'compatible' => false,
            'execAvailable' => WorkerManager::isExecAvailable(),
            'errors' => [],
        ];

        if ($logger) {
            $logger->info("Testing PHP CLI binary: {$phpBinary}");
        }

        // Test if binary exists and get version
        try {
            $output = [];
            $return = 0;

            // Get PHP version
            @exec("{$phpBinary} -v 2>&1", $output, $return);

            if ($return === 0 && !empty($output)) {
                $versionLine = $output[0] ?? '';
                // Extract version number (e.g., "PHP 8.3.25-nmm1" -> "8.3.25")
                if (preg_match('/PHP (\d+\.\d+\.\d+)/', $versionLine, $matches)) {
                    $status['version'] = $matches[1];
                    $status['versionFull'] = $versionLine;

                    // Check if version is compatible (>= 8.1.0)
                    if (version_compare($status['version'], '8.0.0', '>=')) {
                        $status['compatible'] = true;

                        if ($logger) {
                            $logger->info("PHP CLI version: {$status['version']} (compatible)");
                        }
                    } else {
                        $status['errors'][] = "PHP version {$status['version']} is too old. Minimum required: 8.0.0";

                        if ($logger) {
                            $logger->warning("PHP CLI version {$status['version']} is incompatible. Minimum: 8.0.0");
                        }
                    }
                } else {
                    $status['errors'][] = "Could not parse PHP version from: {$versionLine}";
                }
            } else {
                $status['errors'][] = "Could not execute PHP binary: {$phpBinary}";

                if ($logger) {
                    $logger->error("PHP binary test failed for: {$phpBinary}");
                }
            }
        } catch (\Exception $e) {
            $status['errors'][] = $e->getMessage();

            if ($logger) {
                $logger->error("PHP CLI test error: " . $e->getMessage());
            }
        }

        // Check exec() availability
        if (!$status['execAvailable']) {
            $status['errors'][] = 'exec() function is disabled. Background workers will use fallback mode.';

            if ($logger) {
                $logger->warning('exec() is disabled, using fallback worker mode');
            }
        }

        return $status;
    }
}
