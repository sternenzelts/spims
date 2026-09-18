<?php

namespace App\Domain\Ledger\Events;

use App\Models\StockTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Stock has changed. Listeners (alert evaluation, from Plan 5) run only after the posting commits.
 */
final readonly class StockTransactionCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public StockTransaction $transaction) {}
}
