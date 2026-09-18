<?php

namespace App\Enums;

enum ReasonCode: string
{
    case Miscount = 'miscount';
    case Damage = 'damage';
    case UnrecordedIssue = 'unrecorded_issue';
    case UnrecordedReceipt = 'unrecorded_receipt';
    case FoundStock = 'found_stock';
    case Shrinkage = 'shrinkage';
    case Other = 'other';
}
