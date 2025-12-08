<?php

namespace kirbydesk\Simplify\Processing;

use Kirby\Cms\Page;
use kirbydesk\Simplify\Processing\TranslationFilter;
use kirbydesk\Simplify\Processing\ContentMasker;

class FieldGrouper
{
    /**
     * Default batch size for grouping fields
     * If a field type has more than this many fields, they will be split into batches
     */
    private const DEFAULT_BATCH_SIZE = 20;

    /**
     * Group fields by their field type
     *
     * Groups an array of field names by their field type (text, textarea, blocks, etc.)
     * This allows sending all fields of the same type in one API call
     *
     * @param Page $page The page containing the fields
     * @param array $fieldNames Array of field names to group
     * @return array Array grouped by field type ['text' => ['field1', 'field2'], 'blocks' => ['field3']]
     */
    public static function groupFieldsByType(Page $page, array $fieldNames): array
    {
        $fieldsByType = [];

        foreach ($fieldNames as $fieldName) {
            $fieldType = TranslationFilter::getFieldType($page, $fieldName);

            if (!$fieldType) {
                continue; // Skip fields with unknown type
            }

            if (!isset($fieldsByType[$fieldType])) {
                $fieldsByType[$fieldType] = [];
            }

            $fieldsByType[$fieldType][] = $fieldName;
        }

        return $fieldsByType;
    }

    /**
     * Group fields by type and split into batches if necessary
     *
     * If a field type has too many fields (> batch size), split them into multiple batches
     * to avoid sending too much data in one API call
     *
     * @param Page $page The page containing the fields
     * @param array $fieldNames Array of field names to group
     * @param int $batchSize Maximum number of fields per batch
     * @return array Array of batches [['type' => 'text', 'fields' => [...]], ...]
     */
    public static function groupFieldsWithBatching(Page $page, array $fieldNames, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        // First, group by type
        $fieldsByType = self::groupFieldsByType($page, $fieldNames);

        $batches = [];

        foreach ($fieldsByType as $fieldType => $fields) {
            // If fields count is within batch size, create one batch
            if (count($fields) <= $batchSize) {
                $batches[] = [
                    'type' => $fieldType,
                    'fields' => $fields
                ];
            } else {
                // Split into multiple batches
                $chunks = array_chunk($fields, $batchSize, false);
                foreach ($chunks as $chunk) {
                    $batches[] = [
                        'type' => $fieldType,
                        'fields' => $chunk
                    ];
                }
            }
        }

        return $batches;
    }

    /**
     * Get field content for a group of fields
     *
     * Returns an associative array of field names => content values
     *
     * @param Page $page The page containing the fields
     * @param array $fieldNames Array of field names
     * @return array ['field1' => 'content1', 'field2' => 'content2', ...]
     */
    public static function getFieldContents(Page $page, array $fieldNames): array
    {
        $contents = [];

        foreach ($fieldNames as $fieldName) {
            $field = $page->content()->get($fieldName);
            $contents[$fieldName] = $field ? $field->value() : '';
        }

        return $contents;
    }

    /**
     * Mask contents for a group of fields
     *
     * Applies masking to each field's content and returns both masked contents and maps
     *
     * @param array $fieldContents Array of field names => content
     * @param array $maskingConfig Masking configuration
     * @return array ['contents' => [...], 'maps' => [...]]
     */
    public static function maskFieldContents(array $fieldContents, array $maskingConfig = []): array
    {
        $maskedContents = [];
        $maskingMaps = [];

        foreach ($fieldContents as $fieldName => $content) {
            $masked = ContentMasker::maskContent($content, $maskingConfig);
            $maskedContents[$fieldName] = $masked['masked'];
            $maskingMaps[$fieldName] = $masked['map'];
        }

        return [
            'contents' => $maskedContents,
            'maps' => $maskingMaps
        ];
    }

    /**
     * Demask contents for a group of fields
     *
     * Applies de-masking to each field's content using the corresponding map
     *
     * @param array $fieldContents Array of field names => masked content
     * @param array $maskingMaps Array of field names => masking map
     * @return array Array of field names => demasked content
     */
    public static function demaskFieldContents(array $fieldContents, array $maskingMaps): array
    {
        $demaskedContents = [];

        foreach ($fieldContents as $fieldName => $content) {
            $map = $maskingMaps[$fieldName] ?? [];
            $demaskedContents[$fieldName] = ContentMasker::demaskContent($content, $map);
        }

        return $demaskedContents;
    }
}
