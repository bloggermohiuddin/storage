<?php

declare(strict_types=1);

namespace StorageSDK\Exceptions;

/**
 * Thrown when a requested file key does not exist in storage.
 */
class FileNotFoundException extends StorageException
{
    public function __construct(string $key)
    {
        parent::__construct("File not found: [{$key}]", 404);
    }
}
