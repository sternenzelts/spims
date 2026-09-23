<?php

namespace App\Domain\Access\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * A supervisor's authorisation at the station was not accepted (PRD FR-SPIMS-008 step 2). The
 * message is read out loud at a counter, so it says what to do next and never which of the email,
 * the password or the account was wrong.
 */
final class AuthorisationRefused extends RuntimeException
{
    public static function credentials(): self
    {
        return new self(__('Those credentials do not match an account that can authorise this.'));
    }

    public static function inactive(User $user): self
    {
        return new self(__('The account for :email is no longer Active.', ['email' => $user->email]));
    }

    public static function notPermitted(User $user): self
    {
        return new self(__(':name is not allowed to authorise this. Ask an Inventory Supervisor.', [
            'name' => $user->name,
        ]));
    }

    public static function tooManyAttempts(int $seconds): self
    {
        return new self(__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]));
    }
}
