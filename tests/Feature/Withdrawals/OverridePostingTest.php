<?php

use App\Domain\Ledger\Events\StockDiscrepancyReported;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\StockMovedSinceCount;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->custodian = User::factory()->create();
    $this->supervisor = User::factory()->create();
});

/**
 * A pending override on a draft, as WithdrawalService::authoriseOverride() leaves it.
 *
 * The supervisor counts after the stock the test set up was recorded. Timestamps are stored to the
 * second, and a movement recorded in the same second as the authorisation counts as after it, so
 * the clock moves on a second first. Anything the test records afterwards is after the count.
 */
function authorise(StockTransaction $draft, Part $part, string $verified, string $recorded, ReasonCode $reason = ReasonCode::FoundStock): WithdrawalOverride
{
    test()->travel(1)->seconds();

    return WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => $verified,
        'recorded_on_hand' => $recorded,
        'reason_code' => $reason,
        'authorised_by_user_id' => test()->supervisor->id,
    ]);
}

it('posts the adjustment and the withdrawal in one commit', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    $completed = $this->ledger->completeWithOverrides($draft, $this->custodian);

    $adjustment = $override->fresh()->adjustment;

    expect($completed->status)->toBe(TransactionStatus::Completed)
        ->and(onHand($part))->toBe('3.000')
        ->and($adjustment)->not->toBeNull()
        ->and($adjustment->type)->toBe(TransactionType::Adjustment)
        ->and($adjustment->reason_code)->toBe(ReasonCode::FoundStock)
        ->and($adjustment->lines()->firstOrFail()->quantity)->toBe('5.000')
        ->and($adjustment->lines()->firstOrFail()->direction)->toBe(1)
        ->and($override->fresh()->discarded_at)->toBeNull()
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('stamps the adjustment movement at the corrected balance and the withdrawal below it', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    authorise($draft, $part, '8', '3');

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    $balances = StockMovement::query()->where('part_id', $part->id)->orderBy('id')->pluck('balance_after')->all();

    // Opening 3, adjustment to 8, withdrawal of 5 down to 3.
    expect($balances)->toBe(['3.000', '8.000', '3.000']);
});

it('numbers the adjustment before the withdrawal', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    // After the opening balance, so its own number is not in the log.
    DB::enableQueryLog();
    $completed = $this->ledger->completeWithOverrides($draft, $this->custodian);

    // Each prefix has its own sequence row, so the numbers alone cannot show the order. The order
    // the rows are taken in is also what keeps two postings from deadlocking on them (review A).
    $numbered = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'insert into transaction_number_sequences'))
        ->map(fn (array $entry): mixed => $entry['bindings'][0])
        ->values()
        ->all();

    expect($numbered)->toBe(['AJ', 'WD'])
        ->and($override->fresh()->adjustment->transaction_no)->toStartWith('AJ-')
        ->and($completed->transaction_no)->toStartWith('WD-');
});

it('never lets an override lower a balance, whatever its verified quantity', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    // Only inconsistent data reaches this: authoriseOverride() refuses a count below the line, and
    // raising a line past its count removes the override. The locked 3 sits between the count of 2
    // and the line of 5, so the adjustment would be -1.
    authorise($draft, $part, '2', '3');

    expect(fn () => $this->ledger->completeWithOverrides($draft, $this->custodian))
        ->toThrow(InsufficientStock::class);

    expect($draft->fresh()->status)->toBe(TransactionStatus::Draft)
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0)
        ->and(onHand($part))->toBe('3.000');
});

