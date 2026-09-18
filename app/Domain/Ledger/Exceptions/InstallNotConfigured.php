<?php

namespace App\Domain\Ledger\Exceptions;

use DomainException;

final class InstallNotConfigured extends DomainException
{
    public function __construct()
    {
        parent::__construct('Set the company name in Install Settings before recording stock transactions.');
    }
}
