<?php

namespace kirbydesk\Simplify\Helpers;

/**
 * Privacy Helper
 *
 * Handles data privacy features:
 * - Masking sensitive data (emails, phones)
 * - Opt-out field filtering
 * - Data minimization
 */
class Privacy
{
    private array $config;
    private array $maskingMap;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maskingMap = [];
    }

    /**
     * Apply privacy filters to text before sending to AI
     *
     * @param string $text Original text
     * @return string Filtered/masked text
     */
    public function filterText(string $text): string
    {
        if ($this->config["maskEmails"] ?? false) {
            $text = $this->maskEmails($text);
        }

        if ($this->config["maskPhones"] ?? false) {
            $text = $this->maskPhones($text);
        }

        return $text;
    }

    /**
     * Restore masked data after receiving from AI
     *
     * @param string $text Text with masked placeholders
     * @return string Text with original data restored
     */
    public function restoreText(string $text): string
    {
        foreach ($this->maskingMap as $placeholder => $original) {
            $text = str_replace($placeholder, $original, $text);
        }

        return $text;
    }

    /**
     * Check if field should be opted out (never sent to AI)
     *
     * @param string $fieldName Field name to check
     * @return bool True if field should be skipped
     */
    public function isOptedOut(string $fieldName): bool
    {
        $optOutFields = $this->config["optOutFields"] ?? [];

        foreach ($optOutFields as $pattern) {
            if ($this->matchesPattern($fieldName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask email addresses with placeholders
     */
    private function maskEmails(string $text): string
    {
        return preg_replace_callback(
            "/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/",
            function ($matches) {
                $placeholder = "[EMAIL_" . count($this->maskingMap) . "]";
                $this->maskingMap[$placeholder] = $matches[0];
                return $placeholder;
            },
            $text,
        );
    }

    /**
     * Mask phone numbers with placeholders
     */
    private function maskPhones(string $text): string
    {
        // Pattern for common phone formats (German, International)
        $patterns = [
            "/\b\+?\d{1,3}[\s\-]?\(?\d{2,4}\)?[\s\-]?\d{3,4}[\s\-]?\d{3,4}\b/", // International
            "/\b0\d{2,5}[\s\-\/]?\d{3,8}\b/", // German landline
            "/\b01\d{1,2}[\s\-\/]?\d{6,8}\b/", // German mobile
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback(
                $pattern,
                function ($matches) {
                    $placeholder = "[PHONE_" . count($this->maskingMap) . "]";
                    $this->maskingMap[$placeholder] = $matches[0];
                    return $placeholder;
                },
                $text,
            );
        }

        return $text;
    }

    /**
     * Check if field name matches pattern (supports wildcards)
     */
    private function matchesPattern(string $fieldName, string $pattern): bool
    {
        $regex = "/^" . str_replace(["*", "."], [".*", "\\."], $pattern) . '$/';
        return preg_match($regex, $fieldName) === 1;
    }
}
