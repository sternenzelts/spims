<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Ledger\StockLedger;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Livewire\Station\Withdrawal;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\WithdrawalPurpose;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->supervisor->forceFill(['email' => 'rita@microtech.test', 'password' => Hash::make('counter-secret')])->save();
    $this->equipment = Equipment::factory()->create(['code' => 'CNC-01']);
    $this->purpose = WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    $this->part = partWithStock('10', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing']);
    $this->person = Personnel::factory()->create(['full_name' => 'Rosalie Mendoza', 'employee_no' => 'EMP-0042']);
    app(PersonnelBadges::class)->enroll($this->person, 'A1234BCDE', $this->custodian);
});

it('opens the station only for the roles that process withdrawals', function (Role $role, bool $allowed) {
    $response = $this->actingAs(userWithRoles($role))->get(route('station.withdrawal'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(roleMatrix('CS'));

it('adds a spare part entered by its exact number', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'brg-6204')
        ->set('quantity', '3')
        ->call('enterPart')
        ->assertHasNoErrors()
        ->assertSee('Deep groove ball bearing');

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->lines()->firstOrFail()->quantity)->toBe('3.000');
});

it('offers matches when the entry is not a part number', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'ball bearing')
        ->call('enterPart')
        ->assertHasErrors('partEntry')
        ->assertSee('BRG-6204');
});

it('walks the whole scan-last flow and lands on the receipt', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '4')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->assertHasNoErrors()
        ->assertSee('Rosalie Mendoza')
        ->call('confirm')
        ->assertHasNoErrors();

    // Typed, because partWithStock() has already completed an opening transaction for this part.
    $completed = StockTransaction::query()
        ->where('type', TransactionType::Withdrawal)
        ->where('status', TransactionStatus::Completed)
        ->sole();

    $component->assertRedirect(route('station.receipt', ['transaction' => $completed->id]));

    expect(onHand($this->part))->toBe('6.000')
        ->and($completed->transaction_no)->toStartWith('WD-');
});

it('requires a new scan after the cart changes', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->set('quantity', '2')
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('confirm')
        ->assertHasErrors('draft');

    expect(StockTransaction::query()
        ->where('type', TransactionType::Withdrawal)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(0);
});

it('refuses to confirm once the scan has expired', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan');

    $this->travel(app(WithdrawalIdentification::class)->timeoutSeconds() + 1)->seconds();

    $component->call('confirm')->assertHasErrors('draft');
});

it('rejects a badge nobody holds without touching the draft', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('badge', 'FFFF9999')
        ->call('scan')
        ->assertHasErrors('badge');
});

it('lets a supervisor authorise an override at the counter', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '5')
        ->set('overrideReason', ReasonCode::FoundStock->value)
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('authoriseOverride')
        ->assertHasNoErrors()
        ->assertSet('supervisorPassword', '');

    $draft = app(WithdrawalService::class)->openDraft($this->custodian);

    expect($draft->overrides()->count())->toBe(1)
        ->and($draft->overrides()->firstOrFail()->authorised_by_user_id)->toBe($this->supervisor->id);
});

it('binds an override to the part it was requested for', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);
    $other = partWithStock('1', ['part_number' => 'BLT-200']);

    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id);

    expect(fn () => $component->set('overridePartId', $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('names the part the supervisor is counting in the override dialog', function () {
    partWithStock('1', ['part_number' => 'BLT-100', 'description' => 'V-belt A42']);
    $short = Part::query()->where('part_number', 'BLT-100')->sole();

    $html = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->html();

    // The heading appears in the dialog alone, so the part named between it and the dialog's first
    // field is named in the dialog. Livewire has no assertSeeInOrder of its own: Laravel's reads the
    // JSON response, where the dash is escaped.
    $heading = strpos($html, 'Supervisor override');
    $part = strpos($html, 'BLT-100 — V-belt A42');
    $field = strpos($html, 'Verified quantity on the shelf');

    expect($heading)->toBeInt()
        ->and($part)->toBeInt()->toBeGreaterThan($heading)
        ->and($field)->toBeInt()->toBeGreaterThan($part);
});

it('refuses an override on wrong supervisor credentials and never keeps the password', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '5')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'wrong')
        ->call('authoriseOverride')
        ->assertHasErrors('supervisorPassword')
        ->assertSet('supervisorPassword', '');

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->overrides()->count())->toBe(0);
});

