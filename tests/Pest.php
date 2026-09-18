<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Tests whose writes must really commit: another connection or process has to see them, or the code
// under test must run outside a wrapping transaction. They truncate tables instead of rolling back.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Committed');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Wrap a statement that is expected to fail in a savepoint. Postgres aborts the whole test
 * transaction after an error, so without this every later query in the test fails too.
 */
function inSavepoint(Closure $callback): Closure
{
    return fn () => DB::transaction($callback);
}

/**
 * Set the one setting an install needs before it can record stock (PRD FR-SPIMS-018).
 */
function configureInstall(): void
{
    app(InstallSettings::class)->set('company.name', 'Microtech Industrial Solutions, Inc.', User::factory()->create());
}

/**
 * A part whose opening balance was posted through the ledger. Call configureInstall() first.
 *
 * @param  array<string, mixed>  $attributes
 */
function partWithStock(string $onHand, array $attributes = []): Part
{
    $part = Part::factory()->create($attributes);

    if (BigDecimal::of($onHand)->isZero()) {
        return $part;
    }

    $opening = StockTransaction::factory()->opening()->withLines([[$part, $onHand]])->create();
    app(StockLedger::class)->complete($opening, $opening->custodian);

    return $part;
}

/**
 * The part's current stock balance, read fresh from the database.
 */
function onHand(Part $part): ?string
{
    return PartStock::query()->find($part->id)?->on_hand;
}
