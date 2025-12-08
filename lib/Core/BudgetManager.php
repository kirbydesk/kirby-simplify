<?php

namespace chrfickinger\Simplify\Core;

use SQLite3;
use Exception;
use Kirby\Cms\App as Kirby;
use chrfickinger\Simplify\Logging\BaseLogger;

/**
 * Budget Manager
 *
 * Manages daily and monthly budget limits per provider using SQLite.
 * Tracks aggregated costs and token usage independently from stats.
 */
class BudgetManager
{
    /**
     * Default pricing unit: per 1 million tokens (industry standard)
     */
    public const DEFAULT_PER_TOKENS = 1000000;

    /**
     * Default currency for pricing
     */
    public const DEFAULT_CURRENCY = 'USD';

    private SQLite3 $db;
    private string $dbPath;
    private string $providerId;

    /**
     * @param string $providerId Provider ID (e.g., 'gemini-flash')
     */
    public function __construct(string $providerId)
    {
        $this->providerId = $providerId;

        // Use BaseLogger's method to get logs directory
        $kirby = Kirby::instance();
        $dbDir = $kirby->root('logs') . '/simplify/db';

        if (!is_dir($dbDir)) {
            \Kirby\Toolkit\Dir::make($dbDir, true);
        }

        $this->dbPath = $dbDir . '/budget.sqlite';
        $this->initDatabase();
    }

    /**
     * Initialize database and create tables
     */
    private function initDatabase(): void
    {
        $this->db = new SQLite3($this->dbPath);

        // Create budget_usage table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS budget_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider_id TEXT NOT NULL,
                period_type TEXT NOT NULL,
                period_key TEXT NOT NULL,
                total_cost REAL DEFAULT 0,
                total_tokens INTEGER DEFAULT 0,
                api_calls INTEGER DEFAULT 0,
                last_updated INTEGER NOT NULL,
                UNIQUE(provider_id, period_type, period_key)
            )
        ');

        // Create indexes for better performance
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_provider_period ON budget_usage(provider_id, period_type, period_key)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_last_updated ON budget_usage(last_updated)');

        // Create budget_settings table
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS budget_settings (
                provider_id TEXT PRIMARY KEY,
                daily_budget_enabled INTEGER DEFAULT 0,
                daily_budget REAL DEFAULT 0,
                monthly_budget_enabled INTEGER DEFAULT 0,
                monthly_budget REAL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        ');
    }

    /**
     * Check if operation is within budget limits (daily and monthly)
     *
     * @param int $estimatedTokens Estimated total tokens for this call
     * @param float $estimatedCost Estimated cost for this call
     * @return bool True if within budget
     * @throws Exception if budget would be exceeded
     */
    public function ensureWithinLimit(int $estimatedTokens, float $estimatedCost): bool
    {
        $settings = $this->loadSettings();
        $dailyBudget = $settings['dailyBudget'] ?? 0;
        $monthlyBudget = $settings['monthlyBudget'] ?? 0;

        // If no budgets set (both 0), allow all
        if ($dailyBudget <= 0 && $monthlyBudget <= 0) {
            return true;
        }

        // Check daily budget (only if > 0)
        if ($dailyBudget > 0) {
            $dailyUsage = $this->getUsage('daily');
            $projectedDaily = $dailyUsage['total_cost'] + $estimatedCost;

            if ($projectedDaily > $dailyBudget) {
                throw new Exception(sprintf(
                    'Daily budget limit exceeded for provider %s: $%.4f spent + $%.4f estimated = $%.4f (limit: $%.4f)',
                    $this->providerId,
                    $dailyUsage['total_cost'],
                    $estimatedCost,
                    $projectedDaily,
                    $dailyBudget
                ));
            }
        }

        // Check monthly budget (only if > 0)
        if ($monthlyBudget > 0) {
            $monthlyUsage = $this->getUsage('monthly');
            $projectedMonthly = $monthlyUsage['total_cost'] + $estimatedCost;

            if ($projectedMonthly > $monthlyBudget) {
                throw new Exception(sprintf(
                    'Monthly budget limit exceeded for provider %s: $%.4f spent + $%.4f estimated = $%.4f (limit: $%.4f)',
                    $this->providerId,
                    $monthlyUsage['total_cost'],
                    $estimatedCost,
                    $projectedMonthly,
                    $monthlyBudget
                ));
            }
        }

        return true;
    }

