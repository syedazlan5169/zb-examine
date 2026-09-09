<?php

namespace App\Data;

/**
 * Result of a photo-upload cleanup operation.
 *
 * Contains counts and failures from both staging and queue processing phases.
 * Used by console command for output and by tests for verification.
 */
final class PhotoUploadCleanupResult
{
    /**
     * @param  array<int, string>  $failures  Session/queue errors (never paths/tokens)
     */
    public function __construct(
        public readonly int $sessionsScanned,
        public readonly int $sessionsStaged,
        public readonly int $photoRowsStaged,
        public readonly int $queueRowsDue,
        public readonly int $objectsCleared,
        public readonly int $clearingFailures,
        public readonly int $integrityConflicts,
        public readonly array $failures = [],
    ) {}

    public function hasFailures(): bool
    {
        return $this->clearingFailures > 0 || $this->integrityConflicts > 0;
    }

    public function totalProcessed(): int
    {
        return $this->sessionsStaged + $this->objectsCleared;
    }
}
