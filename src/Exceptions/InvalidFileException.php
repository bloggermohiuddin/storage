<?php

declare(strict_types=1);

namespace StorageSDK\Exceptions;

/**
 * Thrown when an uploaded file fails validation (MIME, size, extension checks).
 */
class InvalidFileException extends StorageException
{
    public function __construct(string $reason)
    {
        parent::__construct("Invalid file: {$reason}", 422);
    }
}
