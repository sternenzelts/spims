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
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', CarbonImmutable::parse($this->today)->subDay()->toDateString())
        ->call('saveDelivery')
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

    // A reload fills the date field with the new day; what was saved still says yesterday.
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->assertSet('receivedOn', '2026-09-25')
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
