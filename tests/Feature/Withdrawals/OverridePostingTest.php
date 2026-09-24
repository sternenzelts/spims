<?php

use App\Domain\Ledger\Events\StockDiscrepancyReported;
use App\Domain\Ledger\Exceptions\InsufficientStock;
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
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->custodian = User::factory()->create();
    $this->supervisor = User::factory()->create();
});

/**
 * A pending override on a draft, as WithdrawalService::authoriseOverride() leaves it.
 */
function authorise(StockTransaction $draft, Part $part, string $verified, string $recorded, ReasonCode $reason = ReasonCode::FoundStock): WithdrawalOverride
{
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

    $completed = $this->ledger->completeWithOverrides($draft, $this->custodian);

    expect($override->fresh()->adjustment->transaction_no)->toStartWith('AJ-')
        ->and($completed->transaction_no)->toStartWith('WD-');
});

it('computes the adjustment from the locked balance, not the one the supervisor saw', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    // A replenishment of 1 commits between the authorisation and the confirmation.
    $delivery = StockTransaction::factory()->replenishment()->withLines([[$part, '1']])->create();
    $this->ledger->complete($delivery, $this->custodian);

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    // The shelf holds 8, the system now holds 4, so the adjustment is 4 — not the 5 that
    // 8 - recorded_on_hand would have given.
    expect($override->fresh()->adjustment->lines()->firstOrFail()->quantity)->toBe('4.000')
        ->and(onHand($part))->toBe('3.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('discards the override when the locked balance already covers the line', function () {
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    $delivery = StockTransaction::factory()->replenishment()->withLines([[$part, '10']])->create();
    $this->ledger->complete($delivery, $this->custodian);

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    expect($override->fresh()->discarded_at)->not->toBeNull()
        ->and($override->fresh()->adjustment_transaction_id)->toBeNull()
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(0)
        ->and(onHand($part))->toBe('8.000')
        ->and(AuditLog::query()->where('event', 'override_discarded')->count())->toBe(1);
});

it('reports a discrepancy whether the override was posted or discarded', function (string $delivered, bool $expectedAdjusted) {
    Event::fake([StockDiscrepancyReported::class]);
    $part = partWithStock('3');
    $draft = StockTransaction::factory()->withLines([[$part, '5']])->create(['custodian_user_id' => $this->custodian->id]);
    $override = authorise($draft, $part, '8', '3');

    $delivery = StockTransaction::factory()->replenishment()->withLines([[$part, $delivered]])->create();
    $this->ledger->complete($delivery, $this->custodian);

    $this->ledger->completeWithOverrides($draft, $this->custodian);

    Event::assertDispatched(StockDiscrepancyReported::class, fn (StockDiscrepancyReported $event): bool => $event->partId === $part->id
        && $event->withdrawalOverrideId === $override->id
        && $event->withdrawalTransactionId === $draft->id
        && $event->wasAdjusted === $expectedAdjusted);
})->with([
    'posted' => ['1', true],
    'discarded' => ['10', false],
]);

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
