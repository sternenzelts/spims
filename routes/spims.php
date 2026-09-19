<?php

use App\Http\Controllers\MediaController;
use App\Livewire\Admin;
use App\Livewire\Users;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPIMS screens
|--------------------------------------------------------------------------
|
| Every component authorizes its PRD §8 permission in mount() and again in
| each action that changes data, so these routes need no permission
| middleware of their own.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('users', Users\Index::class)->name('users.index');
    Route::livewire('users/create', Users\Form::class)->name('users.create');
    Route::livewire('users/{user}/edit', Users\Form::class)->name('users.edit');

    Route::livewire('install-settings', Admin\InstallSettingsForm::class)->name('install-settings.edit');

    Route::livewire('withdrawal-purposes', Admin\WithdrawalPurposes::class)->name('withdrawal-purposes.index');

    Route::get('media/{path}', MediaController::class)
        ->where('path', '(company|personnel)/[A-Za-z0-9]+\.[A-Za-z0-9]+')
        ->name('media.show');
});
