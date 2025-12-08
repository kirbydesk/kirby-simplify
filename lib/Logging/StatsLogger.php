<?php

namespace kirbydesk\Simplify\Logging;

use SQLite3;
use Exception;

class StatsLogger
{
    private $db;
    private $dbPath;

    public function __construct()
    {
        $dbDir = LogPathHelper::getSimplifyLogsDir() . '/db';
        LogPathHelper::ensureDirectory($dbDir . '/stats.sqlite');
        $this->dbPath = $dbDir . '/stats.sqlite';
        $this->initDatabase();
    }

    private function initDatabase()
    {
        $this->db = new SQLite3($this->dbPath);

        // Create table if not exists
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS api_calls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp INTEGER NOT NULL,
                provider_id TEXT NOT NULL,
                model TEXT NOT NULL,
                input_tokens INTEGER NOT NULL DEFAULT 0,
                output_tokens INTEGER NOT NULL DEFAULT 0,
                cost REAL,
                success INTEGER NOT NULL DEFAULT 1,
                error TEXT,
                context TEXT,
                page_id TEXT,
                language_code TEXT
            )
        ');

        // Add page_id column if it doesn't exist (migration for existing databases)
        try {
            $this->db->exec('ALTER TABLE api_calls ADD COLUMN page_id TEXT');
        } catch (Exception $e) {
            // Column already exists, ignore error
        }

        // Add language_code column if it doesn't exist (migration for existing databases)
        try {
            $this->db->exec('ALTER TABLE api_calls ADD COLUMN language_code TEXT');
        } catch (Exception $e) {
            // Column already exists, ignore error
        }

        // Add new columns for translation tracking
        $newColumns = [
            'page_uuid TEXT',
            'page_title TEXT',
            'action TEXT',
            'strategy TEXT',
            'status TEXT',
            'fields_translated INTEGER DEFAULT 0'
        ];

        foreach ($newColumns as $column) {
            try {
                $this->db->exec("ALTER TABLE api_calls ADD COLUMN {$column}");
            } catch (Exception $e) {
                // Column already exists, ignore error
            }
        }

