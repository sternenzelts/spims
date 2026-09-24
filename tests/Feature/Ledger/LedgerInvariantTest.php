<?php

use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/*
 * Design §13.1: the single most valuable test in the system. A seeded random sequence of
 * withdrawals, replenishments, adjustments and backdated entries, checked against an independent
 * model of what every balance should be. A failure names its seed, so it can be replayed exactly.
 * Plan 4 adds reversals and count postings to the generator.
 */
it('keeps every balance equal to its ledger through random postings and backdated entries', function (int $seed) {
    configureInstall();
    mt_srand($seed);

    $ledger = app(StockLedger::class);
    $custodian = User::factory()->create();
    $parts = Part::factory()->count(4)->create()->all();
    $expected = [];

    foreach ($parts as $part) {
        $expected[$part->id] = BigDecimal::of(mt_rand(1, 40));
        $ledger->complete(
            StockTransaction::factory()->opening()->withLines([[$part, (string) $expected[$part->id]]])->create(),
            $custodian,
        );
    }

    // A supervisor's count is refused if stock of its part was recorded moving at or after it, and
    // timestamps are stored to the second: the first step must not share a second with the openings.
    $this->travel(1)->minutes();

    for ($step = 1; $step <= 120; $step++) {
        $type = [TransactionType::Withdrawal, TransactionType::Replenishment, TransactionType::Adjustment][mt_rand(0, 2)];
        $chosen = $parts;
        shuffle($chosen);
        $chosen = array_slice($chosen, 0, mt_rand(1, 3));

        $lines = [];
        $wouldGoNegative = false;

        foreach ($chosen as $part) {
            $quantity = BigDecimal::of(mt_rand(1, 12_000))->dividedBy(1000, 3);
            $direction = $type->direction() ?? (mt_rand(0, 1) === 1 ? 1 : -1);
            $lines[] = [$part, (string) $quantity, $direction];
            $wouldGoNegative = $wouldGoNegative
                || $expected[$part->id]->plus($quantity->multipliedBy($direction))->isNegative();
        }

        $factory = match ($type) {
            TransactionType::Replenishment => StockTransaction::factory()->replenishment(),
            TransactionType::Adjustment => StockTransaction::factory()->adjustment(),
            default => StockTransaction::factory(),
        };

        if (mt_rand(1, 5) === 1) {
            // A paper-fallback entry for stock that moved hours or days before rows already recorded.
            $factory = $factory->backdated(CarbonImmutable::now()->subMinutes(mt_rand(60, 60 * 24 * 10)));
        }

        $draft = $factory->withLines($lines)->create(['custodian_user_id' => $custodian->id]);

        // Design §5.3: one short withdrawal in three is issued anyway, after a supervisor counts
        // the shelf and authorises every short line. Two transactions per posting, one lock
        // acquisition. The count is the line or up to three more, so an overridden part lands on
        // what the count left — on zero, the boundary the on_hand >= 0 constraint guards, when
        // the count is exactly the line. A line the balance covers sometimes carries an override
        // too, which the posting discards.
        if ($type === TransactionType::Withdrawal && $wouldGoNegative && mt_rand(1, 3) === 1) {
            $verified = [];

            foreach ($lines as [$part, $quantity]) {
                $isShort = $expected[$part->id]->isLessThan($quantity);

                if (! $isShort && mt_rand(0, 1) === 0) {
                    continue;
                }

                $count = BigDecimal::of($quantity)->plus(mt_rand(0, 3));

                if ($isShort) {
                    $verified[$part->id] = $count;
                }

                WithdrawalOverride::factory()->create([
                    'stock_transaction_id' => $draft->id,
                    'part_id' => $part->id,
                    'verified_qty' => (string) $count,
                    'recorded_on_hand' => (string) $expected[$part->id],
                    'authorised_by_user_id' => $custodian->id,
                ]);
            }

            $ledger->completeWithOverrides($draft, $custodian);

            foreach ($lines as [$part, $quantity]) {
                $expected[$part->id] = isset($verified[$part->id])
                    ? $verified[$part->id]->minus($quantity)
                    : $expected[$part->id]->minus($quantity);
            }

            $this->travel(mt_rand(1, 240))->minutes();

            continue;
        }

        try {
            $ledger->complete($draft, $custodian);
            expect($wouldGoNegative)->toBeFalse("Seed {$seed}, step {$step}: posted although stock was short.");

            foreach ($lines as [$part, $quantity, $direction]) {
                $expected[$part->id] = $expected[$part->id]->plus(BigDecimal::of($quantity)->multipliedBy($direction));
            }
        } catch (InsufficientStock) {
            expect($wouldGoNegative)->toBeTrue("Seed {$seed}, step {$step}: refused although stock was enough.");
        }

        $this->travel(mt_rand(1, 240))->minutes();
    }

    expect(app(LedgerVerifier::class)->verify())->toBe([]);

    foreach ($parts as $part) {
        expect(onHand($part))->toBe((string) $expected[$part->id]->toScale(3), "Seed {$seed}: {$part->part_number}");
    }

    // Independently of the verifier's SQL: balance_after is the running sum in id order (design §4.5).
    $running = [];

    foreach (StockMovement::query()->orderBy('id')->get() as $movement) {
        $running[$movement->part_id] = ($running[$movement->part_id] ?? BigDecimal::zero())->plus($movement->quantity);
        expect($movement->balance_after)->toBe((string) $running[$movement->part_id]->toScale(3), "Seed {$seed}: movement {$movement->id}");
    }

    // The sequence really exercised backdating: occurred_at order differs from recording order.
    $occurred = StockMovement::query()->orderBy('id')->pluck('occurred_at');
    expect($occurred->sort()->values()->all())->not->toEqual($occurred->all());

    // And both outcomes of an override: an adjustment posted, and an override discarded.
    expect(WithdrawalOverride::query()->whereNotNull('adjustment_transaction_id')->exists())->toBeTrue("Seed {$seed}: no override posted")
        ->and(WithdrawalOverride::query()->whereNotNull('discarded_at')->exists())->toBeTrue("Seed {$seed}: no override discarded");
})->with([1, 2, 3]);
