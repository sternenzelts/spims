<?php

use App\Domain\Identity\PersonnelBadges;
use App\Enums\Role;
use App\Livewire\Personnel\Form;
use App\Livewire\Personnel\Index;
use App\Models\AuditLog;
use App\Models\Personnel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('lets supervisors and administrators register personnel and enroll badges', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $person = Personnel::factory()->create();

    $this->actingAs($user)->get(route('personnel.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('personnel.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('personnel.edit', $person))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Personnel', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-S-A'));

it('registers a person with the employee number normalised, audited, then asks for the badge', function () {
    $supervisor = userWithRoles(Role::Supervisor);

    $screen = Livewire::actingAs($supervisor)->test(Form::class)
        ->set('employee_no', ' emp-00042 ')
        ->set('full_name', 'Jose Rizal')
        ->set('department', 'Maintenance')
        ->call('save')
        ->assertHasNoErrors();

    $person = Personnel::query()->sole();
    $screen->assertRedirect(route('personnel.edit', $person));

    expect($person->employee_no)->toBe('EMP-00042')
        ->and(AuditLog::query()->where('auditable_type', 'personnel')->where('event', 'created')->sole())
        ->user_id->toBe($supervisor->id)
        ->new_values->toBe([
            'full_name' => 'Jose Rizal',
            'is_active' => true,
            'department' => 'Maintenance',
            'photo_path' => null,
            'employee_no' => 'EMP-00042',
        ]);
});

it('refuses an employee number already registered, in any letter case', function () {
    Personnel::factory()->create(['employee_no' => 'EMP-00042']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('employee_no', 'emp-00042')
        ->set('full_name', 'Someone Else')
        ->call('save')
        ->assertHasErrors('employee_no');
});

it('enrolls a scanned badge and shows it masked', function () {
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', "a1234 5678\n")
        ->call('enrollBadge')
        ->assertHasNoErrors()
        ->assertSet('badgeScan', '')
        ->assertSee('A1234****')
        ->assertDontSee('A12345678');

    expect(app(PersonnelBadges::class)->identify('A12345678')?->is($person))->toBeTrue();
});

it('refuses a badge an Active person holds, naming them', function () {
    Personnel::factory()->withBadge('A12345678')->create(['full_name' => 'Andres Bonifacio', 'employee_no' => 'EMP-1']);
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasErrors('badgeScan')
        ->assertSee('Andres Bonifacio')
        ->assertSee('A1234****');

    expect($person->refresh()->badge_uid_hash)->toBeNull();
});

it('takes a badge back from an Inactive person', function () {
    $former = Personnel::factory()->inactive()->withBadge('A12345678')->create();
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasNoErrors();

    expect($former->refresh()->badge_uid_hash)->toBeNull()
        ->and(app(PersonnelBadges::class)->identify('A12345678')?->is($person))->toBeTrue();
});

it('reports a badge enrolled elsewhere at the same moment as already enrolled', function () {
    $person = Personnel::factory()->create();
    $rival = Personnel::factory()->create();

    // The race from Plan 1's Task 6 review: another supervisor's enrollment of the same badge lands
    // between this enrollment's holder check and its save, so the unique index refuses the save.
    Personnel::updating(function (Personnel $saving) use ($rival): void {
        if (! $saving->is($rival) && $saving->badge_uid_hash !== null) {
            Personnel::query()->whereKey($rival->id)->update(['badge_uid_hash' => $saving->badge_uid_hash]);
        }
    });

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasErrors('badgeScan')
        ->assertSee('already enrolled');

    expect($person->refresh()->badge_uid_hash)->toBeNull();
});

it('deactivates a person, so their badge stops confirming withdrawals, audited', function () {
    $person = Personnel::factory()->withBadge('A12345678')->create();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class, ['personnel' => $person])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(PersonnelBadges::class)->identify('A12345678'))->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', 'personnel')->where('event', 'updated')->sole())
        ->old_values->toBe(['is_active' => true])
        ->new_values->toBe(['is_active' => false]);
});

it('shows full badge identifiers to System Administrators only', function () {
    $person = Personnel::factory()->withBadge('A12345678')->create();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class, ['personnel' => $person])
        ->call('revealBadge')
        ->assertSee('A12345678');

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->assertDontSee('Show full badge')
        ->call('revealBadge')
        ->assertForbidden();
});

it('lists personnel with masked badges only, and finds them by number or name', function () {
    Personnel::factory()->withBadge('A12345678')->create(['employee_no' => 'EMP-1', 'full_name' => 'Jose Rizal']);
    Personnel::factory()->create(['employee_no' => 'EMP-2', 'full_name' => 'Apolinario Mabini']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Index::class)
        ->assertSee('A1234****')
        ->assertDontSee('A12345678')
        ->set('search', 'mabini')
        ->assertSee('EMP-2')
        ->assertDontSee('EMP-1');
});

it('stores an uploaded photo', function () {
    Storage::fake();
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('photo', UploadedFile::fake()->create('face.jpg', 50, 'image/jpeg'))
        ->call('save')
        ->assertHasNoErrors();

    expect($person->refresh()->photo_path)->toStartWith('personnel/');
    Storage::assertExists($person->photo_path);
});
