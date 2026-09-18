<?php

namespace App\Domain\Identity\Exceptions;

use App\Models\Personnel;
use DomainException;

final class BadgeAlreadyEnrolled extends DomainException
{
    public function __construct(public readonly Personnel $holder)
    {
        parent::__construct(
            "Badge {$holder->badge_uid_masked} is already enrolled to {$holder->full_name} ({$holder->employee_no}).",
        );
    }
}
