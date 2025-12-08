<?php

namespace chrfickinger\Simplify\Logging;

use SQLite3;
use Exception;

/**
 * Reports Logger
 *
 * Manages translation reports from the perspective of language variants.
 * Separate from provider stats - can be cleared independently.
 */
class ReportsLogger
{
    private SQLite3 $db;
    private string $dbPath;

    public function __construct()
    {
        $dbDir = LogPathHelper::getSimplifyLogsDir() . '/db';
        LogPathHelper::ensureDirectory($dbDir . '/reports.sqlite');
        $this->dbPath = $dbDir . '/reports.sqlite';
        $this->initDatabase();
    }

    /**
     * Initialize database and create tables
     */
    private function initDatabase(): void
    {
        $this->db = new SQLite3($this->dbPath);

        // Create translation_reports table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS translation_reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp INTEGER NOT NULL,
                language_code TEXT NOT NULL,
                page_id TEXT,
                page_uuid TEXT,
                page_title TEXT,
                provider_id TEXT NOT NULL,
                model TEXT NOT NULL,
                action TEXT NOT NULL,
                strategy TEXT NOT NULL,
                status TEXT NOT NULL,
                fields_translated INTEGER DEFAULT 0,
                input_tokens INTEGER DEFAULT 0,
                output_tokens INTEGER DEFAULT 0,
                cost REAL,
                error TEXT
            )
        ');

        // Create indexes for better performance
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_language_code ON translation_reports(language_code)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_timestamp ON translation_reports(timestamp)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_page_uuid ON translation_reports(page_uuid)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_status ON translation_reports(status)');
    }

    /**
     * Log a translation report
     *
     * @param array $data Translation report data
     */
    public function logTranslation(array $data): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO translation_reports (
                timestamp, language_code, page_id, page_uuid, page_title,
                provider_id, model, action, strategy, status,
                fields_translated, input_tokens, output_tokens, cost, error
            ) VALUES (
                :timestamp, :language_code, :page_id, :page_uuid, :page_title,
                :provider_id, :model, :action, :strategy, :status,
                :fields_translated, :input_tokens, :output_tokens, :cost, :error
            )
        ');

        $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':language_code', $data['languageCode'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':page_id', $data['pageId'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':page_uuid', $data['pageUuid'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':page_title', $data['pageTitle'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':provider_id', $data['providerId'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':model', $data['model'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':action', $data['action'] ?? 'manual', SQLITE3_TEXT);
        $stmt->bindValue(':strategy', $data['strategy'] ?? 'full', SQLITE3_TEXT);
        $stmt->bindValue(':status', $data['status'] ?? 'SUCCESS', SQLITE3_TEXT);
        $stmt->bindValue(':fields_translated', $data['fieldsTranslated'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':input_tokens', $data['inputTokens'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':output_tokens', $data['outputTokens'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':cost', $data['cost'] ?? null, SQLITE3_NULL);
        $stmt->bindValue(':error', $data['error'] ?? null, SQLITE3_TEXT);

        $stmt->execute();
    }

    /**
     * Get translation reports for a language variant
     *
     * @param string $languageCode Language/variant code (e.g., 'de-x-ls')
     * @param int $limit Maximum number of reports to return (0 = all)
     * @param int $offset Offset for pagination
     * @return array Array of translation reports
     */
    public function getReports(string $languageCode, int $limit = 0, int $offset = 0): array
    {
        $sql = '
            SELECT * FROM translation_reports
            WHERE language_code = :language_code
            ORDER BY timestamp DESC
        ';

        if ($limit > 0) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);

        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
            $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        }

        $result = $stmt->execute();
        $reports = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $reports[] = $row;
        }

        return $reports;
    }

    /**
     * Get report count for a language variant
     *
     * @param string $languageCode Language/variant code
     * @return int Number of reports
     */
    public function getReportCount(string $languageCode): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as total FROM translation_reports
            WHERE language_code = :language_code
        ');
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return (int)($row['total'] ?? 0);
    }

    /**
     * Get summary statistics for a language variant
     *
     * @param string $languageCode Language/variant code
     * @return array Summary data
     */
    public function getSummary(string $languageCode): array
    {
        $stmt = $this->db->prepare('
            SELECT
                COUNT(*) as total_translations,
                SUM(CASE WHEN status = "SUCCESS" THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = "FAILED" THEN 1 ELSE 0 END) as failed,
                SUM(fields_translated) as total_fields,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(cost) as total_cost
            FROM translation_reports
            WHERE language_code = :language_code
        ');
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return [
            'total_translations' => (int)($row['total_translations'] ?? 0),
            'successful' => (int)($row['successful'] ?? 0),
            'failed' => (int)($row['failed'] ?? 0),
            'total_fields' => (int)($row['total_fields'] ?? 0),
            'total_input_tokens' => (int)($row['total_input_tokens'] ?? 0),
            'total_output_tokens' => (int)($row['total_output_tokens'] ?? 0),
            'total_tokens' => (int)(($row['total_input_tokens'] ?? 0) + ($row['total_output_tokens'] ?? 0)),
            'total_cost' => (float)($row['total_cost'] ?? 0)
        ];
    }

    /**
     * Clear all reports for a language variant
     *
     * @param string $languageCode Language/variant code
     * @return bool Success status
     */
    public function clearReports(string $languageCode): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM translation_reports
            WHERE language_code = :language_code
        ');
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    /**
     * Delete a single report entry by language code and timestamp
     *
     * @param string $languageCode Language/variant code
     * @param int $timestamp Timestamp of the entry to delete
     * @return bool Success status
     */
    public function deleteReportByTimestamp(string $languageCode, int $timestamp): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM translation_reports
            WHERE language_code = :language_code AND timestamp = :timestamp
        ');
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
