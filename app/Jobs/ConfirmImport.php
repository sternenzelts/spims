<?php

namespace App\Jobs;

use App\Domain\Imports\PartImports;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a confirmed spare part import (PRD FR-SPIMS-005). Queued, because writing thousands of
 * audited parts can take minutes against a remote database; the review screen polls until it ends.
 */
class ConfirmImport implements ShouldQueue
{
    use Queueable;

    /** One attempt: an import is all or nothing, so a failed one goes back to a person, not a retry. */
    public int $tries = 1;

    /** Seconds. */
    public int $timeout = 1800;

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
}
