<?php

use App\Domain\Ledger\TransactionNumberer;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// RefreshDatabase already wraps each test in a transaction, which the numberer requires.

it('numbers each type in sequence with its prefix and year', function () {
    $numberer = app(TransactionNumberer::class);
    $at = CarbonImmutable::parse('2026-09-14 02:00:00', 'UTC');

    expect($numberer->next(TransactionType::Withdrawal, $at))->toBe('WD-2026-000001')
        ->and($numberer->next(TransactionType::Withdrawal, $at))->toBe('WD-2026-000002')
        ->and($numberer->next(TransactionType::Replenishment, $at))->toBe('RP-2026-000001');
});

it('takes the year from the install time zone', function () {
    // 16:30 UTC on 31 December is already 00:30 on 1 January in Manila.
    $newYearInManila = CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC');

    expect(app(TransactionNumberer::class)->next(TransactionType::Withdrawal, $newYearInManila))->toBe('WD-2027-000001');
});

it('starts again at one each year', function () {
    $numberer = app(TransactionNumberer::class);

    $numberer->next(TransactionType::Withdrawal, CarbonImmutable::parse('2026-06-01', 'UTC'));
    $numberer->next(TransactionType::Withdrawal, CarbonImmutable::parse('2026-07-01', 'UTC'));

    expect($numberer->next(TransactionType::Withdrawal, CarbonImmutable::parse('2027-02-01', 'UTC')))->toBe('WD-2027-000001');
});

it('uses the prefixes from the install settings', function () {
    app(InstallSettings::class)->set(
        'numbering.prefixes',
        ['withdrawal' => 'ISS', 'replenishment' => 'RCV', 'adjustment' => 'ADJ', 'opening' => 'OPN'],
        User::factory()->create(),
    );

    expect(app(TransactionNumberer::class)->next(TransactionType::Adjustment, CarbonImmutable::parse('2026-09-14', 'UTC')))
        ->toBe('ADJ-2026-000001');
});

it('gives the number back when the posting rolls back', function () {
    $numberer = app(TransactionNumberer::class);
    $at = CarbonImmutable::parse('2026-09-14 02:00:00', 'UTC');

    try {
        DB::transaction(function () use ($numberer, $at) {
            expect($numberer->next(TransactionType::Withdrawal, $at))->toBe('WD-2026-000001');

            throw new RuntimeException('The posting failed after numbering.');
        });
    } catch (RuntimeException) {
        // Expected: the savepoint rolled back.
    }

    expect($numberer->next(TransactionType::Withdrawal, $at))->toBe('WD-2026-000001');
});
