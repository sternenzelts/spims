<?php

namespace App\Domain\Access\Exceptions;

use DomainException;

/**
 * A change administrators may not make to their own account, because it could leave the install
 * without an active System Administrator. $field names the input the refusal is about.
 */
final class AccountChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function ownAdministratorRole(): self
    {
        return new self('You cannot remove your own System Administrator role.', 'roles');
    }

    public static function ownDeactivation(): self
    {
        return new self('You cannot deactivate your own account.', 'is_active');
    }
}
