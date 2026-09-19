<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait ActsAsSignedInUser
{
    /**
     * The signed-in user, recorded as the actor of every change a screen makes.
     */
    protected function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
