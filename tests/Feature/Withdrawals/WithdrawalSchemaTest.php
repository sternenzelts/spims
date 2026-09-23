<?php

use App\Enums\ReasonCode;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('records when a draft was identified', function () {
    $draft = StockTransaction::factory()->create();

    $draft->forceFill(['identified_at' => CarbonImmutable::now()])->save();

    expect($draft->fresh()->identified_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('refuses an identification time on a draft with no personnel', function () {
    $draft = StockTransaction::factory()->create(['personnel_id' => null]);

    expect(inSavepoint(fn () => $draft->forceFill(['identified_at' => CarbonImmutable::now()])->save()))
        ->toThrow(QueryException::class);
});

it('holds one override per part on a draft', function () {
    $draft = StockTransaction::factory()->create();
    $part = Part::factory()->create();
    WithdrawalOverride::factory()->create(['stock_transaction_id' => $draft->id, 'part_id' => $part->id]);

    expect(inSavepoint(fn () => WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('allows only found stock and unrecorded receipt as an override reason', function (ReasonCode $reason, bool $allowed) {
    $override = fn () => WithdrawalOverride::factory()->create(['reason_code' => $reason]);

    $allowed
        ? expect($override()->reason_code)->toBe($reason)
        : expect(inSavepoint($override))->toThrow(QueryException::class);
})->with([
    'found stock' => [ReasonCode::FoundStock, true],
    'unrecorded receipt' => [ReasonCode::UnrecordedReceipt, true],
    'miscount' => [ReasonCode::Miscount, false],
    'shrinkage' => [ReasonCode::Shrinkage, false],
]);

it('refuses an override that is both posted and discarded', function () {
    $adjustment = StockTransaction::factory()->adjustment()->create();

    expect(inSavepoint(fn () => WithdrawalOverride::factory()->create([
        'adjustment_transaction_id' => $adjustment->id,
        'discarded_at' => CarbonImmutable::now(),
    ])))->toThrow(QueryException::class);
});

it('refuses a verified quantity of zero or less', function () {
    expect(inSavepoint(fn () => WithdrawalOverride::factory()->create(['verified_qty' => '0'])))
        ->toThrow(QueryException::class);
});

it("removes a draft's overrides with the draft", function () {
    $override = WithdrawalOverride::factory()->create();

    $override->transaction->delete();

    expect(WithdrawalOverride::query()->count())->toBe(0);
});

it('names the authorising supervisor and the balance they saw', function () {
    $supervisor = User::factory()->create();

    $override = WithdrawalOverride::factory()->create([
        'authorised_by_user_id' => $supervisor->id,
        'recorded_on_hand' => '2.500',
        'verified_qty' => '9.000',
    ]);

    expect($override->authorisedBy->is($supervisor))->toBeTrue()
        ->and($override->recorded_on_hand)->toBe('2.500')
        ->and($override->verified_qty)->toBe('9.000')
        ->and($override->isPending())->toBeTrue();
});