    /**
     * Record actual usage after API call
     *
     * @param int $inputTokens Actual input tokens
     * @param int $outputTokens Actual output tokens
     * @param float|null $cost Actual cost (null if pricing data unavailable)
     */
    public function record(int $inputTokens, int $outputTokens, ?float $cost): void
    {
        $totalTokens = $inputTokens + $outputTokens;
        $now = time();

        // Only record cost if available, otherwise just track tokens
        $costToRecord = $cost ?? 0;

        // Record for daily budget
        $this->incrementUsage('daily', date('Y-m-d'), $totalTokens, $costToRecord, $now);

        // Record for monthly budget
        $this->incrementUsage('monthly', date('Y-m'), $totalTokens, $costToRecord, $now);
    }

    /**
     * Increment usage for a specific period
     *
     * @param string $periodType 'daily' or 'monthly'
     * @param string $periodKey Date string (e.g., '2025-01-17' or '2025-01')
     * @param int $tokens Tokens to add
     * @param float $cost Cost to add
     * @param int $timestamp Current timestamp
     */
    private function incrementUsage(string $periodType, string $periodKey, int $tokens, float $cost, int $timestamp): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO budget_usage (provider_id, period_type, period_key, total_cost, total_tokens, api_calls, last_updated)
            VALUES (:provider_id, :period_type, :period_key, :cost, :tokens, 1, :timestamp)
            ON CONFLICT(provider_id, period_type, period_key)
            DO UPDATE SET
                total_cost = total_cost + :cost,
                total_tokens = total_tokens + :tokens,
                api_calls = api_calls + 1,
                last_updated = :timestamp
        ');