        // Create indexes for better performance
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_timestamp ON api_calls(timestamp)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_provider ON api_calls(provider_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_success ON api_calls(success)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_page_id ON api_calls(page_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_language_code ON api_calls(language_code)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_page_uuid ON api_calls(page_uuid)');
    }

    /**
     * Log an API call
     *
     * @param string $providerId Provider identifier
     * @param string $model Model name
     * @param int $inputTokens Input tokens used
     * @param int $outputTokens Output tokens used
     * @param float|null $cost Calculated cost (null if pricing unavailable)
     * @param bool $success Whether the call succeeded
     * @param string|null $error Error message if failed
     * @param string $context Context (e.g., 'translation', 'test')
     * @param string|null $pageId Page ID being translated (optional)
     * @param string|null $languageCode Language/variant code (e.g., 'de-x-ls')
     */
    public function logApiCall(
        string $providerId,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?float $cost,
        bool $success = true,
        ?string $error = null,
        string $context = 'translation',
        ?string $pageId = null,
        ?string $languageCode = null
    ): void {
        $stmt = $this->db->prepare('
            INSERT INTO api_calls (
                timestamp, provider_id, model, input_tokens, output_tokens,
                cost, success, error, context, page_id, language_code
            ) VALUES (
                :timestamp, :provider_id, :model, :input_tokens, :output_tokens,
                :cost, :success, :error, :context, :page_id, :language_code
            )
        ');

        $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':model', $model, SQLITE3_TEXT);
        $stmt->bindValue(':input_tokens', $inputTokens, SQLITE3_INTEGER);
        $stmt->bindValue(':output_tokens', $outputTokens, SQLITE3_INTEGER);
        $stmt->bindValue(':cost', $cost, $cost === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':success', $success ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':error', $error, SQLITE3_TEXT);
        $stmt->bindValue(':context', $context, SQLITE3_TEXT);
        $stmt->bindValue(':page_id', $pageId, SQLITE3_TEXT);
        $stmt->bindValue(':language_code', $languageCode, SQLITE3_TEXT);

        $stmt->execute();
    }

    /**
     * Log a translation activity
     *
     * @param array $data Translation data
     */
    public function logTranslation(array $data): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO api_calls (
                timestamp, provider_id, model, input_tokens, output_tokens,
                cost, success, error, context, page_id, language_code,
                page_uuid, page_title, action, strategy, status, fields_translated
            ) VALUES (
                :timestamp, :provider_id, :model, :input_tokens, :output_tokens,
                :cost, :success, :error, :context, :page_id, :language_code,
                :page_uuid, :page_title, :action, :strategy, :status, :fields_translated
            )
        ');

        $cost = $data['cost'] ?? null;

        $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':provider_id', $data['providerId'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':model', $data['model'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':input_tokens', $data['inputTokens'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':output_tokens', $data['outputTokens'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':cost', $cost, $cost === null ? SQLITE3_NULL : SQLITE3_FLOAT);
        $stmt->bindValue(':success', ($data['success'] ?? true) ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':error', $data['error'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':context', 'translation', SQLITE3_TEXT);
        $stmt->bindValue(':page_id', $data['pageId'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':language_code', $data['languageCode'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':page_uuid', $data['pageUuid'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':page_title', $data['pageTitle'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':action', $data['action'] ?? 'manual', SQLITE3_TEXT);
        $stmt->bindValue(':strategy', $data['strategy'] ?? 'full', SQLITE3_TEXT);
        $stmt->bindValue(':status', $data['status'] ?? 'SUCCESS', SQLITE3_TEXT);
        $stmt->bindValue(':fields_translated', $data['fieldsTranslated'] ?? 0, SQLITE3_INTEGER);

        $stmt->execute();
    }

    /**
     * Get statistics for a provider
     *
     * @param string $providerId Provider identifier
     * @param string $period Period ('today', 'week', 'month', 'year', 'all')
     * @param int|null $from Custom start timestamp (optional)
     * @param int|null $to Custom end timestamp (optional)
     * @return array Statistics data
     */
    public function getStats(string $providerId, string $period = 'month', ?int $from = null, ?int $to = null): array
    {
        // Use custom timestamps if provided, otherwise use period
        if ($from !== null && $to !== null) {
            $startTime = $from;
            $endTime = $to;
        } else {
            $startTime = $this->getStartTime($period);
            $endTime = time();
        }

        // Total calls
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as total FROM api_calls
            WHERE provider_id = :provider_id AND timestamp >= :start_time AND timestamp <= :end_time
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
        $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $totalCalls = $result->fetchArray(SQLITE3_ASSOC)['total'];

        // Successful calls
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as total FROM api_calls
            WHERE provider_id = :provider_id AND timestamp >= :start_time AND timestamp <= :end_time AND success = 1
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
        $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $successfulCalls = $result->fetchArray(SQLITE3_ASSOC)['total'];

        // Token usage and costs
        $stmt = $this->db->prepare('
            SELECT
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(cost) as total_cost
            FROM api_calls
            WHERE provider_id = :provider_id AND timestamp >= :start_time AND timestamp <= :end_time
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
        $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $usage = $result->fetchArray(SQLITE3_ASSOC);

        // Unique pages translated (only count translation context with page_id)
        $stmt = $this->db->prepare('
            SELECT COUNT(DISTINCT page_id) as unique_pages FROM api_calls
            WHERE provider_id = :provider_id
            AND timestamp >= :start_time
            AND timestamp <= :end_time
            AND context = :context
            AND page_id IS NOT NULL
            AND page_id != ""
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
        $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);
        $stmt->bindValue(':context', 'translation', SQLITE3_TEXT);
        $result = $stmt->execute();
        $uniquePages = $result->fetchArray(SQLITE3_ASSOC)['unique_pages'];

        // Unique languages (only count translation context with language_code)
        $stmt = $this->db->prepare('
            SELECT COUNT(DISTINCT language_code) as unique_languages FROM api_calls
            WHERE provider_id = :provider_id
            AND timestamp >= :start_time
            AND timestamp <= :end_time
            AND context = :context
            AND language_code IS NOT NULL
            AND language_code != ""
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
        $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);
        $stmt->bindValue(':context', 'translation', SQLITE3_TEXT);
        $result = $stmt->execute();
        $uniqueLanguages = $result->fetchArray(SQLITE3_ASSOC)['unique_languages'];

        // Recent calls (all entries in timespan)
        $stmt = $this->db->prepare('
            SELECT * FROM api_calls
            WHERE provider_id = :provider_id AND timestamp >= :start_time AND timestamp <= :end_time
            ORDER BY timestamp DESC
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $startTime, SQLITE3_INTEGER);
        $stmt->bindValue(':end_time', $endTime, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $recentCalls = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Ensure cost is properly null (SQLite returns empty string for null sometimes)
            if ($row['cost'] === '' || $row['cost'] === null) {
                $row['cost'] = null;
            } else {
                $row['cost'] = (float)$row['cost'];
            }
            $recentCalls[] = $row;
        }

        // Calculate total cost (null if all entries are null, otherwise sum non-null values)
        $totalCost = $usage['total_cost'] !== null ? round((float)$usage['total_cost'], 4) : null;

        return [
            'period' => $period,
            'total_calls' => (int)$totalCalls,
            'successful_calls' => (int)$successfulCalls,
            'failed_calls' => (int)($totalCalls - $successfulCalls),
            'error_rate' => $totalCalls > 0 ? round((($totalCalls - $successfulCalls) / $totalCalls) * 100, 2) : 0,
            'unique_pages' => (int)$uniquePages,
            'unique_languages' => (int)$uniqueLanguages,
            'total_input_tokens' => (int)($usage['total_input_tokens'] ?? 0),
            'total_output_tokens' => (int)($usage['total_output_tokens'] ?? 0),
            'total_tokens' => (int)(($usage['total_input_tokens'] ?? 0) + ($usage['total_output_tokens'] ?? 0)),
            'total_cost' => $totalCost,
            'recent_calls' => $recentCalls
        ];
    }

    /**
     * Get start time for a period
     */
    private function getStartTime(string $period): int
    {
        switch ($period) {
            case 'day':
            case 'today':
                return strtotime('today');
            case 'week':
                return strtotime('-7 days');
            case 'month':
                return strtotime('first day of this month 00:00:00');
            case 'year':
                return strtotime('first day of january this year 00:00:00');
            case 'all':
            default:
                return 0;
        }
    }

    /**
     * Reset all stats for a provider
     *
     * @param string $providerId Provider identifier
     * @return bool Success status
     */
    public function resetStats(string $providerId): bool
    {
        $stmt = $this->db->prepare('
            DELETE FROM api_calls
            WHERE provider_id = :provider_id
        ');
        $stmt->bindValue(':provider_id', $providerId, SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    public function __destruct()
    {
        if ($this->db) {
            $this->db->close();
        }
    }
}
