<?php

namespace kirbydesk\Simplify\Processing;

class ContentMasker
{
    /**
     * Email regex pattern
     */
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * Phone regex pattern
     * Matches formats like: +49 123 456789, (030) 123-4567, +1-555-123-4567, etc.
     */
    private const PHONE_PATTERN = '/(\+?\d{1,3}[\s\-]?)?(\(?\d{2,4}\)?[\s\-]?)[\d\s\-]{4,}/';

    /**
     * Mask email addresses and phone numbers in content
     *
     * Replaces emails and phone numbers with unique placeholders
     * and returns both the masked content and a mapping to restore them later
     *
     * @param string $content The content to mask
     * @param array $maskingConfig Masking configuration (mask_emails, mask_phones)
     * @return array ['masked' => string, 'map' => array]
     */
    public static function maskContent(string $content, array $maskingConfig = []): array
    {
        $maskEmails = $maskingConfig['mask_emails'] ?? true;
        $maskPhones = $maskingConfig['mask_phones'] ?? true;

        $maskedContent = $content;
        $map = [];
        $counter = 1;

        // Mask emails
        if ($maskEmails) {
            $maskedContent = preg_replace_callback(
                self::EMAIL_PATTERN,
                function($matches) use (&$map, &$counter) {
                    $placeholder = '___EMAIL_MASK_' . $counter . '___';
                    $map[$placeholder] = $matches[0];
                    $counter++;
                    return $placeholder;
                },
                $maskedContent
            );
        }

        // Reset counter for phone numbers
        $counter = 1;

        // Mask phone numbers
        if ($maskPhones) {
            $maskedContent = preg_replace_callback(
                self::PHONE_PATTERN,
                function($matches) use (&$map, &$counter) {
                    $placeholder = '___TEL_MASK_' . $counter . '___';
                    $map[$placeholder] = $matches[0];
                    $counter++;
                    return $placeholder;
                },
                $maskedContent
            );
        }

        return [
            'masked' => $maskedContent,
            'map' => $map
        ];
    }

    /**
     * Restore masked email addresses and phone numbers
     *
     * Replaces placeholders with original values from the mapping
     *
     * @param string $content The content with placeholders
     * @param array $map The mapping from maskContent()
     * @return string Content with restored emails and phone numbers
     */
    public static function demaskContent(string $content, array $map): string
    {
        if (empty($map)) {
            return $content;
        }

        $demaskedContent = $content;

        foreach ($map as $placeholder => $original) {
            $demaskedContent = str_replace($placeholder, $original, $demaskedContent);
        }

        return $demaskedContent;
    }
}
