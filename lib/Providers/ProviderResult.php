<?php

namespace chrfickinger\Simplify\Providers;

/**
 * Result object returned by AI providers
 */
class ProviderResult
{
    public string $text;
    public int $promptTokens;
    public int $completionTokens;
    public string $model;
    public ?array $rawResponse;

    public function __construct(
        string $text,
        int $promptTokens,
        int $completionTokens,
        string $model,
        ?array $rawResponse = null,
    ) {
        $this->text = $text;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->model = $model;
        $this->rawResponse = $rawResponse;
    }
}
