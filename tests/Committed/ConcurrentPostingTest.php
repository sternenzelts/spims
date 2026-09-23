<?php

use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Illuminate\Support\Facades\DB;

// Design §13.2. These tests commit real data and start real PHP processes, so they live in the
// Committed suite. Run `php artisan config:clear` first: child processes must not read a cached
// config that points at the development database.

beforeEach(function () {
    configureInstall();

    // A second connection to the same test database, standing in for another station.
    config(['database.connections.lock_holder' => config('database.connections.'.config('database.default'))]);
});

afterEach(fn () => DB::purge('lock_holder'));

it('lets only one of two simultaneous withdrawals take the last units', function () {
    $part = partWithStock('10');
    $first = StockTransaction::factory()->withLines([[$part, '7']])->create();
    $second = StockTransaction::factory()->withLines([[$part, '7']])->create();

    $results = postConcurrently([$first->id, $second->id], [$part->id]);

    expect($results)->toEqualCanonicalizing(['completed', 'insufficient'])
        ->and(onHand($part))->toBe('3.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('posts overlapping withdrawals entered in opposite line order without deadlocking', function () {
    $bearing = partWithStock('50');
    $belt = partWithStock('50');
    $first = StockTransaction::factory()->withLines([[$bearing, '5'], [$belt, '5']])->create();
    $second = StockTransaction::factory()->withLines([[$belt, '5'], [$bearing, '5']])->create();
    $deadlocks = 'select deadlocks from pg_stat_database where datname = current_database()';
    $deadlocksBefore = DB::scalar($deadlocks);

    $results = postConcurrently([$first->id, $second->id], [$bearing->id, $belt->id]);

    // Laravel retries a transaction that hits a deadlock, so a deadlock would not fail the postings.
    // Postgres counts it anyway. Its statistics arrive a moment after the posting processes exit.
    usleep(1_500_000);
    DB::statement('select pg_stat_clear_snapshot()');

    expect($results)->toBe(['completed', 'completed'])
        ->and(DB::scalar($deadlocks))->toBe($deadlocksBefore)
        ->and(onHand($bearing))->toBe('40.000')
        ->and(onHand($belt))->toBe('40.000')
        ->and(StockMovement::query()->whereIn('stock_transaction_id', [$first->id, $second->id])->count())->toBe(4)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('gives up with StockBusy and writes nothing while another station holds the stock', function () {
    config(['spims.lock_timeout' => '200ms']);
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();
    $custodian = User::factory()->create();

    $holder = DB::connection('lock_holder');
    $holder->beginTransaction();
    $holder->table('part_stock')->where('part_id', $part->id)->lockForUpdate()->get();

    try {
        expect(fn () => app(StockLedger::class)->complete($draft, $custodian))->toThrow(StockBusy::class);
    } finally {
        $holder->rollBack();
    }

    expect($draft->fresh()?->status)->toBe(TransactionStatus::Draft)
        ->and(StockMovement::query()->where('stock_transaction_id', $draft->id)->exists())->toBeFalse();

    app(StockLedger::class)->complete($draft, $custodian);

    expect(onHand($part))->toBe('9.000');
});

it('lets an override withdrawal and a plain withdrawal contend for the same part without losing a unit', function () {
    $part = partWithStock('2');
    $supervisor = User::factory()->create();

    $override = StockTransaction::factory()->withLines([[$part, '5']])->create();
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $override->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
        'recorded_on_hand' => '2.000',
        'authorised_by_user_id' => $supervisor->id,
    ]);
    $plain = StockTransaction::factory()->withLines([[$part, '2']])->create();

    $results = postConcurrently([$override->id, $plain->id], [$part->id]);

    // The override withdrawal always posts: whatever the locked balance turns out to be, the
    // supervisor verified five on the shelf and the adjustment brings the balance up to it. The
    // plain withdrawal takes the two units only if it gets there first.
    expect($results[0])->toBe('completed')
        ->and(onHand($part))->toBe('0.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('posts two override withdrawals on the same part without deadlocking', function () {
    $part = partWithStock('0');
    $supervisor = User::factory()->create();
    $drafts = [];

    foreach ([1, 2] as $each) {
        $draft = StockTransaction::factory()->withLines([[$part, '5']])->create();
        WithdrawalOverride::factory()->create([
            'stock_transaction_id' => $draft->id,
            'part_id' => $part->id,
            'verified_qty' => '5.000',
            'recorded_on_hand' => '0.000',
            'authorised_by_user_id' => $supervisor->id,
        ]);
        $drafts[] = $draft->id;
    }

    $deadlocks = 'select deadlocks from pg_stat_database where datname = current_database()';
    $deadlocksBefore = DB::scalar($deadlocks);

    $results = postConcurrently($drafts, [$part->id]);

    usleep(1_500_000);
    DB::statement('select pg_stat_clear_snapshot()');

    expect($results)->toBe(['completed', 'completed'])
        ->and(DB::scalar($deadlocks))->toBe($deadlocksBefore)
        ->and(onHand($part))->toBe('0.000')
        ->and(StockTransaction::query()->where('type', TransactionType::Adjustment)->count())->toBe(2)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});
