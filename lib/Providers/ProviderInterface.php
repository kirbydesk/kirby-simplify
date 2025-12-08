<?php

namespace kirbydesk\Simplify\Providers;

/**
 * Provider Interface for AI completion services
 *
 * Defines the contract for all AI provider implementations
 * (OpenAI, OpenRouter, Azure OpenAI, Gemini, local models, etc.)
 */
interface ProviderInterface
{
    /**
     * Complete a chat/text request
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param string $model Model identifier (e.g., 'gpt-4', 'gpt-3.5-turbo', 'gemini-pro')
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return ProviderResult Result object with text, usage data, and model info
     * @throws \Exception on API errors, network issues, etc.
     */
    public function complete(
        array $messages,
        string $model,
        array $options = [],
    ): ProviderResult;

    /**
     * Get the provider name
     *
     * @return string Provider identifier (e.g., 'openai', 'openrouter', 'azure', 'gemini')
     */
    public function getName(): string;

    /**
     * Validate provider configuration
     *
     * @return bool True if configuration is valid
     * @throws \Exception if configuration is invalid with detailed error message
     */
    public function validateConfig(): bool;
}
