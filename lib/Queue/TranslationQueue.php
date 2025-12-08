<?php

namespace chrfickinger\Simplify\Queue;

use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Toolkit\Dir;
use Kirby\Toolkit\F;

/**
 * Translation Queue Manager
 * Handles background translation jobs with FIFO queue processing
 */
class TranslationQueue
{
    private string $queuePath;
    private string $lockFile;

    public function __construct()
    {
        $kirby = App::instance();

        // Use logs directory for queues
        $this->queuePath = $kirby->root('logs') . '/simplify/queues';
        $this->lockFile = $this->queuePath . '/.worker.lock';

        // Ensure queue directory exists
        if (!is_dir($this->queuePath)) {
            // Use Dir::make with recursive flag
            if (!Dir::make($this->queuePath, true)) {
                throw new \Exception("Failed to create queue directory: {$this->queuePath}");
            }
        }
    }

    /**
     * Add a new job to the queue
     */
    public function addJob(string $pageId, string $variantCode, array $sourceSnapshot, bool $isManual = true): array
    {
        $kirby = App::instance();
        $page = $kirby->page($pageId);

        if (!$page) {
            throw new \Exception("Page not found: {$pageId}");
        }

        $timestamp = time();
        $uuid = bin2hex(random_bytes(8));
        $jobId = "job_{$timestamp}_{$uuid}";

        $job = [
            'id' => $jobId,
            'pageId' => $pageId,
            'pageTitle' => $page->title()->value(),
            'variantCode' => $variantCode,
            'status' => 'pending',
            'isManual' => $isManual, // Track if this is a manual or auto translation
            'sourceSnapshot' => $sourceSnapshot,
            'strategy' => null, // Will be determined by worker
            'fieldsToTranslate' => [],
            'progress' => [
                'current' => 0,
                'total' => 0,
                'currentField' => null,
                'percentage' => 0,
            ],
            'result' => [
                'translatedFields' => 0,
                'tokensUsed' => 0,
                'cost' => 0,
            ],
            'error' => null,
            'createdAt' => date('Y-m-d H:i:s', $timestamp),
            'startedAt' => null,
        ];

        $this->saveJob($job);
        return $job;
    }

