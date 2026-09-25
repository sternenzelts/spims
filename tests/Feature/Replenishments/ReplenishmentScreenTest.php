<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Replenishments\ReplenishmentService;
use App\Domain\Settings\InstallSettings;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Livewire\Station\Replenishment;
use App\Models\StockTransaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->part = partWithStock('4', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing', 'max_level' => '10']);
    $this->today = CarbonImmutable::now()->setTimezone(app(InstallSettings::class)->timezone())->toDateString();
});

it('opens the replenishment screen only for the roles that record deliveries', function (Role $role, bool $allowed) {
    $response = $this->actingAs(userWithRoles($role))->get(route('station.replenishment'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(roleMatrix('CS'));

it('records a delivery from end to end', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '6')
        ->set('unitCost', '145.50')
        ->call('enterPart')
        ->assertHasNoErrors()
        ->call('confirm')
        ->assertHasNoErrors();

    expect(onHand($this->part))->toBe('10.000')
        ->and(StockTransaction::query()
            ->where('status', TransactionStatus::Completed)
            ->where('type', TransactionType::Replenishment)
            ->sole()
            ->transaction_no)->toStartWith('RP-');
});

it('warns about a delivery reference already used by that supplier and still confirms', function () {
    $service = app(ReplenishmentService::class);
    $earlier = $service->openDraft($this->custodian);
    $service->setDelivery($earlier, 'Pacific Bearings Inc.', 'DR-00921', $this->today);
    $service->addLine($earlier, $this->part, '1', null);
    app(StockLedger::class)->complete($earlier, $this->custodian);

    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '1')
        ->call('enterPart')
        ->assertSee(__('already recorded'))
        ->call('confirm')
        ->assertHasNoErrors();
});

it('warns about a line that goes over the maximum level and still confirms', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '20')
        ->call('enterPart')
        ->assertSee(__('above their maximum level'))
        ->call('confirm')
        ->assertHasNoErrors();

    expect(onHand($this->part))->toBe('24.000');
});

it('refuses a delivery received on an earlier day and names the backdated entry screen', function () {
    // Wave 2: no explicit Save click needed — the last field set (receivedOn) autosaves once
    // supplier and reference are both already present, and is the one that gets refused here.
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', CarbonImmutable::parse($this->today)->subDay()->toDateString())
        ->assertHasErrors('receivedOn');
});

it('refuses to confirm after midnight a delivery saved the day before, even after a reload', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 23:50:00', 'Asia/Manila'));

    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', '2026-09-24')
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->assertHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-09-25 00:10:00', 'Asia/Manila'));

    // Wave 2: a reload now shows the date that was actually saved, not today (mount() reads
    // occurred_at, not now()) — the very divergence this fix wave closes. It still refuses to
    // confirm: the delivery is genuinely a day old now, and that needs a backdated entry.
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->assertSet('receivedOn', '2026-09-24')
        ->call('confirm')
        ->assertHasErrors('draft')
        ->assertSee('backdated entry');

    expect(onHand($this->part))->toBe('4.000');
});

it('refuses to confirm before there is a delivery and a line', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->call('confirm')
        ->assertHasErrors('draft');
});

it('adds a part on Enter', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->assertSeeHtml('wire:keydown.enter.prevent="enterPart"');
});

// --- Wave 2: focus follows the work (walk W1), applied to the delivery screen's part field ---

it('sends focus to the part field after adding a part, and after a refusal', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->assertDispatched('station-focus', field: 'partEntry');

    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('partEntry', 'nothing like this exists')
        ->call('enterPart')
        ->assertHasErrors('partEntry')
        ->assertDispatched('station-focus', field: 'partEntry');
});

// --- Wave 2: hide the dead-end "New spare part" link from whoever can't use it (walk W5) -----

it('shows the New spare part link only to someone who can actually create one', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->assertDontSee(__('New spare part'))
        ->set('partEntry', 'nothing like this exists')
        ->call('enterPart')
        ->assertDontSee('create it on the spare parts screen');

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Replenishment::class)
        ->assertSee(__('New spare part'))
        ->set('partEntry', 'nothing like this exists')
        ->call('enterPart')
        ->assertSee('create it on the spare parts screen');
});

// --- Wave 2: no error message is rendered twice (item 10) -------------------------------------

it('renders the delivery screen\'s errors once, not once from the field and once from a separate <flux:error>', function () {
    $html = Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('partEntry', 'nothing like this exists')
        ->call('enterPart')
        ->html();

    expect(substr_count($html, __('No spare part has that number. Pick one from the matches below.')))->toBe(1);
});

