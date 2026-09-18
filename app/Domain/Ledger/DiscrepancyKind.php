<?php

namespace App\Domain\Ledger;

enum DiscrepancyKind: string
{
    /** part_stock.on_hand differs from the sum of the part's movements. */
    case StockCache = 'stock_cache';

    /** A movement's balance_after differs from the running sum of movements in id order. */
    case RunningBalance = 'running_balance';
}
