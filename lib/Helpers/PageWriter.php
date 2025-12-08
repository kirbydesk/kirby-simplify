<?php

namespace kirbydesk\Simplify\Helpers;

use Kirby\Cms\Page;
use Kirby\Cms\App as Kirby;
use kirbydesk\Simplify\Logging\Logger;

/**
 * Page Writer
 *
 * Handles writing simplified content to Kirby pages
 * Uses Kirby's native Txt encoder/decoder
 */
class PageWriter
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Write fields to page using Kirby's native Txt encoder/decoder
     *
     * @param Page $page Page to update
     * @param string $targetLang Target language code
     * @param array $fields Fields to write
     * @param array $metadata Additional metadata to add (optional)
     * @return void
     * @throws \Exception If writing fails
     */
    public function writeToPage(Page $page, string $targetLang, array $fields, array $metadata = []): void
    {
        try {
            // Build the target file path
            $contentDir = $page->root();
            $blueprint = $page->intendedTemplate()->name();
            $targetFile = $contentDir . "/" . $blueprint . "." . $targetLang . ".txt";

            $this->logger->info("Writing to file: {$targetFile}");

            // Read existing content to preserve field order
            $allFields = [];
            if (file_exists($targetFile)) {
                $existingContent = file_get_contents($targetFile);
                $allFields = \Kirby\Data\Txt::decode($existingContent);
            }

            // Merge fields and metadata (new values override existing)
            $allFields = array_merge($allFields, $fields, $metadata);

            // Encode back to Kirby format using native encoder
            $newContent = \Kirby\Data\Txt::encode($allFields);

            // Write to file
            if (!is_dir($contentDir)) {
                mkdir($contentDir, 0755, true);
            }

            $result = file_put_contents($targetFile, $newContent);

            if ($result === false) {
                throw new \Exception("Failed to write file: {$targetFile}");
            }

            $this->logger->info(
                "Written " . count($allFields) . " fields to page {$page->id()} for language {$targetLang} ({$result} bytes)"
            );

        } catch (\Exception $e) {
            $this->logger->error("Failed to write to page: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Write fields to page manually (legacy wrapper for backwards compatibility)
     *
     * @param Page $page Page to update
     * @param string $targetLang Target language code
     * @param array $fields Fields to write
     * @param array $metadata Additional metadata to add
     * @return void
     */
    public function writeToPageManual(
        Page $page,
        string $targetLang,
        array $fields,
        array $metadata = []
    ): void {
        $this->writeToPage($page, $targetLang, $fields, $metadata);
    }
}