// --- Wave 2: what the screen shows must be what is saved (review C finding 1, item 1) --------

it('saves the reference as it changes, without a Save click', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        // Changed on screen, never followed by an explicit Save click.
        ->set('referenceNo', 'DR-00999')
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart');

    $draft = app(ReplenishmentService::class)->openDraft($this->custodian);

    expect($draft->reference_no)->toBe('DR-00999');
});

it('does not autosave the delivery until both supplier and reference are filled', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->assertHasNoErrors();

    $draft = app(ReplenishmentService::class)->openDraft($this->custodian);

    expect($draft->supplier)->toBeNull();
});

it('does not touch the draft when a field resyncs to the value it already had', function () {
    $component = Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery');

    $before = app(ReplenishmentService::class)->openDraft($this->custodian)->updated_at;

    $this->travel(1)->seconds();
    $component->set('supplier', 'Pacific Bearings Inc.');

    expect(app(ReplenishmentService::class)->openDraft($this->custodian)->updated_at->equalTo($before))->toBeTrue();
});

it('reverts the delivery fields to the draft\'s values when the change is refused', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('receivedOn', CarbonImmutable::parse($this->today)->subDay()->toDateString())
        ->assertHasErrors('receivedOn')
        // Refused: the screen goes back to what the draft still holds, not the rejected value.
        ->assertSet('receivedOn', $this->today);
});

it('does not wipe a still-typed supplier and reference when only the date is refused', function () {
    // Ordinary order of work: the date first — a genuinely earlier day, the refused case — then
    // the supplier and the reference. Autosave waits for both of those before it ever attempts a
    // save, so this is the first save attempt, and it fails on the date alone.
    $component = Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('receivedOn', CarbonImmutable::parse($this->today)->subDay()->toDateString())
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1');

    $component->assertHasErrors('receivedOn')
        // The date reverts — it was the actual problem...
        ->assertSet('receivedOn', $this->today)
        // ...but the supplier and reference just typed survive on screen.
        ->assertSet('supplier', 'Pacific Bearings Inc.')
        ->assertSet('referenceNo', 'DR-1');

    // Never actually saved (setDelivery() refused before writing anything), so confirming now
    // must refuse rather than post while the draft still holds nothing for either.
    $component->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('confirm')
        ->assertHasErrors('draft');

    expect(StockTransaction::query()
        ->where('type', TransactionType::Replenishment)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(0);
});

it('refuses to confirm when the reference on screen was cleared and never re-saved', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        // Cleared back to blank: autosave never runs while it's empty, so the draft still holds
        // "DR-1" while the screen shows nothing.
        ->set('referenceNo', '')
        ->call('confirm')
        ->assertHasErrors('draft');

    expect(StockTransaction::query()
        ->where('type', TransactionType::Replenishment)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(0);
});

it('re-checks the permission when the supplier is changed after roles are revoked', function () {
    // Autosave waits for both fields, so setting the reference alone (authorize() still passes,
    // roles are intact) never reaches setDelivery() itself — nothing is written yet, matching the
    // "don't save what nobody has finished typing" behaviour above. The write is attempted once
    // supplier follows it.
    $screen = Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('referenceNo', 'DR-1');

    $this->custodian->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $screen->set('supplier', 'Pacific Bearings Inc.')->assertForbidden();
});

// --- Fix round 1: re-review item 1 -------------------------------------------------------------

it('re-checks the permission even when the resent value has not changed', function () {
    $screen = Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', $this->today);

    $this->custodian->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Same values as already saved: the unchanged guard returns before saveDelivery() ever runs.
    $screen->set('supplier', 'Pacific Bearings Inc.')->assertForbidden();
});

it('does not silently confirm when a refused autosave is bundled with Confirm delivery in the same request', function () {
    $component = Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart');

    // One request carrying both an invalid property update and an action call, exactly as a mouse
    // click bundles the blur of the field it leaves with the click that follows it.
    $component->update(
        calls: [['method' => 'confirm', 'params' => [], 'path' => '']],
        updates: ['receivedOn' => CarbonImmutable::parse($this->today)->subDay()->toDateString()],
    );

    $component->assertHasErrors('receivedOn');

    expect(StockTransaction::query()
        ->where('type', TransactionType::Replenishment)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(0);

    expect(app(ReplenishmentService::class)->openDraft($this->custodian)->occurred_at
        ->setTimezone(app(InstallSettings::class)->timezone())
        ->toDateString())->toBe($this->today);
});
