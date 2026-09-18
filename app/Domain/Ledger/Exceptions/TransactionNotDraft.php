<?php

namespace App\Domain\Ledger\Exceptions;

use App\Models\StockTransaction;
use DomainException;

final class TransactionNotDraft extends DomainException
{
    public function __construct(public readonly StockTransaction $transaction)
    {
        parent::__construct("Transaction {$transaction->id} is {$transaction->status->value}; only a draft can be completed.");
    }
}
