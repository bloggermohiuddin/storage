<?php

declare(strict_types=1);

namespace StorageSDK\Support;

/**
 * KeyGenerator — builds safe, unique, S3-compatible object keys.
 *
 * Keys follow the pattern:
 *   {prefix}/{year}/{month}/{uuid}.{ext}
 *
 * Example: patients/2026/04/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg
 *
 * Keys are safe for both local filesystem and S3-compatible storage.
 */
class KeyGenerator
{
    /**
     * Generate a fully-qualified unique key for a file.
     *
     * @param  string $originalFilename  Original filename (used for extension only)
     * @param  string $prefix            Folder prefix, e.g. "patients" or "invoices/2026"
     * @param  bool   $dateFolders       Whether to add /year/month/ sub-folders
     * @return string                    e.g. "patients/2026/04/uuid.jpg"
     */
    public static function generate(
        string $originalFilename,
        string $prefix = 'uploads',
        bool $dateFolders = true
    ): string {
        $ext    = self::safeExtension($originalFilename);
        $uuid   = self::uuid4();
        $folder = self::buildFolder($prefix, $dateFolders);

        return $folder . '/' . $uuid . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * Sanitize a user-supplied prefix / folder path.
     * Strips leading/trailing slashes and any path traversal sequences.
     */
    public static function sanitizePrefix(string $prefix): string
    {
        // Remove path traversal
        $prefix = str_replace(['..', '\\'], '', $prefix);
        // Replace any non-alphanumeric except /._- with nothing
        $prefix = preg_replace('/[^a-zA-Z0-9\/._\-]/', '', $prefix);
        return trim($prefix, '/');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function buildFolder(string $prefix, bool $dateFolders): string
    {
        $prefix = self::sanitizePrefix($prefix);
        if ($dateFolders) {
            return $prefix . '/' . date('Y') . '/' . date('m');
        }
        return $prefix;
    }

    private static function safeExtension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        // Allow only alphanumeric extensions up to 8 chars
        if (preg_match('/^[a-z0-9]{1,8}$/', $ext)) {
            return $ext;
        }
        return '';
    }

    /**
     * Generate a version-4 UUID (random).
     * Uses random_bytes() which is CSPRNG-safe on PHP 7+.
     */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);

        // Set version bits (4) and variant bits (RFC 4122)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
