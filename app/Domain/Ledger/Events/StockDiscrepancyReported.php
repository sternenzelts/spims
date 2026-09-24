<?php

namespace App\Domain\Ledger\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A supervisor override showed that the recorded balance did not match the shelf (PRD
 * FR-SPIMS-008 step 8, design §5.3). Dispatched after the commit that posted the withdrawal: a
 * caller that wraps the posting and rolls back announces nothing.
 *
 * Plan 5 turns this into an inventory_discrepancy alert. Until then nothing listens: the event is
 * the seam that keeps Plan 3 from depending on an alerts table that does not exist yet (design §10
 * gap, "Plans 3 → 5").
 */
final readonly class StockDiscrepancyReported implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $partId,
        public int $withdrawalOverrideId,
        public int $withdrawalTransactionId,
        /**
         * False when the locked balance already covered the line, so the override was discarded
         * and no adjustment was written. The discrepancy is reported either way: it was real, and
         * the part needs counting.
         */
        public bool $wasAdjusted,
    ) {}
}