it('asks for a new count when the part moved after the supervisor counted it', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);
    // The count comes after the opening balance; timestamps are stored to the second.
    $this->travel(1)->seconds();

    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '5')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('authoriseOverride')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->assertHasNoErrors();

    // Another station records a delivery of the same part before this one confirms.
    $delivery = StockTransaction::factory()->replenishment()->withLines([[$short, '1']])->create();
    app(StockLedger::class)->complete($delivery, $this->custodian);

    $component->call('confirm')
        ->assertHasErrors('draft')
        ->assertSee('Count the shelf again');

    expect(StockTransaction::query()
        ->where('type', TransactionType::Withdrawal)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(0);

    // Wave 2: the line must offer a way to ask for a new count, without removing and re-adding it.
    $component->assertSeeHtml("requestOverride({$short->id})")
        ->assertDontSee('Override authorised');

    // And that new count really does let the withdrawal go through: re-authorise, re-scan (the
    // authorisation invalidated the stale scan too) and confirm.
    $this->travel(1)->seconds();

    $component->call('requestOverride', $short->id)
        ->set('verifiedQty', '6')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('authoriseOverride')
        ->assertHasNoErrors()
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(StockTransaction::query()
        ->where('type', TransactionType::Withdrawal)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(1);
});

it('lets a supervisor identify a person who has no badge', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('requestManualIdentification')
        ->set('manualPersonnelId', (string) $this->person->id)
        ->set('manualReason', 'Badge left at home')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('identifyManually')
        ->assertHasNoErrors()
        ->assertSet('supervisorPassword', '')
        ->assertSee('Rosalie Mendoza');
});

it('opens the no-badge dialog through the server, so it starts empty', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->assertSeeHtml('wire:click="requestManualIdentification"')
        ->set('manualPersonnelId', (string) $this->person->id)
        ->set('manualReason', 'Left over from last time')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('requestManualIdentification')
        ->assertSet('manualPersonnelId', '')
        ->assertSet('manualReason', '')
        ->assertSet('supervisorEmail', '')
        ->assertSet('supervisorPassword', '');
});

it('never lets a password typed into a dismissed override identify someone by hand', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        // The custodian dismisses the override dialog: nothing is submitted. Later they open
        // "No badge?", pick someone and submit without the supervisor typing anything.
        ->call('requestManualIdentification')
        ->set('manualPersonnelId', (string) $this->person->id)
        ->set('manualReason', 'Badge left at home')
        ->call('identifyManually')
        ->assertHasErrors('supervisorPassword');

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->personnel_id)->toBeNull();
});

it('forgets the supervisor credentials when the draft is cancelled', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('cancelDraft')
        ->assertSet('supervisorEmail', '')
        ->assertSet('supervisorPassword', '');
});

it('clears the supervisor password after an authorisation that the draft refuses', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '4')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('authoriseOverride')
        ->assertHasErrors('verifiedQty')
        ->assertSet('supervisorPassword', '');
});

it('names the spare parts when stock ran out while the custodian was reviewing', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '9')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan');

    // Another station takes the stock between the scan and the confirmation.
    $other = StockTransaction::factory()->withLines([[$this->part, '8']])->create();
    app(StockLedger::class)->complete($other, $this->custodian);

    $component->call('confirm')->assertHasErrors('draft')->assertSee('BRG-6204');
});

it('cancels a draft and starts a clean one', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('cancelDraft')
        ->assertHasNoErrors();

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->lines()->count())->toBe(0)
        ->and(StockTransaction::query()->where('status', TransactionStatus::Cancelled)->count())->toBe(1);
});

it('shows the transaction record the requirement lists, with the badge masked', function () {
    $draft = StockTransaction::factory()->withLines([[$this->part, '4']])->create([
        'custodian_user_id' => $this->custodian->id,
        'personnel_id' => $this->person->id,
        'equipment_id' => $this->equipment->id,
        'purpose_id' => $this->purpose->id,
    ]);
    $completed = app(StockLedger::class)->complete($draft, $this->custodian);

    $this->actingAs($this->custodian)
        ->get(route('station.receipt', ['transaction' => $completed->id]))
        ->assertOk()
        ->assertSee($completed->transaction_no)
        ->assertSee('Rosalie Mendoza')
        ->assertSee('EMP-0042')
        ->assertSee('BRG-6204')
        ->assertSee('Deep groove ball bearing')
        ->assertSee('CNC-01')
        ->assertSee($this->person->fresh()->badge_uid_masked)
        ->assertDontSee('A1234BCDE');
});

it('keeps the scan field on Enter', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->assertSeeHtml('wire:keydown.enter.prevent="scan"')
        ->assertSeeHtml('wire:keydown.enter.prevent="enterPart"');
});

// --- Wave 2: focus follows the work (walk W1) -----------------------------------------------

