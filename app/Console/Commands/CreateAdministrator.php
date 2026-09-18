<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class CreateAdministrator extends Command
{
    protected $signature = 'spims:create-admin
        {email : Sign-in email address}
        {name : Full name}';

    protected $description = 'Create a System Administrator account (provisioning checklist step 4)';

    public function handle(AuditLogger $audit): int
    {
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'name' => $this->argument('name')],
            ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value): ?string => Validator::make(
                ['password' => $value],
                ['password' => [Password::default()]],
            )->errors()->first('password') ?: null,
        );

        $user = DB::transaction(function () use ($validator, $password, $audit): User {
            /** @var array{email: string, name: string} $input */
            $input = $validator->validated();

            $user = User::query()->create([...$input, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Role::Admin);

            $audit->record('created', $user, null, ['email' => $user->email, 'roles' => [Role::Admin->value]]);

            return $user;
        });

        $this->components->info("System Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
