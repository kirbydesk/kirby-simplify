<?php

namespace chrfickinger\Simplify\Logging;

/**
 * Base Logger Class
 *
 * Provides common file logging functionality for Logger and WorkerLogger.
 */
abstract class BaseLogger
{
    protected string $logPath;

    /**
     * Ensure log directory exists
     *
     * @param string $path Path to log file
     * @return void
     */
    protected function ensureLogDirectory(string $path): void
    {
        LogPathHelper::ensureDirectory($path);
    }

    /**
     * Get the simplify logs directory
     *
     * @return string Path to simplify logs directory
     */
    protected function getSimplifyLogsDir(): string
    {
        return LogPathHelper::getSimplifyLogsDir();
    }

    /**
     * Write a line to the log file
     *
     * @param string $line Line to write
     * @return void
     */
    protected function writeLogLine(string $line): void
    {
        file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get current timestamp
     *
     * @return string Formatted timestamp
     */
    protected function getTimestamp(): string
    {
        return date("Y-m-d H:i:s");
    }
}
