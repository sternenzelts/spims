<?php

namespace App\Domain\Ledger\Exceptions;

use App\Models\StockTransaction;
use DomainException;

final class TransactionHasNoLines extends DomainException
{
    public function __construct(public readonly StockTransaction $transaction)
    {
        parent::__construct("Transaction {$transaction->id} has no lines to post.");
    }
}
