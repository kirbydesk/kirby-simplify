<?php

namespace chrfickinger\Simplify\Logging;

use chrfickinger\Simplify\Logging\BaseLogger;

/**
 * Logger for worker-specific activity
 * Creates variant-specific log files in workers/ subdirectory
 */
class WorkerLogger extends BaseLogger
{
    private string $variantCode;

    public function __construct(string $variantCode)
    {
        $this->variantCode = $variantCode;
        $this->logPath = $this->getSimplifyLogsDir() . '/workers/' . $variantCode . '/worker.log';
        $this->ensureLogDirectory($this->logPath);
    }

    /**
     * Log worker job processing
     */
    public function logJobProcessing(array $data): void
    {
        $timestamp = $this->getTimestamp();
        $pageId = $data['pageId'] ?? 'unknown';
        $pageTitle = $data['pageTitle'] ?? 'Unknown';
        $status = $data['status'] ?? 'PROCESSING';
        $tokensUsed = $data['tokensUsed'] ?? 0;
        $cost = $data['cost'] ?? 0;
        $duration = $data['duration'] ?? 0;
        $error = $data['error'] ?? null;

        // Format: [TIMESTAMP] STATUS | PageID | PageTitle | Tokens | Cost | Duration
        $message = sprintf(
            "%s | %s | %s | %d tokens | $%.4f | %.2fs",
            $status,
            $pageId,
            $pageTitle,
            $tokensUsed,
            $cost,
            $duration
        );

        if ($error) {
            $message .= " | Error: " . $error;
        }

        $line = sprintf("[%s] %s\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Log job start
     */
    public function logJobStart(string $pageId, string $pageTitle): void
    {
        $this->logJobProcessing([
            'pageId' => $pageId,
            'pageTitle' => $pageTitle,
            'status' => 'START',
            'tokensUsed' => 0,
            'cost' => 0,
            'duration' => 0,
        ]);
    }

    /**
     * Log job success
     */
    public function logJobSuccess(string $pageId, string $pageTitle, int $tokens, float $cost, float $duration): void
    {
        $this->logJobProcessing([
            'pageId' => $pageId,
            'pageTitle' => $pageTitle,
            'status' => 'SUCCESS',
            'tokensUsed' => $tokens,
            'cost' => $cost,
            'duration' => $duration,
        ]);
    }

    /**
     * Log job failure
     */
    public function logJobFailure(string $pageId, string $pageTitle, string $error, float $duration = 0): void
    {
        $this->logJobProcessing([
            'pageId' => $pageId,
            'pageTitle' => $pageTitle,
            'status' => 'FAILED',
            'tokensUsed' => 0,
            'cost' => 0,
            'duration' => $duration,
            'error' => $error,
        ]);
    }

    /**
     * Log job retry
     */
    public function logJobRetry(string $pageId, string $pageTitle, int $attempt, string $reason): void
    {
        $timestamp = $this->getTimestamp();
        $message = sprintf(
            "RETRY (attempt %d) | %s | %s | Reason: %s",
            $attempt,
            $pageId,
            $pageTitle,
            $reason
        );
        $line = sprintf("[%s] %s\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Log job skip
     */
    public function logJobSkip(string $pageId, string $pageTitle, string $reason): void
    {
        $timestamp = $this->getTimestamp();
        $message = sprintf(
            "SKIP | %s | %s | Reason: %s",
            $pageId,
            $pageTitle,
            $reason
        );
        $line = sprintf("[%s] %s\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Log worker session start
     */
    public function logWorkerStart(int $queueSize): void
    {
        $timestamp = $this->getTimestamp();
        $message = sprintf(
            "========== WORKER SESSION START | Queue: %d jobs ==========",
            $queueSize
        );
        $line = sprintf("[%s] %s\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Log worker session end
     */
    public function logWorkerEnd(int $processed, int $failed, float $totalDuration): void
    {
        $timestamp = $this->getTimestamp();
        $message = sprintf(
            "========== WORKER SESSION END | Processed: %d | Failed: %d | Duration: %.2fs ==========",
            $processed,
            $failed,
            $totalDuration
        );
        $line = sprintf("[%s] %s\n\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Log general info message
     */
    public function info(string $message): void
    {
        $timestamp = $this->getTimestamp();
        $line = sprintf("[%s] INFO | %s\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Log error message
     */
    public function error(string $message): void
    {
        $timestamp = $this->getTimestamp();
        $line = sprintf("[%s] ERROR | %s\n", $timestamp, $message);
        $this->writeLogLine($line);
    }

    /**
     * Get variant code
     */
    public function getVariantCode(): string
    {
        return $this->variantCode;
    }
}
