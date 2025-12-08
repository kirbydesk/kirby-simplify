<?php

namespace kirbydesk\Simplify\Helpers;

use Kirby\Cms\App as Kirby;

/**
 * Blueprint Scanner Class
 *
 * Scans and analyzes Kirby blueprints to discover templates and field types.
 */
class BlueprintScanner
{
    /**
     * Find all page templates with metadata
     *
     * @return array Array of template info
     */
    public static function getTemplates(): array
    {
        $kirby = Kirby::instance();
        $templates = [];
        $templateNames = [];

        // Get page counts
        $pageCounts = self::getPageCounts($kirby);

        // Scan filesystem blueprints
        $blueprintPaths = self::getBlueprintPaths($kirby);

        foreach ($blueprintPaths as $blueprintsPath) {
            $files = @scandir($blueprintsPath);
            if (!$files) continue;

            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) !== 'yml') {
                    continue;
                }

                $templateName = pathinfo($file, PATHINFO_FILENAME);

                if (isset($templateNames[$templateName])) {
                    continue;
                }

                $templateNames[$templateName] = true;
                $blueprintFile = $blueprintsPath . '/' . $file;

                $templates[] = [
                    'value' => $templateName,
                    'title' => self::extractTitle($blueprintFile, $templateName),
                    'count' => $pageCounts[$templateName] ?? 0
                ];
            }
        }

        // Scan registered blueprints
        foreach ($kirby->extensions('blueprints') as $blueprintName => $blueprintData) {
            if (strpos($blueprintName, 'pages/') !== 0) {
                continue;
            }

            $templateName = substr($blueprintName, 6);

            if (isset($templateNames[$templateName])) {
                continue;
            }

            $blueprintPath = is_array($blueprintData)
                ? ($blueprintData['props'] ?? null)
                : $blueprintData;

            if (!$blueprintPath || !is_string($blueprintPath) || !file_exists($blueprintPath)) {
                continue;
            }

            $templateNames[$templateName] = true;

            $templates[] = [
                'value' => $templateName,
                'title' => self::extractTitle($blueprintPath, $templateName),
                'count' => $pageCounts[$templateName] ?? 0
            ];
        }

        // Sort by title
        usort($templates, fn($a, $b) => strcmp($a['title'], $b['title']));

        return $templates;
    }

    /**
     * Get field types used across all blueprints
     *
     * @return array Array of field type info
     */
    public static function getFieldTypes(): array
    {
        $kirby = Kirby::instance();
        $usedFieldTypes = [];

        // Get ALL blueprint directories (not just pages)
        $blueprintPaths = self::getAllBlueprintPaths($kirby);

        foreach ($blueprintPaths as $blueprintsPath) {
            if (!is_dir($blueprintsPath)) continue;

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $blueprintsPath,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'yml') {
                    self::extractFieldTypesFromFile($file->getPathname(), $usedFieldTypes);
                }
            }
        }

        // Convert to list
        $fieldTypesList = [];
        foreach ($usedFieldTypes as $type => $count) {
            $fieldTypesList[] = [
                'type' => $type,
                'count' => $count
            ];
        }

        usort($fieldTypesList, fn($a, $b) => strcmp($a['type'], $b['type']));

        return $fieldTypesList;
    }

    /**
     * Get page counts per template
     * Counts all pages including drafts
     *
     * @param Kirby $kirby
     * @return array
     */
    private static function getPageCounts(Kirby $kirby): array
    {
        $pageCounts = [];
        // Use index(true) to get ALL pages including drafts
        $pages = $kirby->site()->index(true);

        foreach ($pages as $page) {
            $template = $page->intendedTemplate()->name();
            if (!isset($pageCounts[$template])) {
                $pageCounts[$template] = 0;
            }
            $pageCounts[$template]++;
        }

        return $pageCounts;
    }

    /**
     * Get blueprint paths (site + plugins) - only /pages subdirectory
     *
     * @param Kirby $kirby
     * @return array
     */
    private static function getBlueprintPaths(Kirby $kirby): array
    {
        $paths = [];

        // Site blueprints
        $sitePath = $kirby->root('blueprints') . '/pages';
        if (is_dir($sitePath)) {
            $paths[] = $sitePath;
        }

        // Plugin blueprints
        foreach ($kirby->plugins() as $plugin) {
            $pluginPath = $plugin->root() . '/blueprints/pages';
            if (is_dir($pluginPath)) {
                $paths[] = $pluginPath;
            }
        }

        return $paths;
    }

    /**
     * Get ALL blueprint paths (site + plugins) - including root /blueprints
     *
     * @param Kirby $kirby
     * @return array
     */
    private static function getAllBlueprintPaths(Kirby $kirby): array
    {
        $paths = [];

        // Site blueprints (root directory)
        $sitePath = $kirby->root('blueprints');
        if (is_dir($sitePath)) {
            $paths[] = $sitePath;
        }

        // Plugin blueprints (root directory)
        foreach ($kirby->plugins() as $plugin) {
            $pluginPath = $plugin->root() . '/blueprints';
            if (is_dir($pluginPath)) {
                $paths[] = $pluginPath;
            }
        }

        return $paths;
    }

    /**
     * Extract title from blueprint file
     *
     * @param string $blueprintFile
     * @param string $default
     * @return string
     */
    private static function extractTitle(string $blueprintFile, string $default): string
    {
        if (!file_exists($blueprintFile)) {
            return $default;
        }

        $content = @file_get_contents($blueprintFile);
        if ($content && preg_match('/^title:\s*(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return $default;
    }

    /**
     * Extract field types from a blueprint file
     *
     * @param string $file
     * @param array $usedFieldTypes
     */
    private static function extractFieldTypesFromFile(string $file, array &$usedFieldTypes): void
    {
        try {
            $content = file_get_contents($file);
            $data = \Symfony\Component\Yaml\Yaml::parse($content);
            self::extractFieldTypesRecursive($data, $usedFieldTypes);
        } catch (\Exception $e) {
            // Skip files that can't be parsed
        }
    }

    /**
     * Recursively extract field types from blueprint data
     *
     * @param mixed $data
     * @param array $usedFieldTypes
     */
    private static function extractFieldTypesRecursive($data, array &$usedFieldTypes): void
    {
        if (!is_array($data)) {
            return;
        }

        if (isset($data['type']) && is_string($data['type'])) {
            $fieldType = $data['type'];
            if (!isset($usedFieldTypes[$fieldType])) {
                $usedFieldTypes[$fieldType] = 0;
            }
            $usedFieldTypes[$fieldType]++;
        }

        foreach ($data as $value) {
            self::extractFieldTypesRecursive($value, $usedFieldTypes);
        }
    }
}
