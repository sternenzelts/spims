<?php

namespace App\Domain\Identity\Exceptions;

use App\Models\Personnel;
use DomainException;

final class DuplicateEmployeeNumber extends DomainException
{
    public function __construct(public readonly Personnel $holder)
    {
        parent::__construct("Employee number {$holder->employee_no} is already registered to {$holder->full_name}.");
    }
}
