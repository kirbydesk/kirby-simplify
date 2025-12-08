<?php

namespace chrfickinger\Simplify\Providers;

/**
 * Mistral AI Provider
 * Uses OpenAI-compatible API
 */
class Mistral extends OpenAI
{
    public function __construct(array $config)
    {
        // Set Mistral default endpoint if not provided
        if (!isset($config["endpoint"])) {
            $config["endpoint"] = "https://api.mistral.ai/v1";
        }

        // Call parent OpenAI constructor with modified config
        parent::__construct($config);
    }
}
