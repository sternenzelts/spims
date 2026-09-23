<?php

namespace App\Domain\Withdrawals;

/**
 * A whole draft withdrawal as the custodian sees it at review.
 */
final readonly class WithdrawalReview
{
    /**
     * @param  list<LineReview>  $lines
     */
    public function __construct(public array $lines) {}

    public function isShort(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->isShort) {
                return true;
            }
        }

        return false;
    }

    /**
     * At least one line is short and unauthorised, so the withdrawal cannot be confirmed as it is.
     */
    public function needsOverride(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->needsOverride()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The part numbers of every short line, for a message that names them.
     *
     * @return list<string>
     */
    public function shortPartNumbers(): array
    {
        $numbers = [];

        foreach ($this->lines as $line) {
            if ($line->isShort) {
                $numbers[] = $line->line->part->part_number;
            }
        }

        return $numbers;
    }
}
