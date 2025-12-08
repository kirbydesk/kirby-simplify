<?php

namespace chrfickinger\Simplify\Logging;

/**
 * Simple logger for EasyRead operations
 */
class Logger extends BaseLogger
{
    private string $level;

    const LEVELS = ["debug" => 0, "info" => 1, "warning" => 2, "error" => 3];

    public function __construct(string $logPath, string $level = "info")
    {
        $this->logPath = $logPath;
        $this->level = strtolower($level);
        $this->ensureLogDirectory($logPath);
    }

    public function debug(string $message): void
    {
        $this->log("debug", $message);
    }

    public function info(string $message): void
    {
        $this->log("info", $message);
    }

    public function warning(string $message): void
    {
        $this->log("warning", $message);
    }

    public function error(string $message): void
    {
        $this->log("error", $message);
    }

    private function log(string $level, string $message): void
    {
        // Check if this level should be logged
        if (self::LEVELS[$level] < self::LEVELS[$this->level]) {
            return;
        }

        $timestamp = $this->getTimestamp();
        $line = sprintf(
            "[%s] %s: %s\n",
            $timestamp,
            strtoupper($level),
            $message,
        );

        $this->writeLogLine($line);
    }
}
