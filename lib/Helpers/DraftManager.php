<?php

namespace kirbydesk\Simplify\Helpers;

use Kirby\Cms\Page;
use Kirby\Cms\App as Kirby;
use kirbydesk\Simplify\Logging\Logger;

/**
 * Draft Manager
 *
 * Handles storage and retrieval of preview drafts
 */
class DraftManager
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Store preview draft
     *
     * @param Page $page Page being processed
     * @param string $targetLang Target language code
     * @param array $fields Simplified fields
     * @return void
     */
    public function storeDraft(
        Page $page,
        string $targetLang,
        array $fields,
    ): void {
        $draftPath = $this->getDraftPath();
        $draftFile =
            $draftPath . "/" . $page->id() . "-" . $targetLang . ".json";

        // Ensure directory exists
        if (!is_dir($draftPath)) {
            mkdir($draftPath, 0755, true);
        }

        $draft = [
            "pageId" => $page->id(),
            "targetLang" => $targetLang,
            "timestamp" => time(),
            "fields" => $fields,
        ];

        file_put_contents($draftFile, json_encode($draft, JSON_PRETTY_PRINT));

        $this->logger->info("Draft stored for {$page->id()} ({$targetLang})");
    }

    /**
     * Get stored draft
     *
     * @param Page $page Page to get draft for
     * @param string $targetLang Target language code
     * @return array|null Draft data or null if not found
     */
    public function getDraft(Page $page, string $targetLang): ?array
    {
        $draftPath = $this->getDraftPath();
        $draftFile =
            $draftPath . "/" . $page->id() . "-" . $targetLang . ".json";

        if (!file_exists($draftFile)) {
            return null;
        }

        $draft = json_decode(file_get_contents($draftFile), true);

        return $draft;
    }

    /**
     * Delete draft
     *
     * @param Page $page Page to delete draft for
     * @param string $targetLang Target language code
     * @return void
     */
    public function deleteDraft(Page $page, string $targetLang): void
    {
        $draftPath = $this->getDraftPath();
        $draftFile =
            $draftPath . "/" . $page->id() . "-" . $targetLang . ".json";

        if (file_exists($draftFile)) {
            unlink($draftFile);
            $this->logger->info(
                "Draft deleted for {$page->id()} ({$targetLang})",
            );
        }
    }

    /**
     * Get draft storage path
     *
     * @return string Path to drafts directory
     */
    private function getDraftPath(): string
    {
        return Kirby::instance()->root("content") . "/.simplify-drafts";
    }
}
