<?php

namespace kirbydesk\Simplify\Providers;

use kirbydesk\Simplify\Providers\ProviderInterface;
use kirbydesk\Simplify\Providers\ProviderResult;

/**
 * OpenAI Provider Implementation
 *
 * Compatible with:
 * - OpenAI API (api.openai.com)
 * - OpenRouter (openrouter.ai)
 * - Azure OpenAI
 * - Any OpenAI-compatible endpoint
 */
class OpenAI implements ProviderInterface
{
    private string $endpoint;
    private string $apiKey;
    private array $headers;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;

    public function __construct(array $config)
    {
        $this->endpoint = $config["endpoint"] ?? "https://api.openai.com/v1";
        $this->apiKey = $config["apiKey"] ?? "";
        $this->timeout = $config["timeout"] ?? 120;
        $this->connectTimeout = $config["connectTimeout"] ?? 10;
        $this->maxRetries = $config["retries"] ?? 3;

        // Build headers
        $this->headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->apiKey,
        ];

        // Add optional headers (e.g., for OpenRouter)
        if (isset($config["headers"]) && is_array($config["headers"])) {
            foreach ($config["headers"] as $key => $value) {
                $this->headers[] = $key . ": " . $value;
            }
        }
    }

    public function complete(
        array $messages,
        string $model,
        array $options = [],
    ): ProviderResult {
        $payload = [
            "model" => $model,
            "messages" => $messages,
        ];

        // Add temperature if provided
        if (isset($options["temperature"])) {
            $payload["temperature"] = $options["temperature"];
        }

        if (isset($options["max_tokens"])) {
            // Some newer models (o1, gpt-4o-2024-08-06+) use max_completion_tokens instead of max_tokens
            $usesCompletionTokens = $this->usesMaxCompletionTokens($model);

            if ($usesCompletionTokens) {
                $payload["max_completion_tokens"] = $options["max_tokens"];
            } else {
                $payload["max_tokens"] = $options["max_tokens"];
            }
        }

        $response = $this->makeRequest("/chat/completions", $payload);

        // Extract data from response
        $text = $response["choices"][0]["message"]["content"] ?? "";
        $usage = $response["usage"] ?? [];
        $promptTokens = $usage["prompt_tokens"] ?? 0;
        $completionTokens = $usage["completion_tokens"] ?? 0;
        $modelUsed = $response["model"] ?? $model;

        return new ProviderResult(
            $text,
            $promptTokens,
            $completionTokens,
            $modelUsed,
            $response,
        );
    }

    public function getName(): string
    {
        return "openai";
    }

    public function validateConfig(): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception("OpenAI API key is required");
        }

        if (empty($this->endpoint)) {
            throw new \Exception("OpenAI endpoint is required");
        }

        return true;
    }

    /**
     * Check if model uses max_completion_tokens instead of max_tokens
     *
     * Models that require max_completion_tokens:
     * - o1 series (o1-preview, o1-mini)
     * - gpt-4o series from 2024-08-06 onwards
     * - chatgpt-4o-latest (uses gpt-4o-2024-08-06 under the hood)
     */
    private function usesMaxCompletionTokens(string $model): bool
    {
        // o1 series models
        if (str_starts_with($model, 'o1-')) {
            return true;
        }

        // gpt-4o from 2024-08-06 onwards
        if (preg_match('/^gpt-4o-(\d{4})-(\d{2})-(\d{2})$/', $model, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];

            // Compare date: 2024-08-06 and later
            if ($year > 2024) return true;
            if ($year === 2024 && $month > 8) return true;
            if ($year === 2024 && $month === 8 && $day >= 6) return true;
        }

        // chatgpt-4o-latest uses gpt-4o-2024-08-06
        if ($model === 'chatgpt-4o-latest') {
            return true;
        }

        return false;
    }

    /**
     * Make HTTP request to API with retry logic
     */
    private function makeRequest(
        string $path,
        array $payload,
        int $attempt = 1,
    ): array {
        $url = rtrim($this->endpoint, "/") . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Handle network errors
        if ($responseBody === false) {
            if ($attempt < $this->maxRetries) {
                sleep(pow(2, $attempt)); // Exponential backoff
                return $this->makeRequest($path, $payload, $attempt + 1);
            }
            throw new \Exception("API request failed: " . $curlError);
        }

        $response = json_decode($responseBody, true);

        if ($httpCode !== 200) {
            $errorMsg = $response["error"]["message"] ?? "Unknown error";

            // Retry on rate limit (429) or server errors (5xx)
            if (
                ($httpCode === 429 || $httpCode >= 500) &&
                $attempt < $this->maxRetries
            ) {
                sleep(pow(2, $attempt));
                return $this->makeRequest($path, $payload, $attempt + 1);
            }

            throw new \Exception("API error (HTTP {$httpCode}): {$errorMsg}");
        }

        return $response;
    }
}
