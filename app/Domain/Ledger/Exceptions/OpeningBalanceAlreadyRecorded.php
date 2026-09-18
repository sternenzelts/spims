<?php

namespace App\Domain\Ledger\Exceptions;

use DomainException;

final class OpeningBalanceAlreadyRecorded extends DomainException
{
    /**
     * @param  list<string>  $partNumbers
     */
    public function __construct(public readonly array $partNumbers)
    {
        parent::__construct('An opening balance can only be recorded before any other stock movement. Already has movements: '.implode(', ', $partNumbers).'.');
    }
}
