<?php

namespace App\Domain\Withdrawals;

use App\Enums\LevelWarning;
use App\Models\StockTransactionLine;
use App\Models\WithdrawalOverride;

/**
 * One line of a draft withdrawal as the custodian sees it at review (PRD FR-SPIMS-006 step 4).
 * Advisory only: the balance is read again under lock at commit, and that read decides.
 */
final readonly class LineReview
{
    public function __construct(
        public StockTransactionLine $line,
        /** The recorded balance now, to three decimals. */
        public string $onHand,
        /** What the balance becomes, counting an authorised override. */
        public string $resulting,
        /** The quantity exceeds the recorded balance. */
        public bool $isShort,
        public ?WithdrawalOverride $override,
        /**
         * The override exists but stock of its part was recorded moving after it was counted
         * (design §5.6.1): confirming would refuse it with StockMovedSinceCount, so it no longer
         * covers this line.
         */
        public bool $staleOverride,
        public ?LevelWarning $level,
    ) {}

    /**
     * Short, and no supervisor has authorised it yet, or the authorisation has gone stale: the
     * custodian must reduce the quantity, remove the line, or request a (new) override
     * (FR-SPIMS-006 alternate flow).
     */
    public function needsOverride(): bool
    {
        return $this->isShort && (! $this->override instanceof WithdrawalOverride || $this->staleOverride);
    }
}
