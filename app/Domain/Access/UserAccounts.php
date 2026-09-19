<?php

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\AccountChangeRefused;
use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SPIMS user accounts, managed by System Administrators (PRD §8, Manage Users and Roles). Accounts
 * are deactivated, never deleted, because stock transactions keep their custodian for ever.
 */
final readonly class UserAccounts
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<Role>  $roles
     */
    public function create(string $name, string $email, string $password, array $roles, User $actor): User
    {
        return DB::transaction(function () use ($name, $email, $password, $roles, $actor): User {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            // An administrator typed the address, so it is trusted.
            $user->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
            $user->syncRoles($roles);

            $this->audit->record('created', $user, null, $this->snapshot($user), $actor);

            return $user;
        });
    }

    /**
     * @param  list<Role>  $roles
     *
     * @throws AccountChangeRefused
     */
    public function update(User $user, string $name, string $email, array $roles, bool $isActive, User $actor): void
    {
        if ($user->is($actor) && ! in_array(Role::Admin, $roles, true)) {
            throw AccountChangeRefused::ownAdministratorRole();
        }

        if ($user->is($actor) && ! $isActive) {
            throw AccountChangeRefused::ownDeactivation();
        }

        DB::transaction(function () use ($user, $name, $email, $roles, $isActive, $actor): void {
            $old = $this->snapshot($user);

            $user->forceFill(['name' => $name, 'email' => $email, 'is_active' => $isActive])->save();
            $user->syncRoles($roles);

            $new = $this->snapshot($user);

            $this->audit->recordChanges($user, $old, $new, $actor);
        });
    }

    public function resetPassword(User $user, string $password, User $actor): void
    {
        DB::transaction(function () use ($user, $password, $actor): void {
            $user->forceFill(['password' => $password])->save();

            // The event only: never the password, hashed or not.
            $this->audit->record('password_reset', $user, null, null, $actor);
        });
    }

    /**
     * The user's role names, read from the database so a just-synced change is included.
     *
     * @return list<string>
     */
    public function roleNames(User $user): array
    {
        return array_values(array_map(
            fn (mixed $name): string => (string) $name,
            $user->roles()->orderBy('name')->pluck('name')->all(),
        ));
    }

    /**
     * @return array{name: string, email: string, is_active: bool, roles: list<string>}
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $this->roleNames($user),
        ];
    }
}
