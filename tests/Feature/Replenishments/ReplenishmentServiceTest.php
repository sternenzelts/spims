<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Domain\Replenishments\ReplenishmentService;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(ReplenishmentService::class);
    $this->today = CarbonImmutable::now()->setTimezone(app(InstallSettings::class)->timezone())->toDateString();
});

it('opens one replenishment draft per custodian', function () {
    $first = $this->service->openDraft($this->custodian);

    expect($this->service->openDraft($this->custodian)->is($first))->toBeTrue()
        ->and($first->type)->toBe(TransactionType::Replenishment)
        ->and($first->status)->toBe(TransactionStatus::Draft);
});

it('keeps a replenishment draft separate from a withdrawal draft', function () {
    $withdrawal = app(WithdrawalService::class)->openDraft($this->custodian);

    expect($this->service->openDraft($this->custodian)->is($withdrawal))->toBeFalse();
});

it('records the supplier, the reference and the date received', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->setDelivery($draft, '  Pacific Bearings Inc. ', ' dr-00921 ', $this->today);

    expect($draft->fresh())->supplier->toBe('Pacific Bearings Inc.')->reference_no->toBe('DR-00921');
});

it('adds a line that adds stock', function () {
    $draft = $this->service->openDraft($this->custodian);

    $line = $this->service->addLine($draft, Part::factory()->create(), '12', '145.5000');

    expect($line->direction)->toBe(1)->and($line->quantity)->toBe('12.000')->and($line->unit_cost)->toBe('145.5000');
});

it('merges a repeated part into one line', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();

    $this->service->addLine($draft, $part, '5', null);
    $this->service->addLine($draft, $part, '3', null);

    expect($draft->lines()->count())->toBe(1)->and($draft->lines()->firstOrFail()->quantity)->toBe('8.000');
});

it('refuses an inactive part, a quantity of zero or less and a negative unit cost', function (Closure $attempt, string $expected) {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $attempt($this->service, $draft))->toThrow(ReplenishmentRefused::class, $expected);
})->with([
    'inactive part' => [
        fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(['is_active' => false, 'part_number' => 'OLD-1']), '1', null),
        'OLD-1',
    ],
    'zero quantity' => [fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(), '0', null), 'greater than zero'],
    'negative unit cost' => [fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(), '1', '-1'), 'unit cost'],
]);

it('refuses a delivery with no supplier or no reference', function () {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $this->service->setDelivery($draft, '  ', 'DR-1', $this->today))
        ->toThrow(ReplenishmentRefused::class, 'Supplier')
        ->and(fn () => $this->service->setDelivery($draft, 'Pacific Bearings Inc.', ' ', $this->today))
        ->toThrow(ReplenishmentRefused::class, 'reference');
});

it('refuses a date received that is not today in the install time zone', function (int $days, string $expected) {
    $draft = $this->service->openDraft($this->custodian);
    $date = CarbonImmutable::parse($this->today)->addDays($days)->toDateString();

    expect(fn () => $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', $date))
        ->toThrow(ReplenishmentRefused::class, $expected);
})->with([
    'tomorrow' => [1, 'in the future'],
    'yesterday' => [-1, 'backdated entry'],
]);

it('accepts today in the install time zone even when UTC has not caught up', function () {
    app(InstallSettings::class)->set('regional.timezone', 'Asia/Manila', $this->custodian);
    // 00:30 in Manila on the 24th is still 16:30 UTC on the 23rd.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 16:30:00', 'UTC'));
    $draft = $this->service->openDraft($this->custodian);

    $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', '2026-09-24');

    expect($draft->fresh()->reference_no)->toBe('DR-1');
});

it('warns about a delivery reference already recorded for the same supplier', function () {
    $part = Part::factory()->create();
    $earlier = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($earlier, 'Pacific Bearings Inc.', 'DR-00921', $this->today);
    $this->service->addLine($earlier, $part, '5', null);
    app(StockLedger::class)->complete($earlier, $this->custodian);

    $draft = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($draft, 'pacific bearings inc.', 'dr-00921', $this->today);

    expect($this->service->duplicateDelivery($draft)?->is($earlier))->toBeTrue();
});

it('does not warn when the same reference comes from another supplier', function () {
    $earlier = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($earlier, 'Pacific Bearings Inc.', 'DR-00921', $this->today);
    $this->service->addLine($earlier, Part::factory()->create(), '5', null);
    app(StockLedger::class)->complete($earlier, $this->custodian);

    $draft = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($draft, 'Luzon Belting Corp.', 'DR-00921', $this->today);

    expect($this->service->duplicateDelivery($draft))->toBeNull();
});

it('warns about a line that takes a part over its maximum level', function () {
    $overshoot = partWithStock('8', ['max_level' => '10', 'part_number' => 'BRG-1']);
    $within = partWithStock('1', ['max_level' => '10']);
    $unlimited = partWithStock('1', ['max_level' => null]);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $overshoot, '5', null);
    $this->service->addLine($draft, $within, '5', null);
    $this->service->addLine($draft, $unlimited, '5000', null);

    expect(array_map(fn (Part $part): string => $part->part_number, $this->service->overMaximum($draft)))
        ->toBe(['BRG-1']);
});

it('refuses to confirm until the delivery and at least one line are there', function () {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $this->service->guardReadyToConfirm($draft))->toThrow(ReplenishmentRefused::class, 'Supplier');

    $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', $this->today);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))->toThrow(ReplenishmentRefused::class, 'at least one');

    $this->service->addLine($draft, Part::factory()->create(), '1', null);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))->not->toThrow(ReplenishmentRefused::class);
});

it('refuses every change to a replenishment another request has completed', function (Closure $change) {
    $part = Part::factory()->create();
    $draft = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', $this->today);
    $line = $this->service->addLine($draft, $part, '5', '10');

    // The second tab's copy, taken while the draft was still a Draft.
    $stale = $draft->fresh();
    app(StockLedger::class)->complete($draft->fresh(), $this->custodian);

    expect(fn () => $change($this->service, $stale, $line))->toThrow(ReplenishmentRefused::class, 'completed');

    $completed = $draft->fresh();

    expect($completed->status)->toBe(TransactionStatus::Completed)
        ->and($completed->supplier)->toBe('Pacific Bearings Inc.')
        ->and($completed->reference_no)->toBe('DR-1')
        ->and($completed->lines()->count())->toBe(1)
        ->and($completed->lines()->sole()->quantity)->toBe('5.000')
        ->and($completed->lines()->sole()->unit_cost)->toBe('10.0000');
})->with([
    'setting the delivery' => [fn (ReplenishmentService $s, $d) => $s->setDelivery($d, 'Luzon Belting Corp.', 'DR-2', test()->today)],
    'adding a part' => [fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(), '1', null)],
    'changing a quantity' => [fn (ReplenishmentService $s, $d, $line) => $s->setLineQuantity($d, $line, '9', '12')],
    'removing a line' => [fn (ReplenishmentService $s, $d, $line) => $s->removeLine($d, $line)],
    'cancelling' => [fn (ReplenishmentService $s, $d) => $s->cancel($d, test()->custodian)],
]);

it('cancels a draft and audits it', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->cancel($draft, $this->custodian);

    expect($draft->fresh()->status)->toBe(TransactionStatus::Cancelled)
        ->and(AuditLog::query()->where('event', 'cancelled')->count())->toBe(1);
});