        $stmt->bindValue(':provider_id', $this->providerId, SQLITE3_TEXT);
        $stmt->bindValue(':period_type', $periodType, SQLITE3_TEXT);
        $stmt->bindValue(':period_key', $periodKey, SQLITE3_TEXT);
        $stmt->bindValue(':cost', $cost, SQLITE3_FLOAT);
        $stmt->bindValue(':tokens', $tokens, SQLITE3_INTEGER);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_INTEGER);

        $stmt->execute();
    }

    /**
     * Get usage for a specific period type (daily or monthly)
     *
     * @param string $periodType 'daily' or 'monthly'
     * @param string|null $periodKey Specific period key (defaults to current period)
     * @return array Usage data with total_cost, total_tokens, api_calls
     */
    public function getUsage(string $periodType, ?string $periodKey = null): array
    {
        if ($periodKey === null) {
            $periodKey = $periodType === 'daily' ? date('Y-m-d') : date('Y-m');
        }

        $stmt = $this->db->prepare('
            SELECT total_cost, total_tokens, api_calls, last_updated
            FROM budget_usage
            WHERE provider_id = :provider_id AND period_type = :period_type AND period_key = :period_key
        ');

        $stmt->bindValue(':provider_id', $this->providerId, SQLITE3_TEXT);
        $stmt->bindValue(':period_type', $periodType, SQLITE3_TEXT);
        $stmt->bindValue(':period_key', $periodKey, SQLITE3_TEXT);

        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if (!$row) {
            return [
                'total_cost' => 0,
                'total_tokens' => 0,
                'api_calls' => 0,
                'last_updated' => null
            ];
        }

        return [
            'total_cost' => (float)$row['total_cost'],
            'total_tokens' => (int)$row['total_tokens'],
            'api_calls' => (int)$row['api_calls'],
            'last_updated' => (int)$row['last_updated']
        ];
    }

    /**
     * Get budget summary for panel display
     *
     * @return array Summary with daily and monthly usage, limits
     */
    public function getSummary(): array
    {
        // Load settings from database
        $settings = $this->loadSettings();
        $dailyBudget = $settings['dailyBudget'];
        $monthlyBudget = $settings['monthlyBudget'];

        $dailyUsage = $this->getUsage('daily');
        $monthlyUsage = $this->getUsage('monthly');

        // Calculate percentages
        $dailyPercent = $dailyBudget > 0 ? ($dailyUsage['total_cost'] / $dailyBudget) * 100 : 0;
        $monthlyPercent = $monthlyBudget > 0 ? ($monthlyUsage['total_cost'] / $monthlyBudget) * 100 : 0;

        // Determine exceeded status
        $dailyExceeded = $dailyBudget > 0 && $dailyUsage['total_cost'] >= $dailyBudget;
        $monthlyExceeded = $monthlyBudget > 0 && $monthlyUsage['total_cost'] >= $monthlyBudget;

        return [
            'provider_id' => $this->providerId,
            'daily' => [
                'budget' => $dailyBudget,
                'spent' => $dailyUsage['total_cost'],
                'remaining' => max(0, $dailyBudget - $dailyUsage['total_cost']),
                'percent' => round($dailyPercent, 2),
                'tokens' => $dailyUsage['total_tokens'],
                'calls' => $dailyUsage['api_calls'],
                'exceeded' => $dailyExceeded,
                'period' => date('Y-m-d')
            ],
            'monthly' => [
                'budget' => $monthlyBudget,
                'spent' => $monthlyUsage['total_cost'],
                'remaining' => max(0, $monthlyBudget - $monthlyUsage['total_cost']),
                'percent' => round($monthlyPercent, 2),
                'tokens' => $monthlyUsage['total_tokens'],
                'calls' => $monthlyUsage['api_calls'],
                'exceeded' => $monthlyExceeded,
                'period' => date('Y-m')
            ]
        ];
    }

    /**
     * Reset budget for a specific period
     *
     * @param string $periodType 'daily' or 'monthly'
     * @param string|null $periodKey Specific period key (defaults to current period)
     */
    public function reset(string $periodType = 'monthly', ?string $periodKey = null): void
    {
        if ($periodKey === null) {
            $periodKey = $periodType === 'daily' ? date('Y-m-d') : date('Y-m');
        }

        $stmt = $this->db->prepare('
            DELETE FROM budget_usage
            WHERE provider_id = :provider_id AND period_type = :period_type AND period_key = :period_key
        ');

        $stmt->bindValue(':provider_id', $this->providerId, SQLITE3_TEXT);
        $stmt->bindValue(':period_type', $periodType, SQLITE3_TEXT);
        $stmt->bindValue(':period_key', $periodKey, SQLITE3_TEXT);

        $stmt->execute();
    }

    /**
     * Estimate tokens from text (rough approximation)
     *
     * @param string $text Text to estimate
     * @return int Estimated token count (1 token ≈ 4 characters)
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Save budget settings for this provider
     *
     * @param array $settings Settings to save (dailyBudget, monthlyBudget)
     * @return bool Success status
     */
    public function saveSettings(array $settings): bool
    {
        $timestamp = time();

        // Check if settings exist
        $stmt = $this->db->prepare('SELECT provider_id FROM budget_settings WHERE provider_id = :provider_id');
        $stmt->bindValue(':provider_id', $this->providerId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $exists = $result->fetchArray(SQLITE3_ASSOC) !== false;

        if ($exists) {
            // Update existing settings
            $stmt = $this->db->prepare('
                UPDATE budget_settings
                SET daily_budget = :daily_budget,
                    monthly_budget = :monthly_budget,
                    updated_at = :updated_at
                WHERE provider_id = :provider_id
            ');
        } else {
            // Insert new settings
            $stmt = $this->db->prepare('
                INSERT INTO budget_settings
                (provider_id, daily_budget, monthly_budget, created_at, updated_at)
                VALUES (:provider_id, :daily_budget, :monthly_budget, :created_at, :updated_at)
            ');
            $stmt->bindValue(':created_at', $timestamp, SQLITE3_INTEGER);
        }

        $stmt->bindValue(':provider_id', $this->providerId, SQLITE3_TEXT);
        $stmt->bindValue(':daily_budget', $settings['dailyBudget'] ?? 0, SQLITE3_FLOAT);
        $stmt->bindValue(':monthly_budget', $settings['monthlyBudget'] ?? 0, SQLITE3_FLOAT);
        $stmt->bindValue(':updated_at', $timestamp, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    /**
     * Load budget settings for this provider
     *
     * @return array Settings array with defaults if not found
     */
    public function loadSettings(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM budget_settings WHERE provider_id = :provider_id');
        $stmt->bindValue(':provider_id', $this->providerId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            return [
                'dailyBudget' => (float)$row['daily_budget'],
                'monthlyBudget' => (float)$row['monthly_budget'],
            ];
        }

        // Return defaults if not found (0 = no limit)
        return [
            'dailyBudget' => 0,
            'monthlyBudget' => 0,
        ];
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }
}