it('computes the adjustment from the locked balance, not the one the supervisor saw', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    // recorded_on_hand is only what the screen showed the supervisor, so it is never trusted.
    $override = authorise($draft, $part, '8', '1');

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    // The shelf holds 8 and the locked balance is 3, so the adjustment is 5 — not the 7 that
    // 8 - recorded_on_hand would have given.
    expect($override->fresh()->adjustment->lines()->firstOrFail()->quantity)->toBe('5.000')
        ->and(onHand($part))->toBe('3.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('refuses an override whose part moved after the supervisor counted it', function (Closure $move, string $left) {
    $part = partWithStock('3', ['part_number' => 'BRG-6204']);
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    // Recorded after the count, so the count of 8 no longer describes the shelf.
    $move($part);

    expect(fn () => $this->ledger->completeWithOverrides($draft, $this->custodian))
        ->toThrow(StockMovedSinceCount::class, 'Stock of BRG-6204 moved since the supervisor counted it');

    expect($draft->fresh()->status)->toBe(TransactionStatus::Draft)
        ->and($override->fresh()->isPending())->toBeTrue()
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0)
        ->and(onHand($part))->toBe($left)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
})->with([
    // A delivery the shelf count may or may not have included: adjusting to 8 against 4 could lose
    // the delivery from the record.
    'a delivery that leaves the line short' => [
        fn (Part $part) => test()->ledger->complete(StockTransaction::factory()->replenishment()->withLines([[$part, '1']])->create(), test()->custodian),
        '4.000',
    ],
    // A withdrawal the count had already seen: adjusting to 8 against 1 would record 2 that are not
    // on the shelf.
    'another withdrawal' => [
        fn (Part $part) => test()->ledger->complete(StockTransaction::factory()->withLines([[$part, '2']])->create(), test()->custodian),
        '1.000',
    ],
]);

it('refuses the second of two overrides counted on one shelf, instead of adjusting to it twice', function () {
    $part = partWithStock('0', ['part_number' => 'BLT-100']);
    $first = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $second = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    // One supervisor counts five on the shelf, once for each of two stations.
    authorise($first, $part, '5', '0');
    authorise($second, $part, '5', '0');

    $this->ledger->completeWithOverrides($first, $this->custodian);

    expect(fn () => $this->ledger->completeWithOverrides($second, $this->custodian))
        ->toThrow(StockMovedSinceCount::class, 'BLT-100');

    expect(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(1)
        ->and(onHand($part))->toBe('0.000')
        ->and($second->fresh()->status)->toBe(TransactionStatus::Draft)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('discards the override when the locked balance already covers the line', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    // Recorded after the count, but a covered line discards its override before the count's age
    // is checked: the withdrawal needs no adjustment at all.
    $delivery = StockTransaction::factory()->replenishment()->withLines([[$part, '10']])->create();
    $this->ledger->complete($delivery, $this->custodian);

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    expect($override->fresh()->discarded_at)->not->toBeNull()
        ->and($override->fresh()->adjustment_transaction_id)->toBeNull()
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0)
        ->and(onHand($part))->toBe('8.000')
        ->and(AuditLog::query()->where('event', 'override_discarded')->count())->toBe(1);
});

it('reports a discrepancy whether the override was posted or discarded', function (?string $delivered, bool $expectedAdjusted) {
    Event::fake([StockDiscrepancyReported::class]);
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    if ($delivered !== null) {
        $delivery = StockTransaction::factory()->replenishment()->withLines([[$part, $delivered]])->create();
        $this->ledger->complete($delivery, $this->custodian);
    }

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    Event::assertDispatched(StockDiscrepancyReported::class, fn (StockDiscrepancyReported $event): bool => $event->partId === $part->id
        && $event->withdrawalOverrideId === $override->id
        && $event->withdrawalTransactionId === $draft->id
        && $event->wasAdjusted === $expectedAdjusted);
})->with([
    'posted' => [null, true],
    'discarded' => ['10', false],
]);

it('announces no discrepancy when the caller rolls the posting back', function () {
    $announced = 0;
    Event::listen(StockDiscrepancyReported::class, function () use (&$announced): void {
        $announced++;
    });
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    authorise($draft, $part, '8', '3');

    try {
        DB::transaction(function () use ($draft): void {
            $this->ledger->completeWithOverrides($draft, $this->custodian);

            throw new RuntimeException('The caller failed after posting.');
        });
    } catch (RuntimeException) {
        // Expected: the caller's transaction rolled back, taking the posting with it.
    }

    expect($announced)->toBe(0)
        ->and(onHand($part))->toBe('3.000')
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0);

    // The listener does hear a posting that stays committed, so the zero above is not vacuous.
    $this->ledger->completeWithOverrides($draft, $this->custodian);

    expect($announced)->toBe(1);
});

