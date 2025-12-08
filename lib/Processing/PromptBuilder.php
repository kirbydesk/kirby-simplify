<?php

namespace chrfickinger\Simplify\Processing;

/**
 * Prompt Builder
 *
 * Handles prompt construction and text preparation for AI processing:
 * - Building AI messages
 * - Text normalization
 * - Field type detection
 */
class PromptBuilder
{

    /**
     * Build prompt messages from config (without RuleInterface dependency)
     *
     * @param string $text Text to process (should already be masked if needed)
     * @param array $variantConfig Full variant configuration
     * @param string $fieldType Field type (text, textarea, blocks, etc.)
     * @param string $fieldInstruction Field-specific instruction
     * @param string $categoryPrompt Category-specific prompt (strict/structured/elaborate)
     * @return string The full system prompt
     */
    public static function buildSystemPromptFromConfig(
        string $text,
        array $variantConfig,
        string $fieldType,
        string $fieldInstruction = '',
        string $categoryPrompt = ''
    ): string {
        // Start with main system prompt
        $systemPrompt = $variantConfig['ai_system_prompt'] ?? '';

        // Add project-wide instructions if available
        $projectPrompt = $variantConfig['project_prompt'] ?? '';
        if (!empty($projectPrompt)) {
            $systemPrompt .= "\n\n**Projekt-Anweisungen:**\n" . $projectPrompt;
        }

        // Add field instruction and category prompt
        $fullSystemPrompt = $systemPrompt . "\n\n" . $fieldInstruction . "\n\n" . $categoryPrompt;

        return $fullSystemPrompt;
    }

    /**
     * Normalize text to preserve structure
     *
     * @param string $simplified Simplified text from AI
     * @param string $original Original text
     * @param string $fieldType Field type
     * @return string Normalized text
     */
    public static function normalizeText(
        string $simplified,
        string $original,
        string $fieldType,
    ): string {
        // Basic normalization
        $simplified = trim($simplified);

        // Remove common AI artifacts (code blocks)
        $simplified = preg_replace('/^```.*\n/', "", $simplified);
        $simplified = preg_replace('/\n```$/', "", $simplified);

        // Normalize excessive line breaks (3+ newlines → 2 newlines)
        // This fixes AI models that add too many blank lines
        $simplified = preg_replace('/\n{3,}/', "\n\n", $simplified);

        // For JSON fields (blocks, structure, etc.): Remove trailing explanations/comments
        if (in_array($fieldType, ['blocks', 'layout', 'structure', 'object'])) {
            // Find the end of JSON (closing bracket/brace) and remove everything after
            if (preg_match('/^(\[.*\]|\{.*\})/s', $simplified, $matches)) {
                $simplified = $matches[1];
            }
        }

        return $simplified;
    }
}
