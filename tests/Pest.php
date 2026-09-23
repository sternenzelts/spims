<?php

use App\Domain\Imports\PartImports;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\Role;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Navigation;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Laravel\SerializableClosure\SerializableClosure;
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

/**
 * Post drafts from separate PHP processes at the same moment. A second connection holds the stock
 * rows locked until every process is waiting on them, then lets go, so the postings really contend
 * instead of running one after another. Returns 'completed' or 'insufficient' for each draft.
 *
 * Needs the lock_holder connection configured by the calling test.
 *
 * @param  list<int>  $transactionIds
 * @param  list<int>  $lockedPartIds
 * @return list<string>
 */
function postConcurrently(array $transactionIds, array $lockedPartIds): array
{
    $holder = DB::connection('lock_holder');
    $holder->beginTransaction();
    $holder->table('part_stock')->whereIn('part_id', $lockedPartIds)->lockForUpdate()->get();

    // Children read .env, which points at the development database; send them to this test's database.
    $database = config('database.connections.'.config('database.default').'.database');

    $pool = Process::pool(function (Pool $pool) use ($transactionIds, $database): void {
        foreach ($transactionIds as $transactionId) {
            // No parameters: invoke-serialized-closure calls the SerializableClosure wrapper, whose
            // __invoke() takes none, so the container cannot inject anything into this closure.
            $post = static function () use ($transactionId): string {
                try {
                    $draft = StockTransaction::query()->findOrFail($transactionId);
                    $actor = User::query()->firstOrFail();
                    $ledger = app(StockLedger::class);

                    // A withdrawal carrying supervisor overrides posts through its own path
                    // (Plan 3 Task 7); StockLedger::complete() refuses one.
                    $draft->overrides()->exists()
                        ? $ledger->completeWithOverrides($draft, $actor)
                        : $ledger->complete($draft, $actor);

                    return 'completed';
                } catch (InsufficientStock) {
                    return 'insufficient';
                }
            };

            $pool->path(base_path())
                ->env([
                    'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($post))),
                    'DB_DATABASE' => $database,
                    'SPIMS_LOCK_TIMEOUT' => '30s',
                ])
                ->command([PHP_BINARY, 'artisan', 'invoke-serialized-closure']);
        }
    })->start();

    try {
        $deadline = microtime(true) + 30;
        $waitingOnLocks = "select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'";

        while (DB::scalar($waitingOnLocks) < count($transactionIds)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The posting processes never reached the stock lock.');
            }

            usleep(50_000);
        }
    } finally {
        $holder->rollBack();
    }

    return $pool->wait()->collect()->map(function ($result): string {
        $payload = json_decode($result->output(), true);

        if ($result->failed() || ! is_array($payload)) {
            throw new RuntimeException("Posting process failed:\n".$result->output()."\n".$result->errorOutput());
        }

        if (! $payload['successful']) {
            throw new RuntimeException("Posting process threw {$payload['exception']}: {$payload['message']}");
        }

        return unserialize($payload['result']);
    })->values()->all();
}

/**
 * A user holding the given roles.
 */
function userWithRoles(Role ...$roles): User
{
    $user = User::factory()->create();
    $user->assignRole($roles);

    return $user;
}

/**
 * The four roles, each paired with whether it may perform an action, for role-by-action tests.
 * Holders are written as in RolePermissionMatrixTest: C Custodian, S Supervisor, P Planner,
 * A System Administrator, and a dash for a role that may not.
 *
 * @return array<string, array{0: Role, 1: bool}>
 */
function roleMatrix(string $holders): array
{
    $codes = ['C' => Role::Custodian, 'S' => Role::Supervisor, 'P' => Role::Planner, 'A' => Role::Admin];
    $rows = [];

    foreach ($codes as $code => $role) {
        $rows[$role->label()] = [$role, str_contains($holders, $code)];
    }

    return $rows;
}

/**
 * The labels of the sidebar items a user sees, in menu order.
 *
 * @return list<string>
 */
function menuLabels(User $user): array
{
    return collect(app(Navigation::class)->for($user))->flatten(1)->pluck('label')->values()->all();
}

/**
 * A pending import of this CSV text, uploaded as parts.csv by the actor. Call Storage::fake() first.
 */
function uploadImport(string $csv, User $actor): ImportBatch
{
    return app(PartImports::class)->upload(UploadedFile::fake()->createWithContent('parts.csv', $csv), $actor);
}
