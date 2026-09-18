<?php

use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\OpeningBalanceAlreadyRecorded;
use App\Domain\Ledger\Exceptions\TransactionHasNoLines;
use App\Domain\Ledger\Exceptions\TransactionNotDraft;
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\MovementType;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->custodian = User::factory()->create();
});

it('completes a withdrawal with signed movements, resulting balances, a number and the time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 03:15:00', 'UTC'));
    $bearing = partWithStock('20');
    $belt = partWithStock('4');
    $draft = StockTransaction::factory()->withLines([[$bearing, '15'], [$belt, '4']])->create();

    $this->ledger->complete($draft, $this->custodian);

    expect($draft->fresh())
        ->status->toBe(TransactionStatus::Completed)
        ->transaction_no->toBe('WD-2026-000001')
        ->recorded_at->toEqual(CarbonImmutable::parse('2026-09-14 03:15:00', 'UTC'))
        ->occurred_at->toEqual(CarbonImmutable::parse('2026-09-14 03:15:00', 'UTC'));

    expect(StockMovement::query()->where('stock_transaction_id', $draft->id)->orderBy('id')->get()
        ->map(fn (StockMovement $movement): array => [$movement->part_id, $movement->movement_type, $movement->quantity, $movement->balance_after])
        ->all())->toBe([
            [$bearing->id, MovementType::Withdrawal, '-15.000', '5.000'],
            [$belt->id, MovementType::Withdrawal, '-4.000', '0.000'],
        ]);

    expect(onHand($bearing))->toBe('5.000')
        ->and(onHand($belt))->toBe('0.000');
});

it('records who, for which machine and why on every movement, including per-line overrides', function () {
    $part = partWithStock('10');
    $other = partWithStock('10');
    $pump = Equipment::factory()->create();
    $draft = StockTransaction::factory()->withLines([[$part, '1'], [$other, '1']])->create();
    $draft->lines()->where('part_id', $other->id)->update(['equipment_id' => $pump->id]);

    $this->ledger->complete($draft, $this->custodian);

    $movements = StockMovement::query()->where('stock_transaction_id', $draft->id)->get()->keyBy('part_id');

    expect($movements[$part->id])
        ->personnel_id->toBe($draft->personnel_id)
        ->equipment_id->toBe($draft->equipment_id)
        ->purpose_id->toBe($draft->purpose_id)
        ->custodian_user_id->toBe($draft->custodian_user_id)
        ->and($movements[$other->id]->equipment_id)->toBe($pump->id);
});

it('posts all lines or none when one line is short', function () {
    $plenty = partWithStock('50');
    $scarce = partWithStock('2', ['part_number' => 'BRG-6205']);
    $draft = StockTransaction::factory()->withLines([[$plenty, '5'], [$scarce, '3']])->create();

    expect(fn () => $this->ledger->complete($draft, $this->custodian))
        ->toThrow(InsufficientStock::class, 'Insufficient stock for BRG-6205: 2.000 available, 3.000 requested.');

    expect($draft->fresh())->status->toBe(TransactionStatus::Draft)->transaction_no->toBeNull()
        ->and(StockMovement::query()->where('stock_transaction_id', $draft->id)->exists())->toBeFalse()
        ->and(onHand($plenty))->toBe('50.000')
        ->and(onHand($scarce))->toBe('2.000');

    // The refused posting did not use up a transaction number.
    $fits = StockTransaction::factory()->withLines([[$scarce, '2']])->create();
    expect($this->ledger->complete($fits, $this->custodian)->transaction_no)->toEndWith('-000001');
});

it('adds stock for opening balances and replenishments', function () {
    $part = partWithStock('3');
    $receipt = StockTransaction::factory()->replenishment()->withLines([[$part, '12.5']])->create();

    $this->ledger->complete($receipt, $this->custodian);

    expect(onHand($part))->toBe('15.500')
        ->and(StockMovement::query()->where('part_id', $part->id)->orderBy('id')->get()
            ->map(fn (StockMovement $movement): array => [$movement->movement_type, $movement->quantity, $movement->balance_after])
            ->all())
        ->toBe([
            [MovementType::Opening, '3.000', '3.000'],
            [MovementType::Replenishment, '12.500', '15.500'],
        ]);
});

it('applies each adjustment line in its own direction', function () {
    $found = partWithStock('1');
    $damaged = partWithStock('10');
    $draft = StockTransaction::factory()->adjustment(ReasonCode::Miscount)->withLines([[$found, '2', 1], [$damaged, '3', -1]])->create();

    $this->ledger->complete($draft, $this->custodian);

    expect(onHand($found))->toBe('3.000')
        ->and(onHand($damaged))->toBe('7.000');
});

