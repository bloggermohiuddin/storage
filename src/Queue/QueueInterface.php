<?php

declare(strict_types=1);

namespace StoragePlatform\Queue;

/**
 * QueueInterface defines the standard contract for background task processing.
 */
interface QueueInterface
{
    /**
     * Push a new job onto the queue.
     *
     * @param  string $jobClass  The fully qualified class name of the job
     * @param  array  $payload   The parameters to pass to the job
     * @param  string $queue     Queue channel name
     * @return bool
     */
    public function push(string $jobClass, array $payload = [], string $queue = 'default'): bool;

    /**
     * Pop the next job off the queue.
     *
     * @param  string $queue
     * @return array|null  Returns associative array containing: id (int), job (string), payload (array), attempts (int)
     */
    public function pop(string $queue = 'default'): ?array;

    /**
     * Delete a job from the queue (upon successful completion).
     *
     * @param  int $jobId
     * @return bool
     */
    public function delete(int $jobId): bool;

    /**
     * Release a failed or reserved job back to the queue.
     *
     * @param  int $jobId
     * @param  int $delay  Delay in seconds before the job becomes available again
     * @return bool
     */
    public function release(int $jobId, int $delay = 0): bool;

    /**
     * Clear all jobs from the queue channel.
     *
     * @param  string $queue
     * @return bool
     */
    public function clear(string $queue = 'default'): bool;
}
