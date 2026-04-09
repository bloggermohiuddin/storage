<?php

declare(strict_types=1);

namespace StorageSDK\Support;

/**
 * Logger — simple PSR-3-inspired file logger.
 *
 * Writes to a single rotating log file.
 * No dependencies — works on any shared hosting.
 */
class Logger
{
    private string $logFile;
    private bool   $enabled;

    public function __construct(string $logFile, bool $enabled = true)
    {
        $this->logFile = $logFile;
        $this->enabled = $enabled;
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    // -------------------------------------------------------------------------

    private function write(string $level, string $message, array $context): void
    {
        if (! $this->enabled) {
            return;
        }

        $dir = dirname($this->logFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $ctx       = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        $line      = "[{$timestamp}] [{$level}] {$message}{$ctx}" . PHP_EOL;

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