it('keeps decimal quantities exact', function () {
    $oil = partWithStock('0.3', ['unit_of_measure' => 'L']);

    $this->ledger->complete(StockTransaction::factory()->withLines([[$oil, '0.1']])->create(), $this->custodian);
    $this->ledger->complete(StockTransaction::factory()->withLines([[$oil, '0.2']])->create(), $this->custodian);

    // In floating point 0.3 - 0.1 - 0.2 is not zero, and the stock check would refuse it.
    expect(onHand($oil))->toBe('0.000');
});

it('refuses a second opening balance for a part', function () {
    $part = partWithStock('5');
    $again = StockTransaction::factory()->opening()->withLines([[$part, '5']])->create();

    expect(fn () => $this->ledger->complete($again, $this->custodian))
        ->toThrow(OpeningBalanceAlreadyRecorded::class, $part->part_number);
});

it('keeps the paper form date of a backdated entry and appends it in recording order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 09:00:00', 'UTC'));
    $part = partWithStock('10');
    $this->ledger->complete(StockTransaction::factory()->replenishment()->withLines([[$part, '5']])->create(), $this->custodian);

    $outage = CarbonImmutable::parse('2026-09-12 20:00:00', 'UTC');
    $paper = StockTransaction::factory()->backdated($outage)->withLines([[$part, '4']])->create();

    $this->ledger->complete($paper, $this->custodian);

    expect(StockMovement::query()->where('stock_transaction_id', $paper->id)->sole())
        ->occurred_at->toEqual($outage)
        ->recorded_at->toEqual(CarbonImmutable::parse('2026-09-14 09:00:00', 'UTC'))
        // The balance when it was recorded (15 - 4), not a rewrite of history (design §4.5).
        ->balance_after->toBe('11.000')
        ->and($paper->fresh()?->occurred_at)->toEqual($outage);
});

it('refuses to complete a transaction twice', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    $this->ledger->complete($draft, $this->custodian);

    expect(fn () => $this->ledger->complete($draft, $this->custodian))->toThrow(TransactionNotDraft::class)
        ->and(onHand($part))->toBe('9.000');
});

it('refuses a draft with no lines', function () {
    expect(fn () => $this->ledger->complete(StockTransaction::factory()->create(), $this->custodian))
        ->toThrow(TransactionHasNoLines::class);
});

it('refuses a line whose direction contradicts its type', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1', 1]])->create();

    expect(fn () => $this->ledger->complete($draft, $this->custodian))
        ->toThrow(LogicException::class, 'must have direction -1');
});

it('records no stock until the company name is set', function () {
    $part = Part::factory()->create();
    $opening = StockTransaction::factory()->opening()->withLines([[$part, '5']])->create();
    app(InstallSettings::class)->set('company.name', null, $this->custodian);

    expect(fn () => $this->ledger->complete($opening, $this->custodian))->toThrow(InstallNotConfigured::class)
        ->and(StockMovement::query()->exists())->toBeFalse();
});

it('audits the completion with the acting user', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    $completed = $this->ledger->complete($draft, $this->custodian);

    expect(AuditLog::query()->where('auditable_type', 'stock_transaction')->where('auditable_id', (string) $draft->id)->sole())
        ->event->toBe('completed')
        ->user_id->toBe($this->custodian->id)
        ->new_values->toBe(['status' => 'completed', 'transaction_no' => $completed->transaction_no]);
});

it('announces each completed transaction', function () {
    Event::fake([StockTransactionCompleted::class]);
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    $this->ledger->complete($draft, $this->custodian);

    Event::assertDispatched(StockTransactionCompleted::class, fn (StockTransactionCompleted $event): bool => $event->transaction->is($draft));
});

it('locks stock rows in part id order, whatever the line order, so stations cannot deadlock', function () {
    $first = partWithStock('10');
    $second = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$second, '1'], [$first, '1']])->create();

    DB::enableQueryLog();
    $this->ledger->complete($draft, $this->custodian);

    $lock = collect(DB::getQueryLog())->first(fn (array $entry): bool => str_contains($entry['query'], 'from "part_stock"'));

    expect($lock['query'])->toBe('select * from "part_stock" where "part_id" in (?, ?) order by "part_id" asc for update')
        ->and($lock['bindings'])->toBe([$first->id, $second->id]);
});