    /**
     * Get a specific job by ID
     */
    public function getJob(string $jobId): ?array
    {
        $filePath = $this->getJobFilePath($jobId);

        if (!F::exists($filePath)) {
            return null;
        }

        try {
            return Json::read($filePath);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get all jobs by status
     */
    public function getJobsByStatus(string $status): array
    {
        $jobs = $this->getAllJobs();
        return array_filter($jobs, fn($job) => $job['status'] === $status);
    }

    /**
     * Get running job for a specific page and variant
     * Jobs older than 30 minutes are considered stale and ignored
     */
    public function getRunningJobForPage(string $pageId, string $variantCode): ?array
    {
        $jobs = $this->getAllJobs();
        $staleTimeout = 30 * 60; // 30 minutes in seconds

        foreach ($jobs as $job) {
            if (
                $job['pageId'] === $pageId &&
                $job['variantCode'] === $variantCode &&
                in_array($job['status'], ['pending', 'processing'])
            ) {
                // Check if job is stale (older than 30 minutes)
                $createdAt = strtotime($job['createdAt'] ?? '');
                if ($createdAt && (time() - $createdAt) > $staleTimeout) {
                    // Mark stale job as timeout
                    $this->setJobStatus($job['id'], 'timeout', 'Job timed out after 30 minutes');
                    continue;
                }
                return $job;
            }
        }

        return null;
    }

    /**
     * Update job progress
     */
    public function updateJobProgress(string $jobId, int $current, int $total, ?string $currentField = null): bool
    {
        $job = $this->getJob($jobId);

        if (!$job) {
            return false;
        }

        $job['progress'] = [
            'current' => $current,
            'total' => $total,
            'currentField' => $currentField,
            'percentage' => $total > 0 ? round(($current / $total) * 100) : 0,
        ];

        return $this->saveJob($job);
    }

    /**
     * Set job status
     */
    public function setJobStatus(string $jobId, string $status, ?string $error = null): bool
    {
        $job = $this->getJob($jobId);

        if (!$job) {
            return false;
        }

        $job['status'] = $status;

        if ($error !== null) {
            $job['error'] = $error;
        }

        if ($status === 'processing' && $job['startedAt'] === null) {
            $job['startedAt'] = date('Y-m-d H:i:s');
        }

        return $this->saveJob($job);
    }

    /**
     * Update job result (tokens, cost, translated fields)
     */
    public function updateJobResult(string $jobId, array $result): bool
    {
        $job = $this->getJob($jobId);

        if (!$job) {
            return false;
        }

        $job['result'] = array_merge($job['result'], $result);

        return $this->saveJob($job);
    }

    /**
     * Update job strategy and fields to translate
     */
    public function updateJobStrategy(string $jobId, string $strategy, array $fieldsToTranslate): bool
    {
        $job = $this->getJob($jobId);

        if (!$job) {
            return false;
        }

        $job['strategy'] = $strategy;
        $job['fieldsToTranslate'] = $fieldsToTranslate;

        return $this->saveJob($job);
    }

    /**
     * Cancel a job
     */
    public function cancelJob(string $jobId): bool
    {
        return $this->setJobStatus($jobId, 'cancelled');
    }

    /**
     * Get next pending job (FIFO)
     */
    public function getNextPendingJob(): ?array
    {
        $pendingJobs = $this->getJobsByStatus('pending');

        if (empty($pendingJobs)) {
            return null;
        }

        // Sort by creation time (oldest first)
        usort($pendingJobs, function ($a, $b) {
            return strtotime($a['createdAt']) <=> strtotime($b['createdAt']);
        });

        return $pendingJobs[0];
    }

    /**
     * Reset stuck jobs that have been processing for too long
     * Returns the number of jobs reset
     */
    public function resetStuckJobs(int $timeoutMinutes = 10): int
    {
        $processingJobs = $this->getJobsByStatus('processing');
        $resetCount = 0;
        $timeoutSeconds = $timeoutMinutes * 60;

        foreach ($processingJobs as $job) {
            $startedAt = $job['startedAt'] ?? $job['createdAt'];
            $startedTimestamp = strtotime($startedAt);
            $currentTimestamp = time();
            $elapsedSeconds = $currentTimestamp - $startedTimestamp;

            // If job has been processing for longer than timeout, mark as timeout
            if ($elapsedSeconds > $timeoutSeconds) {
                $errorMessage = "Translation timed out after {$timeoutMinutes} minutes";

                // Mark as timeout
                $this->setJobStatus($job['id'], 'timeout', $errorMessage);

                // Write to reports
                $reportsLogger = new \chrfickinger\Simplify\Logging\ReportsLogger($job['variantCode']);
                $reportsLogger->logTranslation(
                    $job['pageId'],
                    $job['pageUuid'] ?? null,
                    $job['pageTitle'] ?? $job['pageId'],
                    'TIMEOUT',
                    $errorMessage,
                    $job['isManual'] ?? false,
                    0, // tokens
                    0  // duration
                );

                // Delete the stuck job
                $this->deleteJob($job['id']);

                $resetCount++;
            }
        }

        return $resetCount;
    }

    /**
     * Delete a job file
     */
    public function deleteJob(string $jobId): bool
    {
        $filePath = $this->getJobFilePath($jobId);

        if (F::exists($filePath)) {
            return F::remove($filePath);
        }

        return false;
    }

    /**
     * Get all jobs for a variant
     */
    public function getJobsForVariant(string $variantCode): array
    {
        $jobs = $this->getAllJobs();
        return array_filter($jobs, fn($job) => $job['variantCode'] === $variantCode);
    }

    // ========================================
    // Private Helper Methods
    // ========================================

    /**
     * Get all jobs from queue directory
     */
    private function getAllJobs(): array
    {
        $files = Dir::files($this->queuePath, ['job_*.json'], true);
        $jobs = [];

        foreach ($files as $file) {
            try {
                $job = Json::read($file);
                if ($job && isset($job['id'])) {
                    $jobs[] = $job;
                }
            } catch (\Exception $e) {
                // Skip invalid job files
                continue;
            }
        }

        return $jobs;
    }

    /**
     * Save job to file
     */
    private function saveJob(array $job): bool
    {
        $filePath = $this->getJobFilePath($job['id']);

        try {
            Json::write($filePath, $job);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get job file path
     */
    private function getJobFilePath(string $jobId): string
    {
        return $this->queuePath . '/' . $jobId . '.json';
    }

    /**
     * Check if a worker is currently running
     */
    public function isWorkerRunning(): bool
    {
        if (!F::exists($this->lockFile)) {
            return false;
        }

        // Read lock file to get PID
        $lockData = F::read($this->lockFile);
        if (!$lockData) {
            return false;
        }

        $data = json_decode($lockData, true);
        if (!$data || !isset($data['pid'])) {
            return false;
        }

        // Always check lock age first - if older than 10 minutes, assume stale
        // This catches zombie processes that are still running but doing nothing
        $lockAge = time() - ($data['created'] ?? 0);
        if ($lockAge > 600) { // 10 minutes
            // Remove stale lock
            F::remove($this->lockFile);
            return false;
        }

        $pid = $data['pid'];

        // Check if process is still running (Unix/Mac)
        if (function_exists('posix_getpgid')) {
            $isRunning = posix_getpgid($pid) !== false;
            if (!$isRunning) {
                // Process died, remove stale lock
                F::remove($this->lockFile);
            }
            return $isRunning;
        }

        // Fallback: lock exists and is not too old
        return true;
    }

    /**
     * Acquire worker lock
     */
    public function acquireWorkerLock(): bool
    {
        // Check if already locked
        if ($this->isWorkerRunning()) {
            return false;
        }

        // Create lock file with PID
        $lockData = [
            'pid' => getmypid(),
            'created' => time(),
        ];

        try {
            F::write($this->lockFile, json_encode($lockData));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Release worker lock
     */
    public function releaseWorkerLock(): bool
    {
        if (F::exists($this->lockFile)) {
            return F::remove($this->lockFile);
        }
        return true;
    }
}
