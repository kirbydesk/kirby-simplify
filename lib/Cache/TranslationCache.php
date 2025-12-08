<?php

namespace kirbydesk\Simplify\Cache;

use SQLite3;
use Exception;
use Kirby\Cms\App;

/**
 * Translation Cache
 *
 * Caches translated field contents to avoid re-translating unchanged content.
 * Uses MD5 hash of source content to detect changes.
 */
class TranslationCache
{
    private $db;
    private $dbPath;

    public function __construct()
    {
        $kirby = App::instance();
        $dbDir = $kirby->root('logs') . '/simplify/db';
        $this->dbPath = $dbDir . '/translation-cache.sqlite';

        // Ensure directory exists
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }

        $this->initDatabase();
    }

    private function initDatabase()
    {
        $this->db = new SQLite3($this->dbPath);

        // Create translation cache table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS translation_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_uuid TEXT NOT NULL,
                language_code TEXT NOT NULL,
                field_name TEXT NOT NULL,
                field_type TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                prompt_hash TEXT NOT NULL DEFAULT "",
                source_content TEXT NOT NULL,
                translated_content TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                UNIQUE(page_uuid, language_code, field_name)
            )
        ');

        // Add prompt_hash column if it doesn't exist (migration for existing databases)
        $result = $this->db->query("PRAGMA table_info(translation_cache)");
        $hasPromptHash = false;
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if ($row['name'] === 'prompt_hash') {
                $hasPromptHash = true;
                break;
            }
        }
        if (!$hasPromptHash) {
            $this->db->exec('ALTER TABLE translation_cache ADD COLUMN prompt_hash TEXT NOT NULL DEFAULT ""');
        }

        // Create indexes
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_page_language ON translation_cache(page_uuid, language_code)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_hash_lookup ON translation_cache(page_uuid, language_code, field_name, source_hash)');
    }

    /**
     * Get cached translation if source content hash and prompt hash match
     *
     * @param string $pageUuid Page UUID
     * @param string $languageCode Target language code
     * @param string $fieldName Field name
     * @param string $sourceContent Current source content
     * @param string $promptHash Hash of the current prompt configuration
     * @return string|null Cached translation or null if not found/outdated
     */
    public function get(string $pageUuid, string $languageCode, string $fieldName, string $sourceContent, string $promptHash = ''): ?string
    {
        $sourceHash = md5($sourceContent);

        $stmt = $this->db->prepare('
            SELECT translated_content
            FROM translation_cache
            WHERE page_uuid = :page_uuid
                AND language_code = :language_code
                AND field_name = :field_name
                AND source_hash = :source_hash
                AND (prompt_hash = :prompt_hash OR prompt_hash = "")
        ');

        $stmt->bindValue(':page_uuid', $pageUuid, SQLITE3_TEXT);
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);
        $stmt->bindValue(':field_name', $fieldName, SQLITE3_TEXT);
        $stmt->bindValue(':source_hash', $sourceHash, SQLITE3_TEXT);
        $stmt->bindValue(':prompt_hash', $promptHash, SQLITE3_TEXT);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ? $row['translated_content'] : null;
    }

    /**
     * Store or update translation in cache
     *
     * @param string $pageUuid Page UUID
     * @param string $languageCode Target language code
     * @param string $fieldName Field name
     * @param string $fieldType Field type (e.g., 'text', 'blocks', etc.)
     * @param string $sourceContent Source content
     * @param string $translatedContent Translated content
     * @param string $promptHash Hash of the prompt configuration
     */
    public function set(
        string $pageUuid,
        string $languageCode,
        string $fieldName,
        string $fieldType,
        string $sourceContent,
        string $translatedContent,
        string $promptHash = ''
    ): void {
        $sourceHash = md5($sourceContent);
        $now = time();

        $stmt = $this->db->prepare('
            INSERT INTO translation_cache (
                page_uuid, language_code, field_name, field_type,
                source_hash, prompt_hash, source_content, translated_content,
                created_at, updated_at
            ) VALUES (
                :page_uuid, :language_code, :field_name, :field_type,
                :source_hash, :prompt_hash, :source_content, :translated_content,
                :created_at, :updated_at
            )
            ON CONFLICT(page_uuid, language_code, field_name)
            DO UPDATE SET
                source_hash = :source_hash,
                prompt_hash = :prompt_hash,
                source_content = :source_content,
                translated_content = :translated_content,
                field_type = :field_type,
                updated_at = :updated_at
        ');

        $stmt->bindValue(':page_uuid', $pageUuid, SQLITE3_TEXT);
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);
        $stmt->bindValue(':field_name', $fieldName, SQLITE3_TEXT);
        $stmt->bindValue(':field_type', $fieldType, SQLITE3_TEXT);
        $stmt->bindValue(':source_hash', $sourceHash, SQLITE3_TEXT);
        $stmt->bindValue(':prompt_hash', $promptHash, SQLITE3_TEXT);
        $stmt->bindValue(':source_content', $sourceContent, SQLITE3_TEXT);
        $stmt->bindValue(':translated_content', $translatedContent, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', $now, SQLITE3_INTEGER);

        $stmt->execute();
    }

    /**
     * Clear all cached translations for a page
     *
     * @param string $pageUuid Page UUID
     * @param string|null $languageCode Optional: only clear specific language
     */
    public function clearPage(string $pageUuid, ?string $languageCode = null): void
    {
        if ($languageCode) {
            $stmt = $this->db->prepare('
                DELETE FROM translation_cache
                WHERE page_uuid = :page_uuid AND language_code = :language_code
            ');
            $stmt->bindValue(':page_uuid', $pageUuid, SQLITE3_TEXT);
            $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);
        } else {
            $stmt = $this->db->prepare('
                DELETE FROM translation_cache
                WHERE page_uuid = :page_uuid
            ');
            $stmt->bindValue(':page_uuid', $pageUuid, SQLITE3_TEXT);
        }

        $stmt->execute();
    }

    /**
     * Clear all cached translations for a language variant
     *
     * @param string $languageCode Language/variant code (e.g., 'de-x-ls')
     */
    public function clearLanguage(string $languageCode): void
    {
        $stmt = $this->db->prepare('
            DELETE FROM translation_cache
            WHERE language_code = :language_code
        ');
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        $result = $this->db->query('
            SELECT
                COUNT(*) as total_entries,
                COUNT(DISTINCT page_uuid) as unique_pages,
                COUNT(DISTINCT language_code) as unique_languages,
                SUM(LENGTH(translated_content)) as total_size
            FROM translation_cache
        ');

        return $result->fetchArray(SQLITE3_ASSOC);
    }

    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
}