it('sends focus to the part field after adding a part, typed or picked from the matches', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->assertDispatched('station-focus', field: 'partEntry');

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->call('addPart', $this->part->id)
        ->assertDispatched('station-focus', field: 'partEntry');
});

it('sends focus to the part field when the entry matches no part number', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'nothing like this exists')
        ->call('enterPart')
        ->assertHasErrors('partEntry')
        ->assertDispatched('station-focus', field: 'partEntry');
});

it('sends focus to Confirm after a successful scan, and to the scan field after a refused one', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('badge', 'FFFF9999')
        ->call('scan')
        ->assertHasErrors('badge')
        ->assertDispatched('station-focus', field: 'badge');

    $component->set('badge', 'A1234BCDE')
        ->call('scan')
        ->assertDispatched('station-focus', field: 'confirmWithdrawal');
});

it('sends focus to the scan field after "Not this person"', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->call('clearIdentification')
        ->assertDispatched('station-focus', field: 'badge');
});

it('sends focus to the scan field after an override is authorised, since that always clears the scan', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '5')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('authoriseOverride')
        ->assertDispatched('station-focus', field: 'badge');
});

it('sends focus to Confirm after a manual identification succeeds', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('requestManualIdentification')
        ->set('manualPersonnelId', (string) $this->person->id)
        ->set('manualReason', 'Badge left at home')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('identifyManually')
        ->assertDispatched('station-focus', field: 'confirmWithdrawal');
});

it('sends focus to the field a confirm refusal is about, in guardReadyToConfirm\'s own order', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->call('confirm')
        ->assertDispatched('station-focus', field: 'partEntry');

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('confirm')
        ->assertDispatched('station-focus', field: 'equipmentId');

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->call('saveContext')
        ->call('confirm')
        ->assertDispatched('station-focus', field: 'purposeId');
});

it('puts autofocus on the first field of each dialog, so a native dialog focuses it on open', function () {
    $html = Livewire::actingAs($this->custodian)->test(Withdrawal::class)->html();

    expect($html)->toMatch('/autofocus="autofocus"\s+data-focus="verifiedQty"/')
        ->and($html)->toMatch('/wire:model="manualPersonnelId"[^>]*autofocus="autofocus"/');
});

// --- Wave 2: a dismissed dialog forgets the supervisor's password (item 11) ------------------

it('clears the supervisor credentials on the server whenever either dialog closes', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->assertSeeHtml('wire:close="forgetSupervisorCredentials"')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'typed-but-never-submitted')
        ->call('forgetSupervisorCredentials')
        ->assertSet('supervisorEmail', '')
        ->assertSet('supervisorPassword', '');

    // Only a browser can prove the other half: that Escape, the backdrop or the close button
    // actually fire the dialog's native "close" event (which wire:close listens for), and that
    // the x-on:close handler empties the <input type="password"> in the DOM itself.
});

// --- Wave 2: an expired scan clears itself and shows the scan field again (item 2) -----------

it('clears an expired identification on a refused confirm, so the scan field comes back', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->assertSeeHtml('wire:click="clearIdentification"');

    $this->travel(app(WithdrawalIdentification::class)->timeoutSeconds() + 1)->seconds();

    $component->call('confirm')
        ->assertHasErrors('draft')
        ->assertDontSeeHtml('wire:click="clearIdentification"')
        ->assertSeeHtml('wire:keydown.enter.prevent="scan"')
        ->assertDispatched('station-focus', field: 'badge');

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->personnel_id)->toBeNull();
});

// --- Wave 2: the identified person's photo (item 3) -------------------------------------------

it('shows the identified person\'s photo when they have one', function () {
    Storage::fake();
    Storage::put('personnel/face.jpg', 'not-really-a-photo');
    $this->person->forceFill(['photo_path' => 'personnel/face.jpg'])->save();

    $html = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->html();

    expect($html)->toContain(route('media.show', ['path' => 'personnel/face.jpg']));

    // The route a custodian's browser actually requests for that <img src>.
    $this->actingAs($this->custodian)
        ->get(route('media.show', ['path' => 'personnel/face.jpg']))
        ->assertOk();
});

it('keeps the id-card icon when the identified person has no photo', function () {
    $html = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->html();

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('identification');
});

// --- Wave 2: no error message is rendered twice (item 10) -------------------------------------

it('renders each error once, not once from the field and once from a separate <flux:error>', function () {
    $html = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'nothing like this exists')
        ->call('enterPart')
        ->html();

    expect(substr_count($html, __('No spare part has that number. Pick one from the matches below.')))->toBe(1);
});
