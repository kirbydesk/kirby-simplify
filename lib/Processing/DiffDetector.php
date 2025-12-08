<?php

namespace kirbydesk\Simplify\Processing;

use Kirby\Cms\Page;

/**
 * Diff Detector
 * Detects changes between current page content and snapshot
 * to enable intelligent selective translation
 */
class DiffDetector
{
    /**
     * Create a snapshot of the current page content
     *
     * @param Page $page Source page
     * @return array Snapshot of all translatable fields
     */
    public static function createSnapshot(Page $page): array
    {
        $snapshot = [];
        $content = $page->content()->toArray();

        foreach ($content as $key => $value) {
            // Store all field values
            $snapshot[$key] = $value;
        }

        return $snapshot;
    }

    /**
     * Detect changes between current source and snapshot
     *
     * @param array $currentSource Current page content
     * @param array $snapshot Saved snapshot from job creation
     * @return array ['strategy' => 'diff'|'full', 'fields' => [...]]
     */
    public static function detectChanges(array $currentSource, array $snapshot): array
    {
        $changedFields = [];
        $allFields = array_unique(array_merge(array_keys($currentSource), array_keys($snapshot)));

        foreach ($allFields as $field) {
            $currentValue = $currentSource[$field] ?? null;
            $snapshotValue = $snapshot[$field] ?? null;

            // Field was added, removed, or value changed
            if ($currentValue !== $snapshotValue) {
                $changedFields[] = $field;
            }
        }

        // If more than 50% of fields changed, use full translation
        // This prevents inefficiency when most content has changed
        $changePercentage = count($allFields) > 0
            ? (count($changedFields) / count($allFields)) * 100
            : 0;

        $strategy = ($changePercentage > 50 || empty($snapshot)) ? 'full' : 'diff';

        return [
            'strategy' => $strategy,
            'fields' => $strategy === 'diff' ? $changedFields : array_keys($currentSource),
            'changePercentage' => round($changePercentage, 2),
            'totalFields' => count($allFields),
            'changedFields' => count($changedFields),
        ];
    }

    /**
     * Compare two field values with tolerance for minor changes
     *
     * @param mixed $value1
     * @param mixed $value2
     * @return bool True if values are significantly different
     */
    private static function hasSignificantChange($value1, $value2): bool
    {
        // Convert to strings for comparison
        $str1 = is_array($value1) || is_object($value1) ? json_encode($value1) : (string)$value1;
        $str2 = is_array($value2) || is_object($value2) ? json_encode($value2) : (string)$value2;

        // Exact match
        if ($str1 === $str2) {
            return false;
        }

        // Empty vs non-empty is significant
        if (empty($str1) !== empty($str2)) {
            return true;
        }

        // For short strings, any change is significant
        if (strlen($str1) < 50 || strlen($str2) < 50) {
            return true;
        }

        // For longer strings, calculate similarity
        // If less than 80% similar, it's a significant change
        similar_text($str1, $str2, $percent);
        return $percent < 80;
    }

    /**
     * Get a human-readable summary of changes
     *
     * @param array $changes Result from detectChanges()
     * @return string Summary text
     */
    public static function getChangeSummary(array $changes): string
    {
        if ($changes['strategy'] === 'full') {
            return sprintf(
                'Full translation: %d fields (%.1f%% changed)',
                $changes['totalFields'],
                $changes['changePercentage']
            );
        }

        return sprintf(
            'Differential translation: %d of %d fields changed (%.1f%%)',
            $changes['changedFields'],
            $changes['totalFields'],
            $changes['changePercentage']
        );
    }
}
