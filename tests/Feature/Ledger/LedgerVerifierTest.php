<?php

use App\Domain\Ledger\DiscrepancyKind;
use App\Domain\Ledger\LedgerDiscrepancy;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => configureInstall());

it('finds nothing wrong with a ledger written by StockLedger', function () {
    $part = partWithStock('10');
    app(StockLedger::class)->complete(StockTransaction::factory()->withLines([[$part, '4']])->create(), User::factory()->create());

    expect(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('detects a stock balance that no longer matches its movements', function () {
    $part = partWithStock('10');
    DB::table('part_stock')->where('part_id', $part->id)->update(['on_hand' => '12']);

    expect(app(LedgerVerifier::class)->verify())->toEqual([
        new LedgerDiscrepancy(DiscrepancyKind::StockCache, $part->id, $part->part_number, expected: '10.000', actual: '12.000'),
    ]);
});

it('detects a movement whose stamped balance breaks the running sum', function () {
    $part = partWithStock('10');
    $opening = StockMovement::query()->where('part_id', $part->id)->sole();

    // Written around StockLedger: a real quantity with a wrong stamped balance.
    $rogueId = DB::table('stock_movements')->insertGetId([
        'part_id' => $part->id,
        'stock_transaction_id' => $opening->stock_transaction_id,
        'movement_type' => 'adjustment',
        'quantity' => '-1',
        'balance_after' => '10',
        'occurred_at' => CarbonImmutable::now(),
        'custodian_user_id' => $opening->custodian_user_id,
    ]);
    DB::table('part_stock')->where('part_id', $part->id)->update(['on_hand' => '9']);

    expect(app(LedgerVerifier::class)->verify())->toEqual([
        new LedgerDiscrepancy(DiscrepancyKind::RunningBalance, $part->id, $part->part_number, expected: '9.000', actual: '10.000', movementId: $rogueId),
    ]);
});

it('passes the nightly command when the ledger is sound', function () {
    partWithStock('10');

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('no discrepancies')
        ->assertSuccessful();
});

it('fails the nightly command and logs critically when a balance diverges', function () {
    Log::spy();
    $part = partWithStock('10');
    DB::table('part_stock')->where('part_id', $part->id)->update(['on_hand' => '12']);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('1 ledger discrepancies found.')
        ->assertFailed();

    Log::shouldHaveReceived('critical')->once();
});

it('runs ledger verification every night', function () {
    $verification = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'ledger:verify'));

    expect($verification?->expression)->toBe('0 18 * * *');
});
