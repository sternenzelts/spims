<?php

namespace App\Domain\Identity\Exceptions;

use DomainException;

final class InvalidBadgeInput extends DomainException
{
    public static function empty(): self
    {
        return new self('No badge was read. Scan the badge again.');
    }

    public static function malformed(): self
    {
        return new self('The badge could not be read. Scan the badge again.');
    }
}
