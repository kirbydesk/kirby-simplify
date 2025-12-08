<?php

namespace kirbydesk\Simplify\Queue;

use Kirby\Cms\App as Kirby;

/**
 * Worker Manager Class
 *
 * Manages background worker processes for translation jobs.
 */
class WorkerManager
{
    /**
     * Check if PHP binary is configured in config.php
     *
     * @return bool
     */
    public static function isPhpBinaryConfigured(): bool
    {
        $kirby = Kirby::instance();
        $phpConfig = $kirby->option('kirbydesk.simplify.php', []);
        return isset($phpConfig['binary']) && !empty($phpConfig['binary']);
    }

    /**
     * Detect the PHP CLI binary
     *
     * @return string PHP binary path
     */
    public static function detectPhpBinary(): string
    {
        $kirby = Kirby::instance();
        $phpConfig = $kirby->option('kirbydesk.simplify.php', []);
        $phpBinary = $phpConfig['binary'] ?? null;

        // Auto-detect if not configured
        if (!$phpBinary) {
            $phpBinary = PHP_BINARY;

            // If PHP_BINARY is FPM (php-fpm, php82.fpm, etc), use 'php' from PATH instead
            if (strpos($phpBinary, 'fpm') !== false) {
                return 'php';
            }
        }

        return $phpBinary;
    }

    /**
     * Start a background worker for a job
     *
     * @param array $job Job data
     * @param string $workerPath Path to worker script
     * @param object|null $logger Optional logger
     * @return array Result with method and success status
     */
    public static function startWorker(array $job, string $workerPath, ?object $logger = null): array
    {
        $kirby = Kirby::instance();
        $phpBinary = self::detectPhpBinary();
        $variantCode = $job['variantCode'] ?? 'default';

        // Validate PHP binary exists
        if (!self::validatePhpBinary($phpBinary)) {
            $error = "PHP binary not found: {$phpBinary}. Please check your config.php: 'kirbydesk.simplify.php.binary'";
            if ($logger) {
                $logger->error($error);
            }

            // Mark job as failed
            $queue = new TranslationQueue();
            $queue->setJobStatus($job['id'], 'failed', $error);

            return [
                'success' => false,
                'error' => $error,
                'message' => $error,
            ];
        }

        // Ensure variant worker log directory exists
        $variantLogDir = $kirby->root('logs') . '/simplify/workers/' . $variantCode;
        if (!is_dir($variantLogDir)) {
            mkdir($variantLogDir, 0755, true);
        }

        $logFile = $variantLogDir . '/worker-job_' . $job['id'] . '.log';

        // Pass project root to worker so it loads the correct Kirby instance
        // This is important when the plugin is symlinked from another project
        // Get true project root by going up from site/ directory (site is never a symlink)
        $projectRoot = dirname($kirby->root('site'));

        // Use different command based on OS
        // macOS: no nohup (causes "can't detach from console" error)
        // Linux: use nohup for better process isolation
        $isMacOS = PHP_OS === 'Darwin';

        if ($isMacOS) {
            // macOS: Redirect stdin from /dev/null to prevent "can't detach from console" error
            $command = "{$phpBinary} {$workerPath} {$projectRoot} {$job['id']} > {$logFile} 2>&1 < /dev/null & echo $!";
        } else {
            // Linux/Unix: Use nohup for better compatibility
            $command = "nohup {$phpBinary} {$workerPath} {$projectRoot} {$job['id']} > {$logFile} 2>&1 & echo $!";
        }

        if ($logger) {
            $logger->info("Starting worker: {$command}");
        }

        $pid = @exec($command);

        // Check if exec() is disabled or failed
        if ($pid === false || @exec('echo test') === '') {
            return self::fallbackWorkerExecution($job, $logger);
        }

        if ($logger && $pid) {
            $logger->info("Worker started with PID: {$pid}");
        }

        return [
            'success' => true,
            'method' => 'async',
            'jobId' => $job['id'],
            'message' => 'Translation started in background',
        ];
    }

    /**
     * Validate that PHP binary exists and is executable
     *
     * @param string $phpBinary PHP binary path
     * @return bool
     */
    private static function validatePhpBinary(string $phpBinary): bool
    {
        // If it's just 'php' (no path), assume it's in PATH
        if ($phpBinary === 'php') {
            // Try to execute php --version to check if it's available
            $output = @shell_exec('php --version 2>&1');
            return $output !== null && stripos($output, 'PHP') !== false;
        }

        // For absolute paths, check if file exists and is executable
        return file_exists($phpBinary) && is_executable($phpBinary);
    }

    /**
     * Fallback worker execution when exec() is disabled
     *
     * @param array $job Job data
     * @param object|null $logger Optional logger
     * @return array Response (will terminate script)
     */
    private static function fallbackWorkerExecution(array $job, ?object $logger = null): array
    {
        if ($logger) {
            $logger->info("exec() disabled, using fallback method");
        }

        // Set unlimited execution time BEFORE sending response
        set_time_limit(0);
        ignore_user_abort(true);

        // Send response to user immediately
        ob_start();
        $response = json_encode([
            'success' => true,
            'jobId' => $job['id'],
            'method' => 'fallback',
            'message' => 'Translation started in background'
        ]);
        echo $response;
        header('Content-Length: ' . ob_get_length());
        header('Connection: close');
        ob_end_flush();
        flush();

        // Close connection but keep script running
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Now process in "background"
        $worker = new TranslationWorker();
        $worker->processJob($job['id']);

        exit; // Important: stop script after background processing
    }

    /**
     * Check if exec() is available
     *
     * @return bool
     */
    public static function isExecAvailable(): bool
    {
        return @exec('echo test') !== '';
    }
}
