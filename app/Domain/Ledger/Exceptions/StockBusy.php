<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;
use Throwable;

final class StockBusy extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Another station is recording stock for these parts. Try again.', 0, $previous);
    }
}
