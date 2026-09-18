<?php

namespace App\Enums;

enum MovementType: string
{
    case Withdrawal = 'withdrawal';
    case Replenishment = 'replenishment';
    case Adjustment = 'adjustment';
    case Opening = 'opening';
    case CountVariance = 'count_variance';
}
