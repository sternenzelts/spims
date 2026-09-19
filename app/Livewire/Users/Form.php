<?php

namespace App\Livewire\Users;

use App\Concerns\PasswordValidationRules;
use App\Domain\Access\Exceptions\AccountChangeRefused;
use App\Domain\Access\UserAccounts;
use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('User')]
class Form extends Component
{
    use ActsAsSignedInUser, PasswordValidationRules;

    /** The account being edited; null while creating one. */
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var list<string> */
    public array $roles = [];

    public bool $is_active = true;

    public function mount(?User $user = null): void
    {
        $this->authorize(Permission::UsersManage);

        // The create route has no {user}: Livewire then passes null or an unsaved model.
        if ($user?->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->roles = app(UserAccounts::class)->roleNames($user);
            $this->is_active = $user->is_active;
        }
    }

    public function save(UserAccounts $accounts): void
    {
        $this->authorize(Permission::UsersManage);

        $this->email = Str::lower(trim($this->email));

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->userId)],
            'password' => $this->userId === null
                ? $this->passwordRules()
                : ['nullable', 'string', Password::default(), 'confirmed'],
            'roles' => ['required', 'array'],
            'roles.*' => ['required', Rule::enum(Role::class)],
            'is_active' => ['boolean'],
        ]);

        $roles = array_map(fn (string $role): Role => Role::from($role), $this->roles);

        try {
            if ($this->userId === null) {
                $accounts->create($this->name, $this->email, $this->password, $roles, $this->actor());
            } else {
                $user = User::query()->findOrFail($this->userId);
                $accounts->update($user, $this->name, $this->email, $roles, $this->is_active, $this->actor());

                if ($this->password !== '') {
                    $accounts->resetPassword($user, $this->password, $this->actor());
                }
            }
        } catch (AccountChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('User saved.'));

        $this->redirectRoute('users.index', navigate: true);
    }
}
