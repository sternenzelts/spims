<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
}
