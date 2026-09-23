<?php

use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\MediaController;
use App\Livewire\Admin;
use App\Livewire\Imports;
use App\Livewire\MasterData\Equipment;
use App\Livewire\MasterData\StorageLocations;
use App\Livewire\Parts;
use App\Livewire\Personnel;
use App\Livewire\Station;
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

    Route::livewire('storage-locations', StorageLocations\Index::class)->name('storage-locations.index');
    Route::livewire('storage-locations/create', StorageLocations\Form::class)->name('storage-locations.create');
    Route::livewire('storage-locations/{location}/edit', StorageLocations\Form::class)->name('storage-locations.edit');

    Route::livewire('equipment', Equipment\Index::class)->name('equipment.index');
    Route::livewire('equipment/create', Equipment\Form::class)->name('equipment.create');
    Route::livewire('equipment/{equipment}/edit', Equipment\Form::class)->name('equipment.edit');

    Route::livewire('personnel', Personnel\Index::class)->name('personnel.index');
    Route::livewire('personnel/create', Personnel\Form::class)->name('personnel.create');
    Route::livewire('personnel/{personnel}/edit', Personnel\Form::class)->name('personnel.edit');

    Route::livewire('parts', Parts\Index::class)->name('parts.index');
    Route::livewire('parts/create', Parts\Form::class)->name('parts.create');
    Route::livewire('parts/{part}/edit', Parts\Form::class)->whereNumber('part')->name('parts.edit');
    Route::livewire('parts/{part}/stock-levels', Parts\StockLevelsForm::class)->whereNumber('part')->name('parts.stock-levels');
    Route::livewire('parts/{part}', Parts\Show::class)->whereNumber('part')->name('parts.show');

    Route::livewire('imports', Imports\Index::class)->name('imports.index');
    Route::livewire('imports/create', Imports\Upload::class)->name('imports.create');
    Route::get('imports/template', ImportTemplateController::class)->name('imports.template');
    Route::livewire('imports/{batch}', Imports\Review::class)->whereNumber('batch')->name('imports.show');

    Route::livewire('station/withdrawal', Station\Withdrawal::class)->name('station.withdrawal');
    Route::livewire('station/receipt/{transaction}', Station\Receipt::class)
        ->whereNumber('transaction')
        ->name('station.receipt');

    Route::get('media/{path}', MediaController::class)
        ->where('path', '(company|personnel)/[A-Za-z0-9]+\.[A-Za-z0-9]+')
        ->name('media.show');
});
