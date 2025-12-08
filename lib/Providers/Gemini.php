<?php

namespace chrfickinger\Simplify\Providers;

use chrfickinger\Simplify\Providers\ProviderInterface;
use chrfickinger\Simplify\Providers\ProviderResult;

/**
 * Google Gemini Provider Implementation
 *
 * Compatible with:
 * - Google Gemini API (generativelanguage.googleapis.com)
 * - Supports Gemini Pro, Gemini Pro Vision, and other Gemini models
 */
class Gemini implements ProviderInterface
{
    private string $endpoint;
    private string $apiKey;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;

    public function __construct(array $config)
    {
        $this->endpoint =
            $config["endpoint"] ??
            "https://generativelanguage.googleapis.com/v1beta";
        $this->apiKey = $config["apiKey"] ?? "";
        $this->timeout = $config["timeout"] ?? 120;
        $this->connectTimeout = $config["connectTimeout"] ?? 10;
        $this->maxRetries = $config["retries"] ?? 3;
    }

    public function complete(
        array $messages,
        string $model,
        array $options = [],
    ): ProviderResult {
        // Convert OpenAI-style messages to Gemini format
        $contents = $this->convertMessagesToGeminiFormat($messages);

        $generationConfig = [];

        // Add temperature if provided
        if (isset($options["temperature"])) {
            $generationConfig["temperature"] = $options["temperature"];
        }

        // Only add maxOutputTokens if explicitly set
        if (isset($options["max_tokens"])) {
            $generationConfig["maxOutputTokens"] = $options["max_tokens"];
        }

        $payload = [
            "contents" => $contents,
            "generationConfig" => $generationConfig,
        ];

        // Add safety settings (optional)
        if (isset($options["safetySettings"])) {
            $payload["safetySettings"] = $options["safetySettings"];
        }

        $response = $this->makeRequest($model, $payload);

        // Extract data from Gemini response
        $text = "";
        if (
            isset($response["candidates"][0]["content"]["parts"][0]["text"])
        ) {
            $text = $response["candidates"][0]["content"]["parts"][0]["text"];
        }

        // Gemini returns usage metadata
        $usage = $response["usageMetadata"] ?? [];
        $promptTokens = $usage["promptTokenCount"] ?? 0;
        $completionTokens = $usage["candidatesTokenCount"] ?? 0;

        return new ProviderResult(
            $text,
            $promptTokens,
            $completionTokens,
            $model,
            $response,
        );
    }

    public function getName(): string
    {
        return "gemini";
    }

    public function validateConfig(): bool
    {
        if (empty($this->apiKey)) {
            throw new \Exception("Gemini API key is required");
        }

        if (empty($this->endpoint)) {
            throw new \Exception("Gemini endpoint is required");
        }

        return true;
    }

    /**
     * Convert OpenAI-style messages to Gemini format
     *
     * OpenAI format: [{"role": "user", "content": "text"}]
     * Gemini format: [{"role": "user", "parts": [{"text": "text"}]}]
     */
    private function convertMessagesToGeminiFormat(array $messages): array
    {
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $message) {
            $role = $message["role"] ?? "user";
            $content = $message["content"] ?? "";

            // Gemini handles system messages differently
            if ($role === "system") {
                $systemInstruction = $content;
                continue;
            }

            // Map role names (OpenAI uses 'assistant', Gemini uses 'model')
            if ($role === "assistant") {
                $role = "model";
            }

            $contents[] = [
                "role" => $role,
                "parts" => [["text" => $content]],
            ];
        }

        // Prepend system instruction as first user message if exists
        if ($systemInstruction) {
            array_unshift($contents, [
                "role" => "user",
                "parts" => [["text" => $systemInstruction]],
            ]);
        }

        return $contents;
    }

    /**
     * Make HTTP request to Gemini API with retry logic
     */
    private function makeRequest(
        string $model,
        array $payload,
        int $attempt = 1,
    ): array {
        // Gemini uses model name in URL path
        $url =
            rtrim($this->endpoint, "/") .
            "/models/{$model}:generateContent?key=" .
            $this->apiKey;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
        ]);
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
                return $this->makeRequest($model, $payload, $attempt + 1);
            }
            throw new \Exception("API request failed: " . $curlError);
        }

        $response = json_decode($responseBody, true);

        if ($httpCode !== 200) {
            $errorMsg =
                $response["error"]["message"] ??
                $response["error"]["status"] ??
                "Unknown error";

            // Retry on rate limit (429) or server errors (5xx)
            if (
                ($httpCode === 429 || $httpCode >= 500) &&
                $attempt < $this->maxRetries
            ) {
                sleep(pow(2, $attempt));
                return $this->makeRequest($model, $payload, $attempt + 1);
            }

            throw new \Exception("API error (HTTP {$httpCode}): {$errorMsg}");
        }

        return $response;
    }
}
