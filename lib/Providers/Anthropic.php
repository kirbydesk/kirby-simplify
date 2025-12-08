<?php

namespace chrfickinger\Simplify\Providers;

use chrfickinger\Simplify\Providers\ProviderInterface;
use chrfickinger\Simplify\Providers\ProviderResult;

/**
 * Anthropic Provider Implementation
 *
 * Compatible with:
 * - Anthropic API (api.anthropic.com)
 * - Claude models (Claude 3.5 Sonnet, Claude 3.5 Haiku, etc.)
 */
class Anthropic implements ProviderInterface
{
    private string $endpoint;
    private string $apiKey;
    private array $headers;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private string $apiVersion;

    public function __construct(array $config)
    {
        $this->endpoint = $config["endpoint"] ?? "https://api.anthropic.com/v1";
        $this->apiKey = $config["apiKey"] ?? "";
        $this->timeout = $config["timeout"] ?? 120;
        $this->connectTimeout = $config["connectTimeout"] ?? 10;
        $this->maxRetries = $config["retries"] ?? 3;
        $this->apiVersion = "2023-06-01"; // Fixed API version - update plugin for new versions

        // Build headers for Anthropic API
        $this->headers = [
            "Content-Type: application/json",
            "x-api-key: " . $this->apiKey,
            "anthropic-version: " . $this->apiVersion,
        ];
    }

    public function complete(
        array $messages,
        string $model,
        array $options = [],
    ): ProviderResult {
        // Convert messages format from OpenAI to Anthropic
        $anthropicMessages = $this->convertMessages($messages);

        // Anthropic requires max_tokens parameter - must be set in model config
        if (!isset($options["output_token_limit"])) {
            throw new \Exception("output_token_limit is required for Anthropic models. Please configure this in the model settings from GitHub repository.");
        }

        $payload = [
            "model" => $model,
            "messages" => $anthropicMessages['messages'],
            "max_tokens" => $options["output_token_limit"],
        ];

        // Add temperature if provided
        if (isset($options["temperature"])) {
            $payload["temperature"] = $options["temperature"];
        }

        // Add system prompt if present
        if (!empty($anthropicMessages['system'])) {
            $payload["system"] = $anthropicMessages['system'];
        }

        $response = $this->makeRequest("/messages", $payload);

        // Extract data from response
        $text = "";
        if (isset($response["content"]) && is_array($response["content"])) {
            foreach ($response["content"] as $block) {
                if ($block["type"] === "text") {
                    $text .= $block["text"];
                }
            }
        }

        $usage = $response["usage"] ?? [];
        $promptTokens = $usage["input_tokens"] ?? 0;
        $completionTokens = $usage["output_tokens"] ?? 0;
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
        return "anthropic";
    }

    public function validateConfig(): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Anthropic API key is required");
        }

        if (empty($this->endpoint)) {
            throw new \Exception("Anthropic endpoint is required");
        }

        return true;
    }



    /**
     * Convert OpenAI message format to Anthropic format
     *
     * OpenAI: [{"role": "system", "content": "..."}, {"role": "user", "content": "..."}]
     * Anthropic: system: "...", messages: [{"role": "user", "content": "..."}]
     */
    private function convertMessages(array $messages): array
    {
        $system = "";
        $anthropicMessages = [];

        foreach ($messages as $message) {
            $role = $message["role"] ?? "user";
            $content = $message["content"] ?? "";

            if ($role === "system") {
                // Anthropic uses a separate system parameter
                $system .= ($system ? "\n\n" : "") . $content;
            } else {
                // Convert assistant/user roles
                $anthropicMessages[] = [
                    "role" => $role === "assistant" ? "assistant" : "user",
                    "content" => $content,
                ];
            }
        }

        return [
            "system" => $system,
            "messages" => $anthropicMessages,
        ];
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
