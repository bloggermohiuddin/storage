<?php

declare(strict_types=1);

namespace StorageSDK\Exceptions;

/**
 * Base exception for all Storage SDK errors.
 */
class StorageException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct('[StorageSDK] ' . $message, $code, $previous);
    }
}
