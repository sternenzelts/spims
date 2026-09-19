<?php

namespace App\Listeners;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

/**
 * PRD §9.1: sign-ins, failed sign-ins and lockouts are audited against the account. A failed attempt
 * for an email with no account has nothing to attach to and is not recorded.
 */
final readonly class RecordSignInActivity
{
    public function __construct(private AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('signed_in', $event->user, actor: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // Fortify's authenticateUsing callback reports failures without the user, so find it by email.
        $user = $event->user instanceof User ? $event->user : $this->account($event->credentials['email'] ?? null);

        if ($user !== null) {
            $this->audit->record('sign_in_failed', $user);
        }
    }

    public function handleLockout(Lockout $event): void
    {
        $user = $this->account($event->request->input('email'));

        if ($user !== null) {
            $this->audit->record('locked_out', $user);
        }
    }

    private function account(mixed $email): ?User
    {
        return is_string($email) ? User::query()->where('email', Str::lower($email))->first() : null;
    }
}
