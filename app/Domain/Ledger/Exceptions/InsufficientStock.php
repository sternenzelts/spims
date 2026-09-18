<?php

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Ledger\Shortage;
use DomainException;

final class InsufficientStock extends DomainException
{
    /**
     * @param  list<Shortage>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct(implode(' ', array_map(
            fn (Shortage $shortage): string => "Insufficient stock for {$shortage->partNumber}: {$shortage->available} available, {$shortage->requested} requested.",
            $shortages,
        )));
    }
}
