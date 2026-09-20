<?php

namespace App\Jobs;

use App\Domain\Imports\PartImports;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Writes a confirmed spare part import (PRD FR-SPIMS-005). Queued, because writing thousands of
 * audited parts can take minutes against a remote database; the review screen polls until it ends.
 */
class ConfirmImport implements ShouldQueue
{
    use Queueable;

    /** One attempt: an import is all or nothing, so a failed one goes back to a person, not a retry. */
    public int $tries = 1;

    /** Seconds. PartImports::lockOpen() treats a confirmation older than this as stuck. */
    public const int TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public int $batchId,
        public int $actorId,
    ) {}

    public function handle(PartImports $imports): void
    {
        $imports->confirm(
            ImportBatch::query()->findOrFail($this->batchId),
            User::query()->findOrFail($this->actorId),
        );
    }

    /**
     * Leave the batch a person can act on again when the job itself never reaches confirm()'s own
     * catch: a worker killed by an out-of-memory condition, a worker restart, the timeout above, or
     * a dispatch() that never reached the queue at all.
     */
    public function failed(?Throwable $exception): void
    {
        app(PartImports::class)->markFailed(
            ImportBatch::query()->findOrFail($this->batchId),
            $exception ?? new RuntimeException('The import did not finish. The queue worker stopped before it could report a reason.'),
        );
    }
}
