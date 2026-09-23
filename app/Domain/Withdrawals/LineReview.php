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
        public ?LevelWarning $level,
    ) {}

    /**
     * Short, and no supervisor has authorised it yet: the custodian must reduce the quantity,
     * remove the line, or request an override (FR-SPIMS-006 alternate flow).
     */
    public function needsOverride(): bool
    {
        return $this->isShort && ! $this->override instanceof WithdrawalOverride;
    }
}
