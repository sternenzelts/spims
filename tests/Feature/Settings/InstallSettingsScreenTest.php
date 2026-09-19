<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Livewire\Admin\InstallSettingsForm;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('lets only System Administrators configure the install', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('install-settings.edit'))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Install settings', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('---A'));

it('shows the defaults for settings not yet changed, and the install type', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->assertSet('settings.regional.timezone', 'Asia/Manila')
        ->assertSet('settings.numbering.prefixes.withdrawal', 'WD')
        ->assertSet('settings.backdating.limit_days', 7)
        ->assertSee('Production install');
});

it('saves the settings and audits each one that changed', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.company.name', 'Microtech Industrial Solutions, Inc.')
        ->set('settings.company.address', 'Calamba, Laguna')
        ->set('settings.numbering.prefixes.withdrawal', 'iss')
        ->set('settings.backdating.limit_days', '3')
        ->set('recipients', "stores@microtech.test\nplant.manager@microtech.test")
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(InstallSettings::class);

    expect($settings->companyName())->toBe('Microtech Industrial Solutions, Inc.')
        ->and($settings->transactionPrefix(TransactionType::Withdrawal))->toBe('ISS')
        ->and($settings->get('backdating.limit_days'))->toBe(3)
        ->and($settings->get('notifications.digest_recipients'))->toBe(['stores@microtech.test', 'plant.manager@microtech.test'])
        ->and(AuditLog::query()->where('auditable_type', 'setting')->orderBy('auditable_id')->pluck('auditable_id')->all())
        ->toBe(['backdating.limit_days', 'company.address', 'company.name', 'notifications.digest_recipients', 'numbering.prefixes']);
});

it('refuses a prefix that is not letters only, or that two types share', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.numbering.prefixes.withdrawal', 'W-D')
        ->set('settings.numbering.prefixes.replenishment', 'aj')
        ->call('save')
        ->assertHasErrors([
            'settings.numbering.prefixes.withdrawal',
            'settings.numbering.prefixes.replenishment',
            'settings.numbering.prefixes.adjustment',
        ]);

    expect(app(InstallSettings::class)->transactionPrefix(TransactionType::Withdrawal))->toBe('WD');
});

it('numbers new transactions with a changed prefix and leaves existing numbers alone', function () {
    configureInstall();
    partWithStock('5');
    $before = StockTransaction::query()->sole()->transaction_no;

    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.numbering.prefixes.opening', 'OPN')
        ->call('save')
        ->assertHasNoErrors();

    partWithStock('3');

    expect($before)->toStartWith('OP-')
        ->and(StockTransaction::query()->orderBy('id')->pluck('transaction_no')->all())
        ->toBe([$before, 'OPN-'.now()->setTimezone('Asia/Manila')->year.'-000001']);
});

it('asks for confirmation before regrouping figures in a new time zone', function () {
    configureInstall();
    partWithStock('5');

    $screen = Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.regional.timezone', 'Asia/Singapore')
        ->assertSee('regrouped in the new time zone')
        ->call('save')
        ->assertHasErrors('confirmRegrouping');

    expect(app(InstallSettings::class)->timezone())->toBe('Asia/Manila');

    $screen->set('confirmRegrouping', true)->call('save')->assertHasNoErrors();

    expect(app(InstallSettings::class)->timezone())->toBe('Asia/Singapore');
});

it('changes the time zone without asking before any stock is recorded', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.regional.timezone', 'Asia/Singapore')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(InstallSettings::class)->timezone())->toBe('Asia/Singapore');
});

it('asks for confirmation before changing the currency of recorded unit costs', function () {
    configureInstall();
    $receipt = StockTransaction::factory()->replenishment()->withLines([[Part::factory()->create(), '2']])->create();
    $receipt->lines()->update(['unit_cost' => '125.5000']);
    app(StockLedger::class)->complete($receipt, $receipt->custodian);

    $screen = Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.regional.currency', 'USD')
        ->call('save')
        ->assertHasErrors('confirmCurrency');

    $screen->set('confirmCurrency', true)->call('save')->assertHasNoErrors();

    expect(app(InstallSettings::class)->get('regional.currency'))->toBe('USD');
});

it('stores an uploaded logo and shows it beside the company name', function () {
    Storage::fake();
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(InstallSettingsForm::class)
        ->set('logo', UploadedFile::fake()->create('logo.png', 20, 'image/png'))
        ->call('save')
        ->assertHasNoErrors();

    $path = app(InstallSettings::class)->get('company.logo_path');

    expect($path)->toStartWith('company/');
    Storage::assertExists($path);

    $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('media.show', ['path' => $path]), false);
});

it('serves uploaded files to signed-in users only, and only from the upload folders', function () {
    Storage::fake();
    Storage::put('company/logo123.png', 'png-bytes');

    $this->get(route('media.show', ['path' => 'company/logo123.png']))->assertRedirect(route('login'));

    $this->actingAs(userWithRoles(Role::Custodian));
    $this->get(route('media.show', ['path' => 'company/logo123.png']))->assertOk();
    $this->get('/media/company/../.env')->assertNotFound();
});

it('prompts System Administrators to finish setup until the company name is set', function () {
    $admin = userWithRoles(Role::Admin);

    $this->actingAs($admin)->get(route('dashboard'))->assertSee('Finish setting up this install');
    $this->actingAs(userWithRoles(Role::Supervisor))->get(route('dashboard'))->assertDontSee('Finish setting up this install');

    configureInstall();

    $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('Finish setting up this install');
});

it('ticks the setup checklist from what the install holds', function () {
    configureInstall();
    $admin = userWithRoles(Role::Admin);
    $checklist = fn (): array => collect(Livewire::actingAs($admin)->test(InstallSettingsForm::class)->instance()->checklist())
        ->pluck('done', 'label')
        ->all();

    expect($checklist())->toBe([
        'Company profile' => true,
        'Withdrawal purposes' => true,
        'Storage locations' => false,
        'Equipment' => false,
        'Spare parts' => false,
        'Opening balances' => false,
        'Personnel and badges' => false,
    ]);

    partWithStock('5', ['default_location_id' => StorageLocation::factory()->create()->id]);
    Equipment::factory()->create();
    Personnel::factory()->withBadge('A123456789')->create();

    expect($checklist())->each->toBeTrue();
});
