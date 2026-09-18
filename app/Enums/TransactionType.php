<?php

namespace App\Enums;

enum TransactionType: string
{
    case Withdrawal = 'withdrawal';
    case Replenishment = 'replenishment';
    case Adjustment = 'adjustment';
    case Opening = 'opening';

    /**
     * The stock direction every line of this type must carry: -1 removes stock, +1 adds it.
     * Null for adjustments, where each line states its own direction.
     */
    public function direction(): ?int
    {
        return match ($this) {
            self::Withdrawal => -1,
            self::Replenishment, self::Opening => 1,
            self::Adjustment => null,
        };
    }

    public function movementType(): MovementType
    {
        return match ($this) {
            self::Withdrawal => MovementType::Withdrawal,
            self::Replenishment => MovementType::Replenishment,
            self::Adjustment => MovementType::Adjustment,
            self::Opening => MovementType::Opening,
        };
    }
}
