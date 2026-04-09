<?php

declare(strict_types=1);

namespace StorageSDK\Support;

use StorageSDK\Exceptions\InvalidFileException;

/**
 * FileValidator — validates file size, MIME type, and extension
 * before any file is stored. Prevents dangerous uploads on shared hosting.
 */
class FileValidator
{
    /**
     * MIME types that are ALWAYS blocked regardless of config.
     * These could execute code on the server.
     */
    private const BLOCKED_MIMES = [
        'application/x-php',
        'application/php',
        'text/php',
        'text/x-php',
        'application/x-httpd-php',
        'application/x-httpd-php-source',
        'application/x-sh',
        'application/x-csh',
        'text/x-shellscript',
        'application/x-perl',
        'application/x-python',
        'application/javascript',
        'text/javascript',
    ];

    /**
     * File extensions that are ALWAYS blocked.
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'sh', 'bash', 'csh', 'ksh',
        'pl', 'py', 'rb', 'js', 'ts',
        'exe', 'bat', 'cmd', 'com',
        'asp', 'aspx', 'jsp', 'cfm',
        'htaccess', 'htpasswd',
    ];

    /** @var int Max file size in bytes (default: 20 MB) */
    private int $maxSize;

    /** @var array|null Whitelist of allowed MIME types (null = allow all non-blocked) */
    private ?array $allowedMimes;

    public function __construct(int $maxSize = 20971520, ?array $allowedMimes = null)
    {
        $this->maxSize     = $maxSize;
        $this->allowedMimes = $allowedMimes;
    }

    /**
     * Run all validation checks on a source file path.
     *
     * @param  string $sourcePath  Absolute path to the temp file
     * @param  string $originalName  Original filename for extension check
     * @throws InvalidFileException
     */
    public function validate(string $sourcePath, string $originalName = ''): void
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidFileException("Source path is not a readable file: {$sourcePath}");
        }

        $this->checkSize($sourcePath);
        $this->checkExtension($originalName ?: basename($sourcePath));
        $this->checkMime($sourcePath);
    }

    // -------------------------------------------------------------------------
    // Private checks
    // -------------------------------------------------------------------------

    private function checkSize(string $path): void
    {
        $bytes = filesize($path);
        if ($bytes === false || $bytes > $this->maxSize) {
            $maxMb = round($this->maxSize / 1048576, 1);
            throw new InvalidFileException(
                "File size ({$bytes} bytes) exceeds maximum allowed ({$maxMb} MB)"
            );
        }
    }

    private function checkExtension(string $filename): void
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            throw new InvalidFileException(
                "File extension [{$ext}] is not permitted for security reasons"
            );
        }
    }

    private function checkMime(string $path): void
    {
        $mime = $this->detectMime($path);

        foreach (self::BLOCKED_MIMES as $blocked) {
            if (str_starts_with($mime, $blocked)) {
                throw new InvalidFileException(
                    "MIME type [{$mime}] is blocked for security reasons"
                );
            }
        }

        if ($this->allowedMimes !== null && ! in_array($mime, $this->allowedMimes, true)) {
            $allowed = implode(', ', $this->allowedMimes);
            throw new InvalidFileException(
                "MIME type [{$mime}] is not in the allowed list: [{$allowed}]"
            );
        }
    }

    private function detectMime(string $path): string
    {
        // finfo is the most reliable approach
        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime !== false) {
                return $mime;
            }
        }

        // Fallback: mime_content_type
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if ($mime !== false) {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }
}
