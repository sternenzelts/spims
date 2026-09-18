<?php

use App\Enums\IdentificationMethod;
use App\Enums\TransactionStatus;
use App\Models\Part;
use App\Models\StockTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Force a draft straight to completed in SQL, bypassing StockLedger, to prove the database itself
// refuses an incomplete record.
$completeInSql = fn (StockTransaction $transaction): Closure => inSavepoint(
    fn () => DB::table('stock_transactions')->where('id', $transaction->id)->update([
        'status' => 'completed',
        'transaction_no' => 'TEST-2026-000001',
        'recorded_at' => CarbonImmutable::now(),
    ]),
);

it('lets a draft exist without a transaction number', function () {
    expect(StockTransaction::factory()->create()->fresh())
        ->status->toBe(TransactionStatus::Draft)
        ->transaction_no->toBeNull();
});

it('refuses a completed transaction without a number', function () {
    $draft = StockTransaction::factory()->create();

    expect(inSavepoint(fn () => DB::table('stock_transactions')->where('id', $draft->id)->update([
        'status' => 'completed',
        'recorded_at' => CarbonImmutable::now(),
    ])))->toThrow(QueryException::class, 'posted_is_numbered');
});

it('refuses to complete a withdrawal that is not fully attributed', function (string $missing) use ($completeInSql) {
    $draft = StockTransaction::factory()->create([$missing => null]);

    expect($completeInSql($draft))->toThrow(QueryException::class, 'completed_withdrawal_is_attributed');
})->with(['personnel_id', 'equipment_id', 'purpose_id', 'identification_method']);

it('refuses to complete an adjustment without a reason', function () use ($completeInSql) {
    $draft = StockTransaction::factory()->adjustment()->create(['reason_code' => null]);

    expect($completeInSql($draft))->toThrow(QueryException::class, 'adjustment_has_reason');
});

it('refuses manual identification without the authorising supervisor and reason', function () {
    expect(inSavepoint(fn () => StockTransaction::factory()->create([
        'identification_method' => IdentificationMethod::SupervisorManual,
    ])))->toThrow(QueryException::class, 'manual_identification_is_authorised');
});

it('refuses a backdated entry without its paper form number or reason', function (string $missing, string $constraint) {
    expect(inSavepoint(fn () => StockTransaction::factory()
        ->backdated(CarbonImmutable::now()->subDay())
        ->create([$missing => null])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'paper form' => ['paper_form_no', 'backdated_has_paper_form'],
    'reason' => ['backdate_reason', 'backdated_has_reason'],
]);

it('refuses the same paper form entered twice', function () {
    StockTransaction::factory()->backdated(CarbonImmutable::now()->subDay())->create(['paper_form_no' => 'PF-000123']);

    expect(inSavepoint(fn () => StockTransaction::factory()
        ->backdated(CarbonImmutable::now()->subDay())
        ->create(['paper_form_no' => 'PF-000123'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps one line per part in a transaction', function () {
    $part = Part::factory()->create();
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    expect(inSavepoint(fn () => $draft->lines()->create([
        'line_no' => 2, 'part_id' => $part->id, 'quantity' => '2', 'direction' => -1,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('rejects impossible line values', function (array $line, string $constraint) {
    $draft = StockTransaction::factory()->create();

    expect(inSavepoint(fn () => $draft->lines()->create([
        'line_no' => 1, 'part_id' => Part::factory()->create()->id, ...$line,
    ])))->toThrow(QueryException::class, $constraint);
})->with([
    'zero quantity' => [['quantity' => '0', 'direction' => -1], 'stock_transaction_lines_quantity_positive'],
    'direction zero' => [['quantity' => '1', 'direction' => 0], 'stock_transaction_lines_direction_check'],
]);

it('never changes or deletes a ledger movement, and records one opening balance per part', function () {
    $part = Part::factory()->create();
    $opening = StockTransaction::factory()->opening()->create();
    $movement = [
        'part_id' => $part->id,
        'stock_transaction_id' => $opening->id,
        'movement_type' => 'opening',
        'quantity' => '5',
        'balance_after' => '5',
        'occurred_at' => CarbonImmutable::now(),
        'custodian_user_id' => $opening->custodian_user_id,
    ];
    $movementId = DB::table('stock_movements')->insertGetId($movement);

    expect(inSavepoint(fn () => DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => '50'])))
        ->toThrow(QueryException::class, 'stock_movements is append-only')
        ->and(inSavepoint(fn () => DB::table('stock_movements')->where('id', $movementId)->delete()))
        ->toThrow(QueryException::class, 'stock_movements is append-only')
        ->and(inSavepoint(fn () => DB::table('stock_movements')->insert($movement)))
        ->toThrow(UniqueConstraintViolationException::class);
});