it('posts one adjustment per overridden line, each with its own reason', function () {
    $bearing = partWithStock('1');
    $belt = partWithStock('2');
    $draft = StockTransaction::factory()->withLines([[$bearing, '4'], [$belt, '6']])->create(['custodian_user_id' => $this->custodian->id]);
    authorise($draft, $bearing, '4', '1', ReasonCode::FoundStock);
    authorise($draft, $belt, '6', '2', ReasonCode::UnrecordedReceipt);

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    $adjustments = StockTransaction::query()->where('type', TransactionType::Adjustment)->get();

    expect($adjustments)->toHaveCount(2)
        ->and($adjustments->pluck('reason_code')->map->value->sort()->values()->all())->toBe(['found_stock', 'unrecorded_receipt'])
        ->and(onHand($bearing))->toBe('0.000')
        ->and(onHand($belt))->toBe('0.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('writes nothing at all when another line is short', function () {
    $overridden = partWithStock('1');
    $short = partWithStock('1');
    $draft = StockTransaction::factory()->withLines([[$overridden, '4'], [$short, '9']])->create(['custodian_user_id' => $this->custodian->id]);
    authorise($draft, $overridden, '4', '1');

    expect(fn () => $this->ledger->completeWithOverrides($draft, $this->custodian))
        ->toThrow(InsufficientStock::class);

    expect($draft->fresh()->status)->toBe(TransactionStatus::Draft)
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0)
        ->and(onHand($overridden))->toBe('1.000')
        ->and(onHand($short))->toBe('1.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('posts a withdrawal with no overrides exactly as complete() does', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '4']])->create(['custodian_user_id' => $this->custodian->id]);

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    expect(onHand($part))->toBe('6.000')
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0);
});

it('refuses, instead of failing on a constraint, a withdrawal whose identification was cleared after review', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '4']])->create([
        'custodian_user_id' => $this->custodian->id,
        'identified_at' => CarbonImmutable::now(),
    ]);
    // WithdrawalService::guardReadyToConfirm() passed on this copy; then another tab pressed
    // "Not this person".
    $reviewed = $draft->fresh();
    app(WithdrawalIdentification::class)->clear($draft);

    expect(fn () => $this->ledger->completeWithOverrides($reviewed, $this->custodian))
        ->toThrow(WithdrawalRefused::class, 'scan their badge');

    expect($draft->fresh()->status)->toBe(TransactionStatus::Draft)
        ->and(onHand($part))->toBe('10.000');
});

it('refuses a withdrawal whose identification expired before the posting took its locks', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '4']])->create([
        'custodian_user_id' => $this->custodian->id,
        'identified_at' => CarbonImmutable::now(),
    ]);

    $this->travel(app(WithdrawalIdentification::class)->timeoutSeconds() + 1)->seconds();

    expect(fn () => $this->ledger->completeWithOverrides($draft, $this->custodian))
        ->toThrow(WithdrawalRefused::class, 'expired');

    expect($draft->fresh()->status)->toBe(TransactionStatus::Draft)
        ->and(onHand($part))->toBe('10.000');
});

it('refuses to post a draft holding overrides through complete()', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    authorise($draft, $part, '8', '3');

    expect(fn () => $this->ledger->complete($draft, $this->custodian))
        ->toThrow(LogicException::class, 'completeWithOverrides');
});

it('refuses to post anything but a withdrawal through completeWithOverrides()', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->replenishment()->withLines([[$part, '5']])->create();

    expect(fn () => $this->ledger->completeWithOverrides($draft, $this->custodian))
        ->toThrow(LogicException::class, 'replenishment');
});
