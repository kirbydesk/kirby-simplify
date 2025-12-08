#!/usr/bin/env php
<?php

/**
 * Translation Worker CLI Script
 *
 * Processes background translation jobs asynchronously
 *
 * Usage:
 *   php worker.php [projectRoot] [jobId]  - Process specific job with explicit project root
 *   php worker.php [jobId]                 - Process specific job (auto-detect project)
 *   php worker.php                         - Process next pending job (auto-detect project)
 */

// Verify PHP version early
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "Error: PHP 8.1.0 or higher is required. Current version: " . PHP_VERSION . "\n");
    exit(1);
}

echo "PHP Version: " . PHP_VERSION . "\n";

// Check if project root was passed as first argument
$projectRoot = null;
$jobId = null;

if (isset($argv[1]) && is_dir($argv[1])) {
    // New format: php worker.php /path/to/project jobId
    $projectRoot = $argv[1];
    $jobId = $argv[2] ?? null;
    echo "Using provided project root: {$projectRoot}\n";
} else {
    // Legacy format: php worker.php jobId (auto-detect project)
    $jobId = $argv[1] ?? null;
    echo "Auto-detecting project root...\n";

    // Find and load Kirby bootstrap by searching for it in parent directories
    $currentDir = __DIR__;
    $bootstrapPath = null;

    // Search upwards for any directory containing bootstrap.php (likely the kirby directory)
    for ($i = 0; $i < 6; $i++) {
        $currentDir = dirname($currentDir);

        // Look for bootstrap.php in subdirectories
        $dirs = glob($currentDir . '/*/bootstrap.php');
        if (!empty($dirs)) {
            $bootstrapPath = $dirs[0];
            break;
        }
    }

    if (!$bootstrapPath) {
        fwrite(STDERR, "Error: Could not find Kirby bootstrap.php\n");
        fwrite(STDERR, "Searched from: " . __DIR__ . "\n");
        exit(1);
    }

    // Find project root (parent of kirby directory)
    $projectRoot = dirname(dirname($bootstrapPath));
    echo "Detected project root: {$projectRoot}\n";
}

// Now load Kirby bootstrap and index.php from the project root
$bootstrapPath = null;
foreach (glob($projectRoot . '/*/bootstrap.php') as $path) {
    $bootstrapPath = $path;
    break;
}

// Fallback: check common locations
if (!$bootstrapPath && file_exists($projectRoot . '/kirby/bootstrap.php')) {
    $bootstrapPath = $projectRoot . '/kirby/bootstrap.php';
}
if (!$bootstrapPath && file_exists($projectRoot . '/app/kirby/bootstrap.php')) {
    $bootstrapPath = $projectRoot . '/app/kirby/bootstrap.php';
}

if (!$bootstrapPath) {
    fwrite(STDERR, "Error: Could not find Kirby bootstrap.php in project: {$projectRoot}\n");
    exit(1);
}

require $bootstrapPath;

// Find and parse index.php to extract roots configuration
$indexPath = null;
foreach (glob($projectRoot . '/*/index.php') as $path) {
    if (strpos(file_get_contents($path), 'new Kirby') !== false) {
        $indexPath = $path;
        break;
    }
}

if (!$indexPath && file_exists($projectRoot . '/index.php')) {
    if (strpos(file_get_contents($projectRoot . '/index.php'), 'new Kirby') !== false) {
        $indexPath = $projectRoot . '/index.php';
    }
}

if (!$indexPath) {
    fwrite(STDERR, "Error: Could not find Kirby index.php in project: {$projectRoot}\n");
    exit(1);
}

// Parse roots configuration from index.php
$indexContent = file_get_contents($indexPath);
$indexDir = dirname($indexPath);
$roots = [];

// Extract the roots array if it exists
if (preg_match("/new\s+Kirby\s*\(\s*\[\s*'roots'\s*=>\s*\[([^\]]+)\]/s", $indexContent, $matches)) {
    // Parse the roots array content
    $rootsContent = $matches[1];
    if (preg_match_all("/'(\w+)'\s*=>\s*([^,\n]+)/", $rootsContent, $rootMatches, PREG_SET_ORDER)) {
        foreach ($rootMatches as $match) {
            $key = $match[1];
            $value = trim($match[2]);
            // Replace __DIR__ with the actual index.php directory
            $value = str_replace('__DIR__', "'{$indexDir}'", $value);
            // Evaluate the expression
            $value = eval("return {$value};");
            $roots[$key] = $value;
        }
    }
}

// Suppress deprecation warnings from other plugins during Kirby instantiation
$oldErrorReporting = error_reporting();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Create Kirby instance without rendering
$kirby = new Kirby(empty($roots) ? [] : ['roots' => $roots]);

// Restore error reporting
error_reporting($oldErrorReporting);

// Plugin autoloader is now active
use chrfickinger\Simplify\Core\TranslationWorker;

// Set unlimited execution time for background jobs
set_time_limit(0);
ini_set('max_execution_time', 0);

// $jobId was already extracted at the top of the script

try {
    // Create worker instance
    echo "Creating worker instance...\n";
    $worker = new TranslationWorker();
    echo "Worker instance created\n";

    // Get queue instance
    $queue = new \chrfickinger\Simplify\Queue\TranslationQueue();

    // Try to acquire lock with retry
    $maxRetries = 3;
    $retryDelay = 5; // seconds
    $lockAcquired = false;

    for ($retry = 0; $retry < $maxRetries; $retry++) {
        if ($queue->acquireWorkerLock()) {
            $lockAcquired = true;
            break;
        }

        // Check if there are pending jobs before giving up
        $pendingJobs = $queue->getJobsByStatus('pending');
        if (empty($pendingJobs)) {
            echo "Another worker is running and no pending jobs, exiting\n";
            exit(0);
        }

        // Wait and retry - the other worker might finish soon
        echo "Another worker is running, waiting {$retryDelay}s (attempt " . ($retry + 1) . "/{$maxRetries})...\n";
        sleep($retryDelay);
    }

    if (!$lockAcquired) {
        echo "Could not acquire lock after {$maxRetries} attempts, exiting\n";
        exit(0);
    }

    echo "Worker lock acquired\n";

    try {
        // Reset any stuck jobs before starting
        echo "Checking for stuck jobs...\n";
        $resetCount = $queue->resetStuckJobs(5); // 5 minute timeout
        if ($resetCount > 0) {
            echo "Reset {$resetCount} stuck job(s)\n";
        } else {
            echo "No stuck jobs found\n";
        }

        if ($jobId) {
            // Process specific job
            echo "Processing job: {$jobId}\n";
            $success = $worker->processJob($jobId);

            if ($success) {
                echo "Job {$jobId} completed successfully\n";
            } else {
                echo "Job {$jobId} failed\n";
            }
        }

        // Process all pending jobs in FIFO order
        echo "Processing pending jobs in queue...\n";
        $processedCount = 0;

        while (true) {
            $success = $worker->processNextJob();

            if (!$success) {
                echo "No more pending jobs\n";
                break;
            }

            $processedCount++;
            echo "Processed job #{$processedCount}\n";
        }

        echo "Worker finished, processed {$processedCount} job(s)\n";

    } finally {
        // Always release lock
        $queue->releaseWorkerLock();
        echo "Worker lock released\n";
    }

    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, "Fatal error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
