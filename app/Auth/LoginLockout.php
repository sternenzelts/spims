<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

/**
 * NFR-SPIMS-004: after five failed sign-ins the account is locked until 15 minutes after the first
 * failure, so a mistyped password cannot stop a shift for long. Fortify's own limiter locks for one
 * minute. Only failures are counted, and a successful sign-in clears them.
 */
final class LoginLockout extends LoginRateLimiter
{
    public const int LOCK_SECONDS = 15 * 60;

    public function increment(Request $request): void
    {
        $this->limiter->hit($this->throttleKey($request), self::LOCK_SECONDS);
    }
}
