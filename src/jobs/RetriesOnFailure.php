<?php

namespace roadsterworks\craftshopifysync\jobs;

use Throwable;

/**
 * Retries idempotent jobs a few times to ride out throttling and timeouts.
 * Classes using this must implement {@see \yii\queue\RetryableJobInterface}.
 */
trait RetriesOnFailure
{
    public function getTtr(): int
    {
        return 300;
    }

    /**
     * @param int $attempt
     * @param Throwable|null $error
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }
}
