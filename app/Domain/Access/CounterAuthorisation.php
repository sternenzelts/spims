<?php

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A supervisor proves they are standing at the station (PRD FR-SPIMS-008 step 2) without the
 * station ever leaving the custodian's session (design §3.5: the custodian is the attributed
 * actor). Nothing here calls Auth::login(), touches the session, or issues a remember-me token —
 * the result is a User to attribute an authorisation to, not a sign-in.
 */
final readonly class CounterAuthorisation
{
    /** Matches the sign-in lockout of NFR-SPIMS-004: five attempts, then fifteen minutes. */
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 900;

    /**
     * The user these credentials identify, if they may exercise the permission asked for.
     *
     * @param  string  $fromAddress  the station's address, so one station's failures do not lock
     *                               a supervisor out of every other station
     *
     * @throws AuthorisationRefused
     */
    public function verify(string $email, string $password, Permission $permission, string $fromAddress): User
    {
        $email = Str::lower(trim($email));
        // Per email and address, whatever is being authorised: Decision 2's five attempts are one
        // budget, not one for each permission.
        $key = "counter-authorisation:{$email}|{$fromAddress}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw AuthorisationRefused::tooManyAttempts(RateLimiter::availableIn($key));
        }

        // Matched ignoring case: Plan 2 leaves the profile screen storing email as typed.
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        // Active is checked before the password, as the sign-in does (FortifyServiceProvider), so a
        // deactivated account gets the answer a wrong password gets and its password is never
        // confirmed. It costs an attempt all the same.
        if (! $user instanceof User || ! $user->is_active || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthorisationRefused::credentials();
        }

        // A correct password still costs an attempt when the account may not authorise this, so
        // the lockout never tells anyone which thing they got wrong.
        if (! $user->can($permission)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthorisationRefused::notPermitted($user);
        }

        RateLimiter::clear($key);

        return $user;
    }
}
