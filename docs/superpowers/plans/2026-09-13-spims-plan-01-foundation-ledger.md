# SPIMS Plan 1: Foundation and Stock Ledger — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel 13 application on PostgreSQL with sign-in, the four SPIMS roles, install settings, the configuration audit trail, the master data and personnel schema, and a proven stock ledger: `StockLedger::complete()` posts any draft transaction atomically, and randomized, concurrent and nightly checks prove every balance equals its ledger.

**Architecture:** A Laravel monolith (design §3.1). Domain code lives in `app/Domain/{Audit,Settings,Identity,Ledger}` and Eloquent models in `app/Models`. `stock_movements` is an append-only ledger of signed quantities and `part_stock` is its cache and row-lock target. Only `App\Domain\Ledger\StockLedger` writes stock, in one short database transaction that locks stock rows in part id order. This plan builds no screens beyond the starter kit's sign-in and profile pages; Plans 2–7 in the roadmap build on it.

**Tech Stack:** PHP 8.4+, Laravel 13.31, Livewire 4 + Flux 2 starter kit (Fortify authentication), spatie/laravel-permission 8.3, brick/math 0.18, PostgreSQL 17, Pest 5, Larastan (PHPStan level 7), Pint.

**Related documents:** roadmap `docs/superpowers/plans/2026-09-13-spims-microtech-roadmap.md` · design `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md` · requirements `docs/prd/Microtech_SPIMS_PRD_v1.0.md`

## Global Constraints

- **PHP `^8.4`.** Laravel 13 accepts 8.3, but Pest 5 requires 8.4. Do not lower the Composer constraint. CI runs PHP 8.4 and 8.5.
- **Package floors:** Laravel `^13.31`; Livewire `^4.1` class components, Flux `^2.13` and Fortify `^1.37` from the starter kit; spatie/laravel-permission `^8.3`; brick/math `^0.18`.
- **PostgreSQL 17** for development, tests and CI. Never SQLite: the design depends on `FOR UPDATE`, partial indexes, triggers and CHECK constraints (design §13). Supabase hosting for Microtech's installs comes in Plan 7.
- **`composer test` passes before every commit.** It runs Pint (`laravel` preset), PHPStan level 7 over `app/ bootstrap/app.php config/ database/ routes/`, then the whole Pest suite. Never add `@phpstan-ignore` comments or baseline entries.
- **The pgsql connection** uses `PDO::ATTR_EMULATE_PREPARES => true` (design §12.1) and `'timezone' => 'UTC'`. Laravel writes datetimes without an offset, so **convert every datetime to UTC (`->utc()`) before it reaches the database.** The app timezone stays `UTC`. The install time zone (setting `regional.timezone`, default `Asia/Manila`) is for display, reporting periods and the year in transaction numbers.
- **Schema conventions (design §4.2):**
  - `text`, never `varchar`.
  - `timestamptz` (`timestampTz`).
  - `numeric(14,3)` for quantities.
  - `text` + `CHECK` instead of enums.
  - Explicit indexes named as in design §4.3.
  - Primary keys are `bigserial` via `$table->id()`. Laravel's grammar does not emit `generated always as identity`, and the design doc is amended to match.
- **Quantities are decimal strings in PHP** (`decimal:3` casts), and all arithmetic uses `Brick\Math\BigDecimal`. Never cast a quantity to `float` or `int`.
- **The ledger invariant:** for every part, `part_stock.on_hand = SUM(stock_movements.quantity)`, and each `balance_after` is the running sum for its part **in `id` order** (design §4.1, §4.5). Only `StockLedger` writes `part_stock.on_hand` or `stock_movements`.
- **Append-only tables** (`stock_movements`, `audit_logs`) reject every UPDATE and DELETE with a trigger.
- **Lock order:** `part_stock` rows are locked in one statement, `ORDER BY part_id FOR UPDATE`. The transaction-number sequence row is always locked last.
- **No company-specific values in code** (design §3.6): read them from `InstallSettings`. The install type (demo or production) is environment configuration, never a setting (added in Plan 2).
- **The morph map is enforced:** every model that is audited or holds roles is listed in `AppServiceProvider::configureMorphMap()`.
- **Tests:**
  - Use Pest function syntax.
  - A test that expects a database error wraps the failing call in `inSavepoint()`. Otherwise Postgres aborts the surrounding test transaction and every later query fails.
  - Tests whose writes must commit live in `tests/Committed`.
- **Commits:** one per task, with the message given in the task, staged by path. Never `git add -A` or `git add .`.

## Before You Start

Read these first:

- Design doc: §3.3–§3.6, all of §4, §5.1–§5.3, §8.2, §12.1 and §13.
- PRD: FR-SPIMS-004, the validation rules of FR-SPIMS-007, FR-SPIMS-018 and the §8 permissions matrix.

Commands are written for Git Bash on Windows (the development machine) and work unchanged on macOS and Linux.

**How the code in this plan was checked.** Before publishing:

- Pint and PHPStan level 7 passed over all application code.
- Every migration and runtime SQL statement parsed as PostgreSQL.
- The database-free unit tests passed.
- Pest loaded the full suite.

The database tests were not run, because the authoring machine had no PostgreSQL. Run each task's tests exactly as written. If a result differs from the expected output, fix the code before committing.

## File Map

```
app/
  Console/Commands/
    CreateAdministrator.php        spims:create-admin — provisioning checklist step 4     (Task 3)
    VerifyLedger.php               ledger:verify — nightly invariant check                (Task 10)
  Domain/
    Audit/AuditLogger.php          append to audit_logs                                   (Task 2)
    Settings/InstallSettings.php   per-install settings with defaults                     (Task 4)
    Identity/                      BadgeReader, KeyboardWedgeBadgeReader, BadgeIdentity,
                                   PersonnelBadges, Exceptions/                           (Task 6)
    Ledger/                        TransactionNumberer (8), StockLedger, Shortage,
                                   Exceptions/, Events/ (9), LedgerVerifier,
                                   LedgerDiscrepancy, DiscrepancyKind (10)
  Enums/                           Role, Permission (3), TransactionType, MovementType (4),
                                   Criticality (5), TransactionStatus, ReasonCode,
                                   IdentificationMethod (7)
  Http/Middleware/CaptureAuditContext.php                                                 (Task 2)
  Models/                          AuditLog (2), Setting, WithdrawalPurpose (4),
                                   StorageLocation, Equipment, Part, PartStock (5),
                                   Personnel (6), StockTransaction, StockTransactionLine,
                                   StockMovement (7)
config/spims.php                   badge pepper, lock timeout, nightly time               (Tasks 6, 9, 10)
database/migrations/2026_09_14_1000NN_*.php                                               (Tasks 2, 4–8)
database/seeders/                  RolesAndPermissionsSeeder (3), WithdrawalPurposeSeeder (4)
database/factories/                StorageLocation, Equipment, Part (5), Personnel (6),
                                   StockTransaction (7)
tests/Pest.php                     suite bindings and shared helpers
tests/Unit/                        pure PHP, no database
tests/Feature/                     RefreshDatabase: each test rolls back
tests/Committed/                   DatabaseTruncation: writes commit (Tasks 8, 11)
```

---

## Task 1: Scaffold on PostgreSQL, with CI

**Files:**
- Create: the Laravel 13 Livewire starter kit, copied into the repository root
- Create: `tests/Feature/DatabaseConnectionTest.php`, `tests/Feature/Auth/RegistrationDisabledTest.php`, `.github/workflows/tests.yml` (replaces the scaffold's)
- Modify: `composer.json`, `.env`, `.env.example`, `phpunit.xml`, `config/database.php`, `config/fortify.php`, `resources/views/livewire/auth/login.blade.php`, `tests/Pest.php`, `tests/TestCase.php`, `database/seeders/DatabaseSeeder.php`, `.gitignore`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - A working `composer test`.
  - The `spims` and `spims_test` databases.
  - The Feature suite bound to `RefreshDatabase`.
  - `Tests\TestCase` marked `#[Seed]`, so every test database runs `DatabaseSeeder`.

- [ ] **Step 1: Install PostgreSQL 17 and create the two databases**

```bash
winget install --id PostgreSQL.PostgreSQL.17 -e
```

The installer asks for a password for the `postgres` superuser. Keep it for `.env`. Then run:

```bash
"/c/Program Files/PostgreSQL/17/bin/psql" -U postgres -c "create database spims" -c "create database spims_test"
```

Expected: `CREATE DATABASE` printed twice.

- [ ] **Step 2: Scaffold the starter kit outside the repository**

`laravel new --force` **deletes the target directory**, which would destroy `docs/` and `.git`. Scaffold next to the repository instead:

```bash
cd "C:/Users/hp"
laravel new spims-scaffold --livewire --livewire-class-components --pest --database=pgsql --no-boost --no-interaction
```

Expected: `Application ready`. If the installer reports that it could not run migrations, ignore it. Step 8 points the app at the right database.

- [ ] **Step 3: Copy the scaffold into the repository**

```bash
cd "C:/Users/hp/Inventory Microtech"
rm -rf ../spims-scaffold/.git
cp -r ../spims-scaffold/. .
printf '.DS_Store\nThumbs.db\n' >> .gitignore
rm -rf ../spims-scaffold
ls
```

Expected: `app artisan bootstrap composer.json … docs …`, with `docs/` still present. The scaffold's `.gitignore` replaces the repository's. It already covers `vendor`, `node_modules`, `.env` and build output, and the command above re-adds the two operating-system entries.

- [ ] **Step 4: Raise the PHP floor to Pest's requirement**

In `composer.json`, change `"php": "^8.3"` to `"php": "^8.4"`, then refresh the lock file's hash:

```bash
composer update --lock
```

Expected: `Lock file operations: 0 installs, 0 updates, 0 removals`.

- [ ] **Step 5: Configure the environment files**

In `.env`, set these lines, using the password from Step 1:

```dotenv
APP_NAME=SPIMS
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=spims
DB_USERNAME=postgres
DB_PASSWORD=your-local-postgres-password
```

Make the same changes in `.env.example`, but leave `DB_PASSWORD=` empty.

- [ ] **Step 6: Write the failing tests and bind the Feature suite to a refreshed database**

Replace `tests/Pest.php` with:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

Replace `tests/TestCase.php` with:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\Attributes\Seed;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

/**
 * Every test database is seeded with the install defaults: roles, permissions and withdrawal purposes.
 */
#[Seed]
abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
```

Replace the body of `database/seeders/DatabaseSeeder.php` so it no longer creates a test user:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Install defaults every company gets (provisioning checklist step 3). Never seeds users,
     * company details or sample data: those are provisioning steps of their own.
     */
    public function run(): void
    {
        //
    }
}
```

Create `tests/Feature/DatabaseConnectionTest.php`:

```php
<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('runs the suite on PostgreSQL with emulated prepares, as on the Supabase pooler', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(config('database.connections.pgsql.options'))->toHaveKey(PDO::ATTR_EMULATE_PREPARES, true);
});

it('keeps the database session in UTC so saved instants are not shifted', function () {
    $manilaMorning = CarbonImmutable::parse('2026-09-14 08:30:00', 'Asia/Manila');

    expect(DB::scalar('show timezone'))->toBe('UTC')
        ->and(DB::scalar('select cast(? as timestamptz) = cast(? as timestamptz)', [
            $manilaMorning->utc(),
            '2026-09-14 00:30:00+00',
        ]))->toBeTrue();
});
```

Create `tests/Feature/Auth/RegistrationDisabledTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

it('does not offer self-registration: System Administrators create accounts', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
});

it('still shows the sign-in page, without a sign-up link', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up');
});
```

- [ ] **Step 7: Run the new tests to verify they fail**

```bash
php artisan config:clear && php artisan test tests/Feature/DatabaseConnectionTest.php tests/Feature/Auth/RegistrationDisabledTest.php
```

Expected: FAIL. The driver is `sqlite`, because `phpunit.xml` still selects in-memory SQLite, and `Route::has('register')` is `true`.

- [ ] **Step 8: Point tests at PostgreSQL, configure the connection and turn off self-registration**

In `phpunit.xml`, replace

```xml
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
```

with

```xml
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_DATABASE" value="spims_test"/>
```

In `config/database.php`, add these two keys at the end of the `'pgsql'` connection array, after `'sslmode'`:

```php
            // Laravel sends timestamps without an offset, so the session must be UTC.
            'timezone' => 'UTC',
            'options' => [
                // Supabase's transaction pooler cannot keep server-side prepared statements (design §12.1).
                PDO::ATTR_EMULATE_PREPARES => true,
            ],
```

In `config/fortify.php`, delete the line `Features::registration(),` from `'features'`. System Administrators create accounts (Task 3 here, then the user screens in Plan 2).

In `resources/views/livewire/auth/login.blade.php`, delete this block. It calls `route('register')` unconditionally and would break the sign-in page:

```blade
        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
```

- [ ] **Step 9: Run the new tests to verify they pass**

```bash
php artisan test tests/Feature/DatabaseConnectionTest.php tests/Feature/Auth/RegistrationDisabledTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 10: Run CI against PostgreSQL**

Replace `.github/workflows/tests.yml` with the file below. The repository has no remote yet, so the workflow starts running once one is added.

```yaml
name: tests

on:
  push:
    branches:
      - master
  pull_request:

permissions:
  contents: read

jobs:
  ci:
    runs-on: ubuntu-latest

    strategy:
      matrix:
        # 8.4 is the floor set by Pest 5; 8.5 matches development machines.
        php: ['8.4', '8.5']

    services:
      postgres:
        image: postgres:17
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: spims_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -U postgres"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 10

    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_PORT: 5432
      DB_DATABASE: spims_test
      DB_USERNAME: postgres
      DB_PASSWORD: postgres

    steps:
      - name: Checkout code
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Setup PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_pgsql, pgsql, intl
          tools: composer:v2
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@820762786026740c76f36085b0efc47a31fe5020 # v7.0.0
        with:
          node-version: '22'

      - name: Setup Application
        run: composer setup

      - name: Run CI Checks
        run: composer ci:check
```

- [ ] **Step 11: Run the full check**

```bash
composer test
```

Expected: Pint passes, PHPStan reports no errors, and every test passes. The starter kit's `RegistrationTest` is reported as skipped because registration is disabled.

- [ ] **Step 12: Commit**

```bash
git add .editorconfig .env.example .gitattributes .github .gitignore .npmrc app artisan bootstrap composer.json composer.lock config database package-lock.json package.json phpstan.neon phpunit.xml pint.json public resources routes storage tests vite.config.js
git status --short
git commit -m "Scaffold SPIMS on Laravel 13 with PostgreSQL tests and CI"
```

Before committing, check that `git status --short` lists no untracked files except `.env`, `vendor/` and `node_modules/`, which are ignored.

---

## Task 2: Configuration audit trail

**Files:**
- Create: `database/migrations/2026_09_14_100001_create_audit_logs_table.php`, `app/Models/AuditLog.php`, `app/Domain/Audit/AuditLogger.php`, `app/Http/Middleware/CaptureAuditContext.php`, `tests/Feature/Audit/AuditLoggerTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `tests/Pest.php`

**Interfaces:**
- Consumes: `App\Models\User`.
- Produces:
  - `AuditLogger::record(string $event, Model $auditable, ?array $oldValues = null, ?array $newValues = null, ?User $actor = null): AuditLog`. The actor defaults to the signed-in user.
  - The `prevent_append_only_mutation()` trigger function, reused by `stock_movements` in Task 7.
  - The `inSavepoint(Closure): Closure` test helper.
  - `AppServiceProvider::configureMorphMap()`, extended by later tasks.

- [ ] **Step 1: Add the savepoint helper to `tests/Pest.php`**

Add `use Illuminate\Support\Facades\DB;` to the imports and append:

```php
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
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Audit/AuditLoggerTest.php`:

```php
<?php

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

it('records the event, the audited record, old and new values and the actor', function () {
    $actor = User::factory()->create();
    $subject = User::factory()->create(['name' => 'Juan Dela Cruz']);

    $log = app(AuditLogger::class)->record('updated', $subject, ['name' => 'Juan'], ['name' => 'Juan Dela Cruz'], $actor);

    expect($log->fresh())
        ->user_id->toBe($actor->id)
        ->auditable_type->toBe('user')
        ->auditable_id->toBe((string) $subject->id)
        ->event->toBe('updated')
        ->old_values->toBe(['name' => 'Juan'])
        ->new_values->toBe(['name' => 'Juan Dela Cruz']);
});

it('defaults the actor to the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(app(AuditLogger::class)->record('updated', $user)->user_id)->toBe($user->id);
});

it('captures the client address and browser on web requests', function () {
    $user = User::factory()->create();

    Route::middleware('web')->get('/_test/audit', function () use ($user) {
        app(AuditLogger::class)->record('updated', $user);

        return response()->noContent();
    });

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
        ->withHeader('User-Agent', 'Storeroom Station 1')
        ->get('/_test/audit')
        ->assertNoContent();

    expect(AuditLog::query()->sole())
        ->ip_address->toBe('10.20.30.40')
        ->user_agent->toBe('Storeroom Station 1');
});

it('cannot be changed or deleted once written', function () {
    $log = app(AuditLogger::class)->record('updated', User::factory()->create());

    expect(inSavepoint(fn () => $log->update(['event' => 'tampered'])))
        ->toThrow(QueryException::class, 'audit_logs is append-only')
        ->and(inSavepoint(fn () => $log->delete()))
        ->toThrow(QueryException::class, 'audit_logs is append-only');
});
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Audit/AuditLoggerTest.php
```

Expected: FAIL with `Class "App\Domain\Audit\AuditLogger" not found`.

- [ ] **Step 4: Create the table**

Create `database/migrations/2026_09_14_100001_create_audit_logs_table.php`. `auditable_id` is `text` rather than the design's `bigint`, because settings are keyed by name; the design doc is amended to match.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('auditable_type');
            // text, not bigint: settings are keyed by name, e.g. regional.timezone.
            $table->text('auditable_id');
            $table->text('event');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress()->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('create index audit_logs_auditable_idx on audit_logs (auditable_type, auditable_id, created_at desc)');
        DB::statement('create index audit_logs_user_idx on audit_logs (user_id, created_at desc)');

        // Shared by every append-only table: audit_logs here, stock_movements later. "or replace"
        // because migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            create or replace function prevent_append_only_mutation() returns trigger
            language plpgsql as $fn$
            begin
              raise exception '% is append-only (attempted % on id %)', tg_table_name, tg_op, old.id;
            end;
            $fn$;

            create trigger audit_logs_append_only
              before update or delete on audit_logs
              for each row execute function prevent_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('drop function if exists prevent_append_only_mutation()');
    }
};
```

- [ ] **Step 5: Create the model, logger and middleware**

Create `app/Models/AuditLog.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the configuration audit trail (design §4.6). Append-only: a database trigger
 * rejects every UPDATE and DELETE.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $auditable_type
 * @property string $auditable_id
 * @property string $event
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'auditable_type', 'auditable_id', 'event', 'old_values', 'new_values', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
```

Create `app/Domain/Audit/AuditLogger.php`:

```php
<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

final class AuditLogger
{
    /** Context keys filled by App\Http\Middleware\CaptureAuditContext on web requests. */
    public const IP_ADDRESS = 'audit.ip_address';

    public const USER_AGENT = 'audit.user_agent';

    /**
     * Append one entry to the configuration audit trail (design §4.6). The actor defaults to the
     * signed-in user; console commands and jobs pass the actor explicitly.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $event,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
    ): AuditLog {
        $signedIn = Auth::user();
        $actor ??= $signedIn instanceof User ? $signedIn : null;

        return AuditLog::query()->create([
            'user_id' => $actor?->id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => (string) $auditable->getKey(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Context::getHidden(self::IP_ADDRESS),
            'user_agent' => Context::getHidden(self::USER_AGENT),
        ]);
    }
}
```

Create `app/Http/Middleware/CaptureAuditContext.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class CaptureAuditContext
{
    /**
     * Carry the client address and browser into every audit entry written while handling the request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Context::addHidden([
            AuditLogger::IP_ADDRESS => $request->ip(),
            AuditLogger::USER_AGENT => $request->userAgent(),
        ]);

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `use App\Http\Middleware\CaptureAuditContext;` and replace the empty `withMiddleware` callback body:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [CaptureAuditContext::class]);
    })
```

- [ ] **Step 6: Enforce the morph map**

In `app/Providers/AppServiceProvider.php`:

- Add `use App\Models\AuditLog;`, `use App\Models\User;` and `use Illuminate\Database\Eloquent\Relations\Relation;`.
- Call `$this->configureMorphMap();` at the end of `boot()`.
- Add this method:

```php
    /**
     * Stable type names in audit_logs and role assignments, so renaming a class never orphans the
     * audit trail. Every model that is audited or holds roles must be listed here.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'audit_log' => AuditLog::class,
        ]);
    }
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Audit/AuditLoggerTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_14_100001_create_audit_logs_table.php app/Models/AuditLog.php app/Domain/Audit/AuditLogger.php app/Http/Middleware/CaptureAuditContext.php app/Providers/AppServiceProvider.php bootstrap/app.php tests/Pest.php tests/Feature/Audit/AuditLoggerTest.php
git commit -m "Add the append-only configuration audit trail"
```

---

## Task 3: Roles, permissions and the first System Administrator

**Files:**
- Create: `app/Enums/Role.php`, `app/Enums/Permission.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `app/Console/Commands/CreateAdministrator.php`, `tests/Feature/Access/RolePermissionMatrixTest.php`, `tests/Feature/Access/CreateAdministratorCommandTest.php`
- Create (published): `config/permission.php`, `database/migrations/<publish timestamp>_create_permission_tables.php`
- Modify: `composer.json`, `composer.lock`, `app/Models/User.php`, `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `AuditLogger::record()` (Task 2).
- Produces:
  - `enum Role: string` with cases `Custodian`, `Supervisor`, `Planner` and `Admin`, plus `label(): string` and `permissions(): list<Permission>`.
  - `enum Permission: string` with 33 cases, plus `roles(): list<Role>`.
  - `User` uses `HasRoles`, so later plans check `$user->can(Permission::X->value)`.
  - The `spims:create-admin {email} {name}` command.

- [ ] **Step 1: Install spatie/laravel-permission and publish its migration**

```bash
composer require spatie/laravel-permission:^8.3
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

Expected: `config/permission.php` and `database/migrations/…_create_permission_tables.php` are copied.

In `app/Models/User.php`, add `use Spatie\Permission\Traits\HasRoles;` and add `HasRoles` to the trait list:

```php
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Access/RolePermissionMatrixTest.php`. Its matrix is copied from PRD §8 and is the specification, so do not derive it from the enum:

```php
<?php

use App\Enums\Permission;
use App\Enums\Role;
use Spatie\Permission\Models\Role as RoleModel;

// PRD v1.0 §8, one row per permission. Holders read C S P A: Custodian, Supervisor, Planner,
// System Administrator; a dash means the role does not hold it.
$matrix = [
    'Search Spare Parts and View Stock Balances' => [Permission::PartsView, 'CSPA'],
    'View Movement History' => [Permission::MovementsView, 'CSPA'],
    'Create Spare Part Record' => [Permission::PartsCreate, '-SPA'],
    'Create Spare Part Record (custodian, on receipt)' => [Permission::PartsCreateOnReceipt, 'CSPA'],
    'Edit Spare Part Record' => [Permission::PartsEdit, '-SPA'],
    'Edit Spare Part Record (custodian, basic fields)' => [Permission::PartsEditBasic, 'CSPA'],
    'Manage Stock Levels and Criticality' => [Permission::StockLevelsManage, '--PA'],
    'Manage Equipment and Storage Locations' => [Permission::EquipmentLocationsManage, '-SPA'],
    'Register Personnel and Enroll Badges' => [Permission::PersonnelManage, '-S-A'],
    'View Full Badge Identifier' => [Permission::BadgesViewFull, '---A'],
    'Import Spare Parts' => [Permission::PartsImport, '-S-A'],
    'Import Opening Balances' => [Permission::OpeningBalancesImport, '---A'],
    'Prepare and Confirm Withdrawal' => [Permission::WithdrawalsProcess, 'CS--'],
    'Authorize Insufficient Stock Override' => [Permission::OverridesAuthorise, '-S--'],
    'Authorize Manual Personnel Identification' => [Permission::IdentificationAuthorise, '-S--'],
    'Record Replenishment' => [Permission::ReplenishmentsRecord, 'CS--'],
    'Record Stock Adjustment' => [Permission::AdjustmentsRecord, '-S--'],
    'Reverse Completed Transaction' => [Permission::TransactionsReverse, '-S--'],
    'Record Backdated Transaction (within limit)' => [Permission::BackdatedRecordWithinLimit, 'CS--'],
    'Record Backdated Transaction (beyond limit)' => [Permission::BackdatedRecordBeyondLimit, '-S--'],
    'Open, Post, or Cancel Count Session' => [Permission::CountsManage, '-S--'],
    'Enter Counted Quantities' => [Permission::CountsEnter, 'CS--'],
    'View Consumption and Depletion' => [Permission::AnalyticsView, 'CSPA'],
    'Acknowledge Alerts (stock level)' => [Permission::StockAlertsAcknowledge, 'CSPA'],
    'Acknowledge Alerts (all types)' => [Permission::AlertsAcknowledge, '-SPA'],
    'View Dashboard' => [Permission::DashboardView, 'CSPA'],
    'View and Export Reports (A, E, F)' => [Permission::StockReportsView, 'CSPA'],
    'View and Export Reports (all)' => [Permission::ReportsView, '-SPA'],
    'View Audit History (spare parts and stock levels)' => [Permission::PartAuditView, '-SPA'],
    'View Audit History (all)' => [Permission::AuditView, '-S-A'],
    'Manage Users and Roles' => [Permission::UsersManage, '---A'],
    'Configure Install Settings' => [Permission::SettingsManage, '---A'],
    'Load or Reset Sample Data' => [Permission::SampleDataManage, '---A'],
];

dataset('prd permission matrix', $matrix);

it('grants each permission to exactly the roles in PRD §8', function (Permission $permission, string $holders) {
    $codes = ['C' => Role::Custodian, 'S' => Role::Supervisor, 'P' => Role::Planner, 'A' => Role::Admin];

    foreach ($codes as $code => $role) {
        expect(RoleModel::findByName($role->value)->hasPermissionTo($permission->value))
            ->toBe(str_contains($holders, $code), "{$role->label()} and {$permission->value}");
    }
})->with('prd permission matrix');

it('lists every permission the application defines', function () use ($matrix) {
    expect(array_map(fn (array $row): Permission => $row[0], array_values($matrix)))
        ->toEqualCanonicalizing(Permission::cases());
});
```

Create `tests/Feature/Access/CreateAdministratorCommandTest.php`:

```php
<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified System Administrator and audits it', function () {
    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->expectsOutputToContain('System Administrator it.admin@microtech.test created.')
        ->assertSuccessful();

    $user = User::query()->where('email', 'it.admin@microtech.test')->sole();

    expect($user->hasRole(Role::Admin))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('correct-horse-battery-staple', $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', 'user')->where('auditable_id', (string) $user->id)->sole()->event)
        ->toBe('created');
});

it('refuses an email address that is already taken', function () {
    User::factory()->create(['email' => 'it.admin@microtech.test']);

    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->where('email', 'it.admin@microtech.test')->count())->toBe(1);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Access
```

Expected: FAIL with `Class "App\Enums\Permission" not found`.

- [ ] **Step 4: Create the enums**

Create `app/Enums/Role.php`:

```php
<?php

namespace App\Enums;

enum Role: string
{
    case Custodian = 'custodian';
    case Supervisor = 'supervisor';
    case Planner = 'planner';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Custodian => 'Inventory Custodian',
            self::Supervisor => 'Inventory Supervisor',
            self::Planner => 'Inventory Planner',
            self::Admin => 'System Administrator',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => in_array($this, $permission->roles(), true),
        ));
    }
}
```

Create `app/Enums/Permission.php`:

```php
<?php

namespace App\Enums;

enum Permission: string
{
    case PartsView = 'parts.view';
    case MovementsView = 'movements.view';
    case PartsCreate = 'parts.create';
    case PartsCreateOnReceipt = 'parts.create-on-receipt';
    case PartsEdit = 'parts.edit';
    case PartsEditBasic = 'parts.edit-basic';
    case StockLevelsManage = 'stock-levels.manage';
    case EquipmentLocationsManage = 'equipment-locations.manage';
    case PersonnelManage = 'personnel.manage';
    case BadgesViewFull = 'badges.view-full';
    case PartsImport = 'parts.import';
    case OpeningBalancesImport = 'opening-balances.import';
    case WithdrawalsProcess = 'withdrawals.process';
    case OverridesAuthorise = 'overrides.authorise';
    case IdentificationAuthorise = 'identification.authorise';
    case ReplenishmentsRecord = 'replenishments.record';
    case AdjustmentsRecord = 'adjustments.record';
    case TransactionsReverse = 'transactions.reverse';
    case BackdatedRecordWithinLimit = 'backdated.record-within-limit';
    case BackdatedRecordBeyondLimit = 'backdated.record-beyond-limit';
    case CountsManage = 'counts.manage';
    case CountsEnter = 'counts.enter';
    case AnalyticsView = 'analytics.view';
    case StockAlertsAcknowledge = 'alerts.acknowledge-stock-level';
    case AlertsAcknowledge = 'alerts.acknowledge';
    case DashboardView = 'dashboard.view';
    case StockReportsView = 'reports.view-stock';
    case ReportsView = 'reports.view';
    case PartAuditView = 'audit.view-parts';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case SampleDataManage = 'sample-data.manage';

    /**
     * The roles that hold this permission, from PRD v1.0 §8. A "Conditional" cell in the PRD is a
     * narrower permission of its own, held by the conditional role and by every role with the full one:
     * PartsCreateOnReceipt, PartsEditBasic, BackdatedRecordWithinLimit, StockAlertsAcknowledge,
     * StockReportsView (reports A, E and F) and PartAuditView.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        $everyone = [Role::Custodian, Role::Supervisor, Role::Planner, Role::Admin];

        return match ($this) {
            self::PartsView,
            self::MovementsView,
            self::PartsCreateOnReceipt,
            self::PartsEditBasic,
            self::AnalyticsView,
            self::StockAlertsAcknowledge,
            self::DashboardView,
            self::StockReportsView => $everyone,

            self::PartsCreate,
            self::PartsEdit,
            self::EquipmentLocationsManage,
            self::AlertsAcknowledge,
            self::ReportsView,
            self::PartAuditView => [Role::Supervisor, Role::Planner, Role::Admin],

            self::StockLevelsManage => [Role::Planner, Role::Admin],

            self::PersonnelManage,
            self::PartsImport,
            self::AuditView => [Role::Supervisor, Role::Admin],

            self::WithdrawalsProcess,
            self::ReplenishmentsRecord,
            self::BackdatedRecordWithinLimit,
            self::CountsEnter => [Role::Custodian, Role::Supervisor],

            self::OverridesAuthorise,
            self::IdentificationAuthorise,
            self::AdjustmentsRecord,
            self::TransactionsReverse,
            self::BackdatedRecordBeyondLimit,
            self::CountsManage => [Role::Supervisor],

            self::BadgesViewFull,
            self::OpeningBalancesImport,
            self::UsersManage,
            self::SettingsManage,
            self::SampleDataManage => [Role::Admin],
        };
    }
}
```

- [ ] **Step 5: Seed roles and permissions into every install**

Create `database/seeders/RolesAndPermissionsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the four roles and their permissions from PRD §8. Safe to run again after an upgrade:
 * it adds new permissions and re-syncs every role to the current matrix.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web')->syncPermissions(
                array_map(fn (Permission $permission): string => $permission->value, $role->permissions()),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

In `database/seeders/DatabaseSeeder.php`, make `run()` call it:

```php
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
    }
```

- [ ] **Step 6: Create the provisioning command**

Create `app/Console/Commands/CreateAdministrator.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

class CreateAdministrator extends Command
{
    protected $signature = 'spims:create-admin
        {email : Sign-in email address}
        {name : Full name}';

    protected $description = 'Create a System Administrator account (provisioning checklist step 4)';

    public function handle(AuditLogger $audit): int
    {
        $validator = Validator::make(
            ['email' => $this->argument('email'), 'name' => $this->argument('name')],
            ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $password = password(
            label: 'Password',
            required: true,
            validate: fn (string $value): ?string => Validator::make(
                ['password' => $value],
                ['password' => [Password::default()]],
            )->errors()->first('password') ?: null,
        );

        $user = DB::transaction(function () use ($validator, $password, $audit): User {
            /** @var array{email: string, name: string} $input */
            $input = $validator->validated();

            $user = User::query()->create([...$input, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Role::Admin);

            $audit->record('created', $user, null, ['email' => $user->email, 'roles' => [Role::Admin->value]]);

            return $user;
        });

        $this->components->info("System Administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Access
```

Expected: PASS, 36 tests: 33 matrix rows (Pest reports each dataset row as a test), the coverage check and 2 command tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add composer.json composer.lock config/permission.php database/migrations/*_create_permission_tables.php app/Models/User.php app/Enums/Role.php app/Enums/Permission.php database/seeders/RolesAndPermissionsSeeder.php database/seeders/DatabaseSeeder.php app/Console/Commands/CreateAdministrator.php tests/Feature/Access
git commit -m "Add SPIMS roles and permissions from PRD section 8 and the create-admin command"
```

---

## Task 4: Install settings and withdrawal purposes

**Files:**
- Create: `app/Enums/TransactionType.php`, `app/Enums/MovementType.php`, `database/migrations/2026_09_14_100002_create_settings_table.php`, `database/migrations/2026_09_14_100003_create_withdrawal_purposes_table.php`, `app/Models/Setting.php`, `app/Models/WithdrawalPurpose.php`, `app/Domain/Settings/InstallSettings.php`, `database/seeders/WithdrawalPurposeSeeder.php`, `tests/Unit/TransactionTypeTest.php`, `tests/Feature/Settings/InstallSettingsTest.php`, `tests/Feature/Settings/WithdrawalPurposeSeederTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `AuditLogger` (Task 2).
- Produces:
  - `InstallSettings`, a scoped binding, with:
    - `DEFAULTS` (every setting key in design §4.3)
    - `get(string $key): mixed`
    - `set(string $key, mixed $value, User $actor): void`, where null restores the default
    - `companyName(): ?string`
    - `isReadyForStockTransactions(): bool`
    - `timezone(): string`
    - `transactionPrefix(TransactionType $type): string`
  - `enum TransactionType` with `direction(): ?int` and `movementType(): MovementType`.
  - `enum MovementType`.
  - The seeded `withdrawal_purposes` rows.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TransactionTypeTest.php`:

```php
<?php

use App\Enums\MovementType;
use App\Enums\TransactionType;

it('fixes the stock direction of every type except adjustments', function (TransactionType $type, ?int $direction, MovementType $movementType) {
    expect($type->direction())->toBe($direction)
        ->and($type->movementType())->toBe($movementType);
})->with([
    'withdrawal removes stock' => [TransactionType::Withdrawal, -1, MovementType::Withdrawal],
    'replenishment adds stock' => [TransactionType::Replenishment, 1, MovementType::Replenishment],
    'opening balance adds stock' => [TransactionType::Opening, 1, MovementType::Opening],
    'adjustment lines choose' => [TransactionType::Adjustment, null, MovementType::Adjustment],
]);
```

Create `tests/Feature/Settings/InstallSettingsTest.php`:

```php
<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;

it('uses the defaults until a setting is saved', function () {
    $settings = app(InstallSettings::class);

    expect($settings->timezone())->toBe('Asia/Manila')
        ->and($settings->transactionPrefix(TransactionType::Withdrawal))->toBe('WD')
        ->and($settings->transactionPrefix(TransactionType::Replenishment))->toBe('RP')
        ->and($settings->transactionPrefix(TransactionType::Adjustment))->toBe('AJ')
        ->and($settings->transactionPrefix(TransactionType::Opening))->toBe('OP')
        ->and($settings->get('withdrawal.identification_timeout_seconds'))->toBe(120)
        ->and($settings->companyName())->toBeNull()
        ->and($settings->isReadyForStockTransactions())->toBeFalse();
});

it('saves a setting and audits the old and new value', function () {
    $admin = User::factory()->create();

    app(InstallSettings::class)->set('regional.timezone', 'Asia/Singapore', $admin);

    // A fresh instance proves the value was saved, not just cached.
    expect((new InstallSettings(app(AuditLogger::class)))->timezone())->toBe('Asia/Singapore')
        ->and(AuditLog::query()->where('auditable_type', 'setting')->sole())
        ->auditable_id->toBe('regional.timezone')
        ->user_id->toBe($admin->id)
        ->old_values->toBe(['value' => 'Asia/Manila'])
        ->new_values->toBe(['value' => 'Asia/Singapore']);
});

it('saves structured settings intact', function () {
    app(InstallSettings::class)->set(
        'numbering.prefixes',
        ['withdrawal' => 'ISS', 'replenishment' => 'RCV', 'adjustment' => 'ADJ', 'opening' => 'OPN'],
        User::factory()->create(),
    );

    expect((new InstallSettings(app(AuditLogger::class)))->transactionPrefix(TransactionType::Replenishment))->toBe('RCV');
});

it('returns to the default when a setting is cleared', function () {
    $admin = User::factory()->create();
    $settings = app(InstallSettings::class);

    $settings->set('company.name', 'Microtech Industrial Solutions, Inc.', $admin);
    expect($settings->isReadyForStockTransactions())->toBeTrue();

    $settings->set('company.name', null, $admin);

    expect($settings->companyName())->toBeNull()
        ->and(Setting::query()->find('company.name'))->toBeNull();
});

it('treats a blank company name as not set', function () {
    app(InstallSettings::class)->set('company.name', '   ', User::factory()->create());

    expect(app(InstallSettings::class)->isReadyForStockTransactions())->toBeFalse();
});

it('does not audit a save that changes nothing', function () {
    app(InstallSettings::class)->set('regional.timezone', 'Asia/Manila', User::factory()->create());

    expect(AuditLog::query()->exists())->toBeFalse();
});

it('rejects settings it does not know', function () {
    expect(fn () => app(InstallSettings::class)->get('regional.language'))
        ->toThrow(InvalidArgumentException::class, 'Unknown install setting [regional.language].');
});
```

Create `tests/Feature/Settings/WithdrawalPurposeSeederTest.php`:

```php
<?php

use App\Models\WithdrawalPurpose;
use Database\Seeders\WithdrawalPurposeSeeder;

it('seeds the proposal purpose list in order', function () {
    expect(WithdrawalPurpose::query()->orderBy('sort_order')->pluck('name')->all())->toBe([
        'Preventive Maintenance',
        'Corrective Maintenance',
        'Breakdown Repair',
        'Machine Modification',
        'Replacement of Worn Part',
        'Other Maintenance Activity',
    ])->and(WithdrawalPurpose::query()->where('requires_remarks', true)->pluck('code')->all())->toBe(['other']);
});

it('keeps renamed purposes when the seeder runs again', function () {
    WithdrawalPurpose::query()->where('code', 'breakdown_repair')->update(['name' => 'Emergency Breakdown']);

    $this->seed(WithdrawalPurposeSeeder::class);

    expect(WithdrawalPurpose::query()->count())->toBe(6)
        ->and(WithdrawalPurpose::query()->where('code', 'breakdown_repair')->value('name'))->toBe('Emergency Breakdown');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/TransactionTypeTest.php tests/Feature/Settings
```

Expected: FAIL with `Class "App\Enums\TransactionType" not found`.

- [ ] **Step 3: Create the enums**

Create `app/Enums/TransactionType.php`:

```php
<?php

namespace App\Enums;

enum TransactionType: string
{
    case Withdrawal = 'withdrawal';
    case Replenishment = 'replenishment';
    case Adjustment = 'adjustment';
    case Opening = 'opening';

    /**
     * The stock direction every line of this type must carry: -1 removes stock, +1 adds it.
     * Null for adjustments, where each line states its own direction.
     */
    public function direction(): ?int
    {
        return match ($this) {
            self::Withdrawal => -1,
            self::Replenishment, self::Opening => 1,
            self::Adjustment => null,
        };
    }

    public function movementType(): MovementType
    {
        return match ($this) {
            self::Withdrawal => MovementType::Withdrawal,
            self::Replenishment => MovementType::Replenishment,
            self::Adjustment => MovementType::Adjustment,
            self::Opening => MovementType::Opening,
        };
    }
}
```

Create `app/Enums/MovementType.php`:

```php
<?php

namespace App\Enums;

enum MovementType: string
{
    case Withdrawal = 'withdrawal';
    case Replenishment = 'replenishment';
    case Adjustment = 'adjustment';
    case Opening = 'opening';
    case CountVariance = 'count_variance';
}
```

- [ ] **Step 4: Create the tables and models**

Create `database/migrations/2026_09_14_100002_create_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->text('key')->primary();
            $table->jsonb('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

Create `database/migrations/2026_09_14_100003_create_withdrawal_purposes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purposes differ between companies, so they are rows, not a CHECK constraint (design §3.6).
        Schema::create('withdrawal_purposes', function (Blueprint $table) {
            $table->id();
            $table->text('code')->unique();
            $table->text('name')->unique();
            $table->boolean('requires_remarks')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_purposes');
    }
};
```

Create `app/Models/Setting.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved install setting. Read settings through App\Domain\Settings\InstallSettings, which
 * supplies the default for every key that has no row here.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable $updated_at
 */
#[Table(key: 'key', keyType: 'string', incrementing: false)]
#[Fillable(['key', 'value', 'updated_by_user_id'])]
class Setting extends Model
{
    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
```

Create `app/Models/WithdrawalPurpose.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $requires_remarks
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['code', 'name', 'requires_remarks', 'sort_order', 'is_active'])]
class WithdrawalPurpose extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_remarks' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
```

- [ ] **Step 5: Create the settings service**

Create `app/Domain/Settings/InstallSettings.php`. The defaults come from PRD FR-SPIMS-018 and the setting keys from design §4.3:

```php
<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogger;
use App\Enums\TransactionType;
use App\Models\Setting;
use App\Models\User;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Per-install configuration (design §3.6 rule 1, PRD FR-SPIMS-018). A key with no saved row uses
 * its default, so a fresh install works before anyone opens Install Settings. The install type
 * (demo or production) is deliberately not a setting: it is environment configuration.
 */
final class InstallSettings
{
    public const array DEFAULTS = [
        'company.name' => null,
        'company.address' => null,
        'company.logo_path' => null,
        'regional.timezone' => 'Asia/Manila',
        'regional.currency' => 'PHP',
        'numbering.prefixes' => [
            'withdrawal' => 'WD',
            'replenishment' => 'RP',
            'adjustment' => 'AJ',
            'opening' => 'OP',
        ],
        'analysis.window_months' => 6,
        'movement_class.dead_stock_days' => 365,
        'movement_class.slow_moving_days' => 90,
        'movement_class.fast_moving_top_percent' => 20,
        'movement_class.minimum_history_days' => 90,
        'alerts.high_consumption.multiplier' => 2.0,
        'alerts.high_consumption.minimum_events' => 3,
        'alerts.high_consumption.minimum_baseline_days' => 60,
        'withdrawal.identification_timeout_seconds' => 120,
        'backdating.limit_days' => 7,
        'counting.default_tolerance_pct' => 0,
        'notifications.digest_recipients' => [],
        'notifications.instant_alert_types' => [],
        'prediction.default_lead_time_days' => 14,
        'prediction.warning_buffer_days' => 7,
    ];

    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown install setting [{$key}].");
        }

        $saved = $this->saved();

        return array_key_exists($key, $saved) ? $saved[$key] : self::DEFAULTS[$key];
    }

    /**
     * Save a setting and audit the old and new values. Saving null removes the saved value, so the
     * default applies again.
     */
    public function set(string $key, mixed $value, User $actor): void
    {
        $old = $this->get($key);

        if ($value === null) {
            Setting::query()->whereKey($key)->delete();
        } else {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by_user_id' => $actor->id]);
        }

        $this->saved = null;
        $new = $this->get($key);

        if ($old !== $new) {
            $this->audit->record('updated', new Setting(['key' => $key]), ['value' => $old], ['value' => $new], $actor);
        }
    }

    public function companyName(): ?string
    {
        $name = $this->get('company.name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * PRD FR-SPIMS-018: an install records no stock transactions until its company name is set.
     */
    public function isReadyForStockTransactions(): bool
    {
        return $this->companyName() !== null;
    }

    public function timezone(): string
    {
        $timezone = $this->get('regional.timezone');

        if (! is_string($timezone)) {
            throw new UnexpectedValueException('Install setting [regional.timezone] must be a string.');
        }

        return $timezone;
    }

    public function transactionPrefix(TransactionType $type): string
    {
        $prefixes = $this->get('numbering.prefixes');
        $prefix = is_array($prefixes) ? ($prefixes[$type->value] ?? null) : null;

        if (! is_string($prefix)) {
            throw new UnexpectedValueException("Install setting [numbering.prefixes] has no prefix for [{$type->value}].");
        }

        return $prefix;
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        return $this->saved ??= Setting::query()->pluck('value', 'key')->all();
    }
}
```

In `app/Providers/AppServiceProvider.php`:

1. Add `use App\Domain\Settings\InstallSettings;`, `use App\Models\Setting;` and `use App\Models\WithdrawalPurpose;`.
2. Make `register()` read:

```php
    public function register(): void
    {
        // Scoped, not singleton: saved settings are cached per request or queued job, never across them.
        $this->app->scoped(InstallSettings::class);
    }
```

3. Add these entries to the morph map:

```php
            'setting' => Setting::class,
            'withdrawal_purpose' => WithdrawalPurpose::class,
```

- [ ] **Step 6: Seed the proposal's purpose list**

Create `database/seeders/WithdrawalPurposeSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\WithdrawalPurpose;
use Illuminate\Database\Seeder;

/**
 * The proposal's §4 Step 3 purpose list, seeded into every install. Existing rows are left alone,
 * so a System Administrator's renames and deactivations survive re-running the seeder.
 */
class WithdrawalPurposeSeeder extends Seeder
{
    public function run(): void
    {
        $purposes = [
            ['code' => 'preventive_maintenance', 'name' => 'Preventive Maintenance', 'requires_remarks' => false],
            ['code' => 'corrective_maintenance', 'name' => 'Corrective Maintenance', 'requires_remarks' => false],
            ['code' => 'breakdown_repair', 'name' => 'Breakdown Repair', 'requires_remarks' => false],
            ['code' => 'machine_modification', 'name' => 'Machine Modification', 'requires_remarks' => false],
            ['code' => 'worn_part_replacement', 'name' => 'Replacement of Worn Part', 'requires_remarks' => false],
            ['code' => 'other', 'name' => 'Other Maintenance Activity', 'requires_remarks' => true],
        ];

        foreach ($purposes as $index => $purpose) {
            WithdrawalPurpose::query()->firstOrCreate(
                ['code' => $purpose['code']],
                [...$purpose, 'sort_order' => ($index + 1) * 10],
            );
        }
    }
}
```

Replace `database/seeders/DatabaseSeeder.php` with:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Install defaults every company gets (provisioning checklist step 3). Never seeds users,
     * company details or sample data: those are provisioning steps of their own.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            WithdrawalPurposeSeeder::class,
        ]);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/TransactionTypeTest.php tests/Feature/Settings
```

Expected: PASS, 13 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Enums/TransactionType.php app/Enums/MovementType.php database/migrations/2026_09_14_100002_create_settings_table.php database/migrations/2026_09_14_100003_create_withdrawal_purposes_table.php app/Models/Setting.php app/Models/WithdrawalPurpose.php app/Domain/Settings/InstallSettings.php app/Providers/AppServiceProvider.php database/seeders/WithdrawalPurposeSeeder.php database/seeders/DatabaseSeeder.php tests/Unit/TransactionTypeTest.php tests/Feature/Settings
git commit -m "Add install settings with audited changes and seeded withdrawal purposes"
```

---

## Task 5: Master data schema

**Files:**
- Create: `app/Enums/Criticality.php`, `database/migrations/2026_09_14_100004_create_master_data_tables.php`, `app/Models/StorageLocation.php`, `app/Models/Equipment.php`, `app/Models/Part.php`, `app/Models/PartStock.php`, `database/factories/StorageLocationFactory.php`, `database/factories/EquipmentFactory.php`, `database/factories/PartFactory.php`, `tests/Feature/MasterData/MasterDataSchemaTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - The `storage_locations`, `equipment`, `parts`, `part_equipment` and `part_stock` tables.
  - The trigger `parts_create_stock_row`, which gives every part a `part_stock` row at 0.
  - The `Part` relations `defaultLocation()`, `stock()` and `equipment()`, and `Equipment::parts()`.
  - `PartStock`, keyed by `part_id`, with `on_hand` as a `decimal:3` string.
  - Factories for `StorageLocation`, `Equipment` and `Part`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MasterData/MasterDataSchemaTest.php`:

```php
<?php

use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StorageLocation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('gives every new part a stock row at zero', function () {
    $part = Part::factory()->create();

    expect(PartStock::query()->find($part->id)?->on_hand)->toBe('0.000');
});

it('treats part numbers as unique regardless of letter case', function () {
    Part::factory()->create(['part_number' => 'BRG-6205-2RS']);

    expect(inSavepoint(fn () => Part::factory()->create(['part_number' => 'brg-6205-2rs'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rejects stock levels in the wrong order', function (array $levels, string $constraint) {
    expect(inSavepoint(fn () => Part::factory()->create($levels)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'critical above minimum' => [['min_level' => '5', 'critical_level' => '6'], 'parts_levels_ordered'],
    'maximum below minimum' => [['min_level' => '5', 'max_level' => '4'], 'parts_levels_ordered'],
    'negative reorder point' => [['reorder_point' => '-1'], 'parts_levels_non_negative'],
]);

it('rejects an unknown criticality', function () {
    expect(inSavepoint(fn () => DB::table('parts')->insert([
        'part_number' => 'SEAL-001',
        'description' => 'Oil seal',
        'criticality' => 'urgent',
    ])))->toThrow(QueryException::class, 'parts_criticality_check');
});

it('never lets stock go negative', function () {
    $part = Part::factory()->create();

    expect(inSavepoint(fn () => DB::table('part_stock')->where('part_id', $part->id)->update(['on_hand' => '-1'])))
        ->toThrow(QueryException::class, 'part_stock_non_negative');
});

it('keeps a part in its storage location and links it to every machine it fits', function () {
    $rack = StorageLocation::factory()->create();
    $part = Part::factory()->create(['default_location_id' => $rack->id]);
    $conveyor = Equipment::factory()->create();
    $pump = Equipment::factory()->create();

    $part->equipment()->attach([$conveyor->id => ['notes' => 'Drive end'], $pump->id => []]);

    expect($part->defaultLocation?->is($rack))->toBeTrue()
        ->and($part->equipment()->orderBy('equipment.id')->pluck('equipment.id')->all())->toBe([$conveyor->id, $pump->id])
        ->and($conveyor->parts()->sole()->pivot->notes)->toBe('Drive end');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/MasterData
```

Expected: FAIL with `Class "App\Models\Part" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_09_14_100004_create_master_data_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm');

        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->text('code')->unique();
            $table->text('name');
            $table->text('area')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->text('part_number');
            $table->text('description');
            $table->text('unit_of_measure')->default('pcs');
            $table->foreignId('default_location_id')->nullable()->constrained('storage_locations');
            $table->decimal('min_level', 14, 3)->default(0);
            $table->decimal('max_level', 14, 3)->nullable();
            $table->decimal('reorder_point', 14, 3)->default(0);
            $table->decimal('critical_level', 14, 3)->default(0);
            $table->text('criticality')->default('normal');
            $table->integer('lead_time_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table parts
              add constraint parts_criticality_check check (criticality in ('critical', 'important', 'normal')),
              add constraint parts_levels_ordered check (
                critical_level <= min_level and (max_level is null or max_level >= min_level)
              ),
              add constraint parts_levels_non_negative check (
                min_level >= 0 and reorder_point >= 0 and critical_level >= 0
              );

            create unique index parts_part_number_key on parts (lower(part_number));
            create index parts_description_trgm on parts using gin (description gin_trgm_ops);
            create index parts_criticality_active_idx on parts (criticality) where is_active;
            create index parts_location_idx on parts (default_location_id);
            SQL);

        // A part usually fits several machines (design §4.3). Distinct from where a part was used.
        Schema::create('part_equipment', function (Blueprint $table) {
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->primary(['part_id', 'equipment_id']);
            $table->index('equipment_id', 'part_equipment_equipment_idx');
        });

        // The stock cache and row-lock target: one row per part (design §4.1).
        Schema::create('part_stock', function (Blueprint $table) {
            $table->foreignId('part_id')->primary()->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table part_stock add constraint part_stock_non_negative check (on_hand >= 0);

            -- Every part gets its lock target the moment it exists, however it was inserted.
            -- "or replace" because migrate:fresh drops tables but not functions.
            create or replace function create_part_stock_row() returns trigger
            language plpgsql as $fn$
            begin
              insert into part_stock (part_id) values (new.id);
              return new;
            end;
            $fn$;

            create trigger parts_create_stock_row
              after insert on parts
              for each row execute function create_part_stock_row();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('part_stock');
        Schema::dropIfExists('part_equipment');
        Schema::dropIfExists('parts');
        DB::statement('drop function if exists create_part_stock_row()');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('storage_locations');
    }
};
```

- [ ] **Step 4: Create the enum, models and factories**

Create `app/Enums/Criticality.php`:

```php
<?php

namespace App\Enums;

enum Criticality: string
{
    case Critical = 'critical';
    case Important = 'important';
    case Normal = 'normal';
}
```

Create `app/Models/StorageLocation.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StorageLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['code', 'name', 'description', 'is_active'])]
class StorageLocation extends Model
{
    /** @use HasFactory<StorageLocationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
```

Create `app/Models/Equipment.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $area
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Table(name: 'equipment')]
#[Fillable(['code', 'name', 'area', 'is_active'])]
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Part, $this>
     */
    public function parts(): BelongsToMany
    {
        return $this->belongsToMany(Part::class, 'part_equipment')->withPivot('notes');
    }
}
```

Create `app/Models/Part.php`:

```php
<?php

namespace App\Models;

use App\Enums\Criticality;
use Carbon\CarbonImmutable;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $part_number
 * @property string $description
 * @property string $unit_of_measure
 * @property int|null $default_location_id
 * @property string $min_level
 * @property string|null $max_level
 * @property string $reorder_point
 * @property string $critical_level
 * @property Criticality $criticality
 * @property int|null $lead_time_days
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StorageLocation|null $defaultLocation
 * @property-read PartStock|null $stock
 * @property-read Collection<int, Equipment> $equipment
 */
#[Fillable([
    'part_number', 'description', 'unit_of_measure', 'default_location_id', 'min_level', 'max_level',
    'reorder_point', 'critical_level', 'criticality', 'lead_time_days', 'is_active',
])]
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_level' => 'decimal:3',
            'max_level' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'critical_level' => 'decimal:3',
            'criticality' => Criticality::class,
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'default_location_id');
    }

    /**
     * @return HasOne<PartStock, $this>
     */
    public function stock(): HasOne
    {
        return $this->hasOne(PartStock::class);
    }

    /**
     * @return BelongsToMany<Equipment, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'part_equipment')->withPivot('notes');
    }
}
```

Create `app/Models/PartStock.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stock cache and row-lock target for one part (design §4.1). A database trigger creates the
 * row when the part is inserted. Only App\Domain\Ledger\StockLedger changes on_hand, which must
 * always equal the sum of the part's stock movements.
 *
 * @property int $part_id
 * @property string $on_hand
 * @property CarbonImmutable $updated_at
 * @property-read Part $part
 */
#[Table(name: 'part_stock', key: 'part_id', incrementing: false)]
class PartStock extends Model
{
    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
```

Create `database/factories/StorageLocationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageLocation>
 */
class StorageLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('RACK-??-##'),
            'name' => 'Rack '.fake()->unique()->bothify('?##'),
            'is_active' => true,
        ];
    }
}
```

Create `database/factories/EquipmentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('EQ-####'),
            'name' => fake()->randomElement(['Conveyor', 'Compressor', 'Boiler Feed Pump', 'Packaging Line']).' '.fake()->numberBetween(1, 9),
            'area' => fake()->randomElement(['Production', 'Utilities', 'Warehouse']),
            'is_active' => true,
        ];
    }
}
```

Create `database/factories/PartFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\Criticality;
use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Part>
 */
class PartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'part_number' => fake()->unique()->bothify('PN-#####-??'),
            'description' => fake()->randomElement(['Deep groove ball bearing', 'V-belt', 'Proximity sensor', 'Hydraulic oil']).' '.fake()->bothify('##??'),
            'unit_of_measure' => 'pcs',
            'criticality' => Criticality::Normal,
            'is_active' => true,
        ];
    }
}
```

In `app/Providers/AppServiceProvider.php`, import the three models and add them to the morph map:

```php
            'storage_location' => StorageLocation::class,
            'equipment' => Equipment::class,
            'part' => Part::class,
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/MasterData
```

Expected: PASS, 8 tests.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Enums/Criticality.php database/migrations/2026_09_14_100004_create_master_data_tables.php app/Models/StorageLocation.php app/Models/Equipment.php app/Models/Part.php app/Models/PartStock.php database/factories/StorageLocationFactory.php database/factories/EquipmentFactory.php database/factories/PartFactory.php app/Providers/AppServiceProvider.php tests/Feature/MasterData
git commit -m "Add parts, locations, equipment and the per-part stock row"
```

---

## Task 6: Personnel and badge identity

**Files:**
- Create: `config/spims.php`, `database/migrations/2026_09_14_100005_create_personnel_table.php`, `app/Models/Personnel.php`, `database/factories/PersonnelFactory.php`, `app/Domain/Identity/BadgeReader.php`, `app/Domain/Identity/KeyboardWedgeBadgeReader.php`, `app/Domain/Identity/BadgeIdentity.php`, `app/Domain/Identity/PersonnelBadges.php`, `app/Domain/Identity/Exceptions/InvalidBadgeInput.php`, `app/Domain/Identity/Exceptions/BadgeAlreadyEnrolled.php`, `tests/Unit/Identity/KeyboardWedgeBadgeReaderTest.php`, `tests/Unit/Identity/BadgeIdentityTest.php`, `tests/Feature/Identity/PersonnelBadgesTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `phpunit.xml`, `.env`, `.env.example`

**Interfaces:**
- Consumes: `AuditLogger` (Task 2).
- Produces:
  - `interface BadgeReader { normalise(string $rawInput): string }`, bound to `KeyboardWedgeBadgeReader`.
  - `BadgeIdentity::hash(string $uid): string` (HMAC-SHA256 with `config('spims.badge_pepper')`).
  - `BadgeIdentity::mask(string $uid): string`, which shows at most 5 leading characters and always hides the last 4.
  - `BadgeIdentity::attributesFor(string $uid): array`.
  - `PersonnelBadges::enroll(Personnel $personnel, string $rawInput, User $actor): void`, which throws `BadgeAlreadyEnrolled` or `InvalidBadgeInput`.
  - `PersonnelBadges::identify(string $rawInput): ?Personnel`, which returns Active personnel only.
  - The `PersonnelFactory` states `withBadge(string $uid)` and `inactive()`.

- [ ] **Step 1: Configure the badge pepper**

Create `config/spims.php`:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Badge UID Pepper
    |--------------------------------------------------------------------------
    |
    | Badge UIDs are stored as an HMAC-SHA256 keyed with this pepper (design
    | §8.2). Generate one per install with:
    |   php -r "echo bin2hex(random_bytes(32));"
    | Never change it on a live install: every enrolled badge stops matching.
    |
    */

    'badge_pepper' => env('BADGE_PEPPER'),

];
```

Add `BADGE_PEPPER=` to `.env.example`. In `.env`, set it to the output of `php -r "echo bin2hex(random_bytes(32));"`. In `phpunit.xml`, add this to the `<php>` block:

```xml
        <env name="BADGE_PEPPER" value="testing-pepper-never-used-in-an-install"/>
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/Identity/KeyboardWedgeBadgeReaderTest.php`:

```php
<?php

use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Domain\Identity\KeyboardWedgeBadgeReader;

it('normalises what a keyboard-wedge reader types', function (string $raw, string $uid) {
    expect((new KeyboardWedgeBadgeReader)->normalise($raw))->toBe($uid);
})->with([
    'trailing enter' => ["A12345678\r\n", 'A12345678'],
    'lower case' => ['a12345678', 'A12345678'],
    'separators' => ['A1:23-45 678', 'A12345678'],
    'surrounding whitespace' => ["\t 0004521987 ", '0004521987'],
]);

it('rejects input that is not a badge', function (string $raw) {
    expect(fn () => (new KeyboardWedgeBadgeReader)->normalise($raw))->toThrow(InvalidBadgeInput::class);
})->with([
    'nothing' => [''],
    'only whitespace' => ["  \n"],
    'too short' => ['A12'],
    'symbols' => ['A1234$678'],
]);
```

Create `tests/Unit/Identity/BadgeIdentityTest.php`:

```php
<?php

use App\Domain\Identity\BadgeIdentity;

it('shows at most the first five characters and always hides the last four', function (string $uid, string $masked) {
    expect((new BadgeIdentity('pepper'))->mask($uid))->toBe($masked);
})->with([
    'proposal sample' => ['A12345678', 'A1234****'],
    'ten digits' => ['0004521987', '00045*****'],
    'six characters' => ['AB1234', 'AB****'],
    'four characters' => ['AB12', '****'],
]);

it('hashes with the install pepper, so the same badge differs between installs', function () {
    $hash = (new BadgeIdentity('microtech-pepper'))->hash('A12345678');

    expect($hash)
        ->toMatch('/^[0-9a-f]{64}$/')
        ->toBe((new BadgeIdentity('microtech-pepper'))->hash('A12345678'))
        ->not->toBe((new BadgeIdentity('customer-pepper'))->hash('A12345678'));
});

it('refuses to hash without a pepper', function (?string $pepper) {
    expect(fn () => (new BadgeIdentity($pepper))->hash('A12345678'))
        ->toThrow(RuntimeException::class, 'BADGE_PEPPER');
})->with([
    'missing' => [null],
    'empty' => [''],
]);
```

Create `tests/Feature/Identity/PersonnelBadgesTest.php`:

```php
<?php

use App\Domain\Identity\Exceptions\BadgeAlreadyEnrolled;
use App\Domain\Identity\PersonnelBadges;
use App\Models\AuditLog;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->badges = app(PersonnelBadges::class);
});

it('stores a badge only as a hash, an encrypted copy and a masked form', function () {
    $person = Personnel::factory()->create();

    $this->badges->enroll($person, "A12345678\r\n", $this->admin);

    $row = DB::table('personnel')->where('id', $person->id)->first();

    expect($row->badge_uid_masked)->toBe('A1234****')
        ->and($row->badge_uid_hash)->toBe(hash_hmac('sha256', 'A12345678', config('spims.badge_pepper')))
        ->and($row->badge_uid_encrypted)->not->toContain('A12345678')
        ->and($person->fresh()?->badge_uid_encrypted)->toBe('A12345678');
});

it('identifies the active person a badge belongs to', function () {
    $person = Personnel::factory()->create();
    $this->badges->enroll($person, 'a1234-5678', $this->admin);

    expect($this->badges->identify('A12345678')?->is($person))->toBeTrue();
});

it('does not identify inactive personnel or unknown badges', function () {
    Personnel::factory()->inactive()->withBadge('B99990000')->create();

    expect($this->badges->identify('B99990000'))->toBeNull()
        ->and($this->badges->identify('C00000001'))->toBeNull();
});

it('refuses a badge that an active person already holds', function () {
    $holder = Personnel::factory()->withBadge('A12345678')->create(['full_name' => 'Maria Santos']);
    $other = Personnel::factory()->create();

    expect(fn () => $this->badges->enroll($other, 'A12345678', $this->admin))
        ->toThrow(BadgeAlreadyEnrolled::class, 'Maria Santos');

    expect($other->fresh()?->badge_uid_hash)->toBeNull()
        ->and($this->badges->identify('A12345678')?->is($holder))->toBeTrue();
});

it('moves a re-issued badge from a person who has left', function () {
    $leaver = Personnel::factory()->inactive()->withBadge('A12345678')->create();
    $joiner = Personnel::factory()->create();

    $this->badges->enroll($joiner, 'A12345678', $this->admin);

    expect($leaver->fresh()?->badge_uid_hash)->toBeNull()
        ->and($this->badges->identify('A12345678')?->is($joiner))->toBeTrue()
        ->and(AuditLog::query()->orderBy('id')->pluck('event')->all())->toBe(['badge_released', 'badge_enrolled']);
});

it('stops a replaced badge from identifying anyone', function () {
    $person = Personnel::factory()->create();

    $this->badges->enroll($person, 'A12345678', $this->admin);
    $this->badges->enroll($person, 'Z87654321', $this->admin);

    expect($this->badges->identify('A12345678'))->toBeNull()
        ->and($this->badges->identify('Z87654321')?->is($person))->toBeTrue()
        ->and(AuditLog::query()->latest('id')->first())
        ->event->toBe('badge_replaced')
        ->old_values->toBe(['badge_uid_masked' => 'A1234****'])
        ->new_values->toBe(['badge_uid_masked' => 'Z8765****']);
});

it('never writes a badge UID into the audit trail', function () {
    $this->badges->enroll(Personnel::factory()->create(), 'A12345678', $this->admin);

    expect(AuditLog::query()->get()->toJson())->not->toContain('A12345678');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/Identity tests/Feature/Identity
```

Expected: FAIL with `Class "App\Domain\Identity\KeyboardWedgeBadgeReader" not found`.

- [ ] **Step 4: Create the reader, the identity rules and their exceptions**

Create `app/Domain/Identity/BadgeReader.php`:

```php
<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Exceptions\InvalidBadgeInput;

/**
 * The hardware boundary (design §10). Only the implementation changes if a serial or vendor-SDK
 * reader replaces the keyboard-wedge reader.
 */
interface BadgeReader
{
    /**
     * Turn raw reader input into the canonical badge UID.
     *
     * @throws InvalidBadgeInput
     */
    public function normalise(string $rawInput): string;
}
```

Create `app/Domain/Identity/KeyboardWedgeBadgeReader.php`:

```php
<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Exceptions\InvalidBadgeInput;

/**
 * An HID keyboard-wedge reader "types" the card UID into a focused field and presses Enter.
 * Input arrives with stray whitespace, control characters and, on some models, separators.
 */
final class KeyboardWedgeBadgeReader implements BadgeReader
{
    public function normalise(string $rawInput): string
    {
        $uid = strtoupper((string) preg_replace('/[\s:\-\x00-\x1F\x7F]+/', '', $rawInput));

        if ($uid === '') {
            throw InvalidBadgeInput::empty();
        }

        if (preg_match('/^[A-Z0-9]{4,64}$/', $uid) !== 1) {
            throw InvalidBadgeInput::malformed();
        }

        return $uid;
    }
}
```

Create `app/Domain/Identity/Exceptions/InvalidBadgeInput.php`:

```php
<?php

namespace App\Domain\Identity\Exceptions;

use DomainException;

final class InvalidBadgeInput extends DomainException
{
    public static function empty(): self
    {
        return new self('No badge was read. Scan the badge again.');
    }

    public static function malformed(): self
    {
        return new self('The badge could not be read. Scan the badge again.');
    }
}
```

Create `app/Domain/Identity/Exceptions/BadgeAlreadyEnrolled.php`:

```php
<?php

namespace App\Domain\Identity\Exceptions;

use App\Models\Personnel;
use DomainException;

final class BadgeAlreadyEnrolled extends DomainException
{
    public function __construct(public readonly Personnel $holder)
    {
        parent::__construct(
            "Badge {$holder->badge_uid_masked} is already enrolled to {$holder->full_name} ({$holder->employee_no}).",
        );
    }
}
```

Create `app/Domain/Identity/BadgeIdentity.php`:

```php
<?php

namespace App\Domain\Identity;

use Illuminate\Container\Attributes\Config;
use RuntimeException;

/**
 * How a badge UID is stored (design §8.2): an HMAC-SHA256 lookup key, an encrypted copy for System
 * Administrators, and a masked display form. A database leak alone yields no usable badge UIDs.
 */
final readonly class BadgeIdentity
{
    public function __construct(
        #[Config('spims.badge_pepper')] private ?string $pepper,
    ) {}

    public function hash(string $uid): string
    {
        if ($this->pepper === null || $this->pepper === '') {
            throw new RuntimeException('BADGE_PEPPER is not set. Generate one per install; see config/spims.php.');
        }

        return hash_hmac('sha256', $uid, $this->pepper);
    }

    /**
     * Show at most the first five characters and always hide the last four, so a nine-character
     * UID reads A1234**** as in the proposal's §3B sample.
     */
    public function mask(string $uid): string
    {
        $length = strlen($uid);
        $visible = max(0, min(5, $length - 4));

        return substr($uid, 0, $visible).str_repeat('*', $length - $visible);
    }

    /**
     * Personnel attributes for an enrolled badge. badge_uid_encrypted is given in plain text
     * because the model's encrypted cast encrypts it on save.
     *
     * @return array{badge_uid_hash: string, badge_uid_encrypted: string, badge_uid_masked: string}
     */
    public function attributesFor(string $uid): array
    {
        return [
            'badge_uid_hash' => $this->hash($uid),
            'badge_uid_encrypted' => $uid,
            'badge_uid_masked' => $this->mask($uid),
        ];
    }
}
```

- [ ] **Step 5: Run the unit tests to verify they pass**

```bash
php artisan test tests/Unit/Identity
```

Expected: PASS, 15 tests.

- [ ] **Step 6: Create the personnel table, model, factory and enrollment service**

Create `database/migrations/2026_09_14_100005_create_personnel_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People who receive parts. They never sign in (design §3.5).
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->text('employee_no')->unique();
            $table->text('full_name');
            $table->text('department')->nullable();
            $table->text('badge_uid_hash')->nullable()->unique();
            $table->text('badge_uid_encrypted')->nullable();
            $table->text('badge_uid_masked')->nullable();
            $table->text('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
```

Create `app/Models/Personnel.php`:

```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PersonnelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person who receives spare parts. Never signs in (design §3.5). The badge columns are written
 * only by App\Domain\Identity\PersonnelBadges.
 *
 * @property int $id
 * @property string $employee_no
 * @property string $full_name
 * @property string|null $department
 * @property string|null $badge_uid_hash
 * @property string|null $badge_uid_encrypted
 * @property string|null $badge_uid_masked
 * @property string|null $photo_path
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Table(name: 'personnel')]
#[Fillable(['employee_no', 'full_name', 'department', 'photo_path', 'is_active'])]
#[Hidden(['badge_uid_hash', 'badge_uid_encrypted'])]
class Personnel extends Model
{
    /** @use HasFactory<PersonnelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'badge_uid_encrypted' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
```

Create `database/factories/PersonnelFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Identity\BadgeIdentity;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_no' => fake()->unique()->numerify('EMP-#####'),
            'full_name' => fake()->name(),
            'department' => fake()->randomElement(['Maintenance', 'Production', 'Utilities']),
            'is_active' => true,
        ];
    }

    /**
     * Enroll a badge directly, for tests that need an identified person but are not about enrollment.
     */
    public function withBadge(string $uid): static
    {
        return $this->state(fn (): array => app(BadgeIdentity::class)->attributesFor($uid));
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
```

Create `app/Domain/Identity/PersonnelBadges.php`:

```php
<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Exceptions\BadgeAlreadyEnrolled;
use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class PersonnelBadges
{
    public function __construct(
        private BadgeReader $reader,
        private BadgeIdentity $identity,
        private AuditLogger $audit,
    ) {}

    /**
     * Enroll a scanned badge to a person (PRD FR-SPIMS-004). A badge still held by an Inactive
     * person is released from them first, because badges are re-issued when people leave.
     *
     * @throws InvalidBadgeInput
     * @throws BadgeAlreadyEnrolled when an Active person holds the badge
     */
    public function enroll(Personnel $personnel, string $rawInput, User $actor): void
    {
        $uid = $this->reader->normalise($rawInput);
        $attributes = $this->identity->attributesFor($uid);

        DB::transaction(function () use ($personnel, $attributes, $actor): void {
            $holder = Personnel::query()
                ->where('badge_uid_hash', $attributes['badge_uid_hash'])
                ->lockForUpdate()
                ->first();

            if ($holder !== null && $holder->isNot($personnel)) {
                if ($holder->is_active) {
                    throw new BadgeAlreadyEnrolled($holder);
                }

                $released = $holder->badge_uid_masked;
                $holder->forceFill(['badge_uid_hash' => null, 'badge_uid_encrypted' => null, 'badge_uid_masked' => null])->save();
                $this->audit->record('badge_released', $holder, ['badge_uid_masked' => $released], null, $actor);
            }

            $previous = $personnel->badge_uid_masked;
            $personnel->forceFill($attributes)->save();

            $this->audit->record(
                $previous === null ? 'badge_enrolled' : 'badge_replaced',
                $personnel,
                ['badge_uid_masked' => $previous],
                ['badge_uid_masked' => $attributes['badge_uid_masked']],
                $actor,
            );
        });
    }

    /**
     * The Active person a scan identifies, or null when the badge is not enrolled or its holder is
     * Inactive (PRD FR-SPIMS-007).
     *
     * @throws InvalidBadgeInput
     */
    public function identify(string $rawInput): ?Personnel
    {
        return Personnel::query()
            ->where('badge_uid_hash', $this->identity->hash($this->reader->normalise($rawInput)))
            ->where('is_active', true)
            ->first();
    }
}
```

In `app/Providers/AppServiceProvider.php`, import `BadgeReader`, `KeyboardWedgeBadgeReader` and `Personnel`. Add the binding at the end of `register()`:

```php
        $this->app->bind(BadgeReader::class, KeyboardWedgeBadgeReader::class);
```

Add this morph map entry:

```php
            'personnel' => Personnel::class,
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/Identity tests/Feature/Identity
```

Expected: PASS, 22 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add config/spims.php .env.example phpunit.xml database/migrations/2026_09_14_100005_create_personnel_table.php app/Models/Personnel.php database/factories/PersonnelFactory.php app/Domain/Identity app/Providers/AppServiceProvider.php tests/Unit/Identity tests/Feature/Identity
git commit -m "Add personnel with peppered badge hashes, masked display and enrollment"
```

---

## Task 7: Transaction and ledger schema

**Files:**
- Create: `app/Enums/TransactionStatus.php`, `app/Enums/ReasonCode.php`, `app/Enums/IdentificationMethod.php`, `database/migrations/2026_09_14_100006_create_ledger_tables.php`, `app/Models/StockTransaction.php`, `app/Models/StockTransactionLine.php`, `app/Models/StockMovement.php`, `database/factories/StockTransactionFactory.php`, `tests/Feature/Ledger/LedgerSchemaTest.php`
- Modify: `composer.json`, `composer.lock`, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: `Personnel`, `Equipment`, `WithdrawalPurpose`, `Part`, `User` and `prevent_append_only_mutation()`.
- Produces:
  - The `stock_transactions` table, including every constraint from design §4.3. `transaction_no` stays nullable until completion, enforced by `posted_is_numbered`.
  - The `stock_transaction_lines` table with a `direction smallint` of -1 or 1.
  - The `stock_movements` table, append-only, with one opening movement per part.
  - `StockTransactionLine::signedQuantity(): BigDecimal`.
  - The `StockTransactionFactory` default (a fully attributed draft withdrawal) and the states `replenishment()`, `adjustment(ReasonCode $reason = Miscount)`, `opening()`, `backdated(CarbonImmutable $occurredAt)` and `withLines(list<array{0: Part, 1: string, 2?: int}> $lines)`.

- [ ] **Step 1: Require brick/math directly**

Laravel already installs it, but SPIMS uses it directly for quantities:

```bash
composer require brick/math:^0.18
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Ledger/LedgerSchemaTest.php`:

```php
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
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Ledger/LedgerSchemaTest.php
```

Expected: FAIL with `Class "App\Models\StockTransaction" not found`.

- [ ] **Step 4: Create the enums**

Create `app/Enums/TransactionStatus.php`:

```php
<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
}
```

Create `app/Enums/ReasonCode.php`:

```php
<?php

namespace App\Enums;

enum ReasonCode: string
{
    case Miscount = 'miscount';
    case Damage = 'damage';
    case UnrecordedIssue = 'unrecorded_issue';
    case UnrecordedReceipt = 'unrecorded_receipt';
    case FoundStock = 'found_stock';
    case Shrinkage = 'shrinkage';
    case Other = 'other';
}
```

Create `app/Enums/IdentificationMethod.php`:

```php
<?php

namespace App\Enums;

enum IdentificationMethod: string
{
    case BadgeScan = 'badge_scan';
    case SupervisorManual = 'supervisor_manual';
    case PaperForm = 'paper_form';
}
```

- [ ] **Step 5: Create the migration**

Create `database/migrations/2026_09_14_100006_create_ledger_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            // Assigned when the transaction completes (PRD FR-SPIMS-007), so drafts have none.
            $table->text('transaction_no')->nullable()->unique();
            $table->text('type');
            $table->text('status')->default('draft');
            $table->foreignId('custodian_user_id')->constrained('users');
            $table->foreignId('personnel_id')->nullable()->constrained('personnel');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment');
            $table->foreignId('purpose_id')->nullable()->constrained('withdrawal_purposes');
            $table->text('supplier')->nullable();
            $table->text('reference_no')->nullable();
            $table->text('reason_code')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->nullable();
            $table->boolean('is_backdated')->default(false);
            $table->text('backdate_reason')->nullable();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('stock_transactions');
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users');
            $table->text('reversal_reason')->nullable();
            $table->text('identification_method')->nullable();
            $table->foreignId('identification_authorised_by_user_id')->nullable()->constrained('users');
            $table->text('identification_reason')->nullable();
            $table->text('paper_form_no')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transactions
              add constraint stock_transactions_type_check
                check (type in ('withdrawal', 'replenishment', 'adjustment', 'opening')),
              add constraint stock_transactions_status_check
                check (status in ('draft', 'completed', 'cancelled', 'reversed')),
              add constraint stock_transactions_reason_code_check
                check (reason_code in ('miscount', 'damage', 'unrecorded_issue', 'unrecorded_receipt',
                                       'found_stock', 'shrinkage', 'other')),
              add constraint stock_transactions_identification_method_check
                check (identification_method in ('badge_scan', 'supervisor_manual', 'paper_form')),
              add constraint posted_is_numbered check (
                status in ('draft', 'cancelled') or transaction_no is not null
              ),
              -- The accountability guarantee of proposal §2. Reversals copy attribution from the original.
              add constraint completed_withdrawal_is_attributed check (
                not (status = 'completed' and type = 'withdrawal' and reverses_transaction_id is null)
                or (personnel_id is not null and equipment_id is not null and purpose_id is not null
                    and identification_method is not null)
              ),
              add constraint completed_has_recorded_at check (
                status not in ('completed', 'reversed') or recorded_at is not null
              ),
              add constraint manual_identification_is_authorised check (
                identification_method is distinct from 'supervisor_manual'
                or (identification_authorised_by_user_id is not null and identification_reason is not null)
              ),
              add constraint backdated_has_paper_form check (not is_backdated or paper_form_no is not null),
              add constraint backdated_has_reason check (not is_backdated or backdate_reason is not null),
              add constraint adjustment_has_reason check (
                type <> 'adjustment' or status <> 'completed' or reason_code is not null
              );

            create index stock_transactions_status_type_idx on stock_transactions (status, type);
            create index stock_transactions_occurred_idx on stock_transactions (occurred_at desc);
            create index stock_transactions_personnel_idx on stock_transactions (personnel_id);
            create index stock_transactions_equipment_idx on stock_transactions (equipment_id);
            create index stock_transactions_custodian_idx on stock_transactions (custodian_user_id);
            create index stock_transactions_purpose_idx on stock_transactions (purpose_id);

            -- Indexes the reversal lookup and allows each transaction to be reversed only once.
            create unique index stock_transactions_reverses_key
              on stock_transactions (reverses_transaction_id)
              where reverses_transaction_id is not null;
            SQL);

        Schema::create('stock_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('part_id')->constrained();
            $table->decimal('quantity', 14, 3);
            // -1 removes stock, +1 adds it. Fixed by type except on adjustments (see TransactionType).
            $table->smallInteger('direction');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment');
            $table->foreignId('purpose_id')->nullable()->constrained('withdrawal_purposes');
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->unique(['stock_transaction_id', 'line_no']);
            // One line per part; the withdrawal screen merges duplicates.
            $table->unique(['stock_transaction_id', 'part_id']);
            $table->index('part_id', 'stock_transaction_lines_part_idx');
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transaction_lines
              add constraint stock_transaction_lines_quantity_positive check (quantity > 0),
              add constraint stock_transaction_lines_direction_check check (direction in (-1, 1));
            SQL);

        // THE LEDGER. Append-only, signed quantities, balance stamped in id order (design §4.5).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained();
            $table->foreignId('stock_transaction_id')->constrained();
            $table->foreignId('stock_transaction_line_id')->nullable()->constrained();
            $table->text('movement_type');
            $table->decimal('quantity', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment');
            $table->foreignId('purpose_id')->nullable()->constrained('withdrawal_purposes');
            $table->foreignId('custodian_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table stock_movements
              add constraint stock_movements_movement_type_check
                check (movement_type in ('withdrawal', 'replenishment', 'adjustment', 'opening', 'count_variance')),
              add constraint stock_movements_quantity_non_zero check (quantity <> 0);

            create index stock_movements_part_occurred_idx on stock_movements (part_id, occurred_at desc);
            create index stock_movements_occurred_idx on stock_movements (occurred_at desc);
            create index stock_movements_personnel_idx on stock_movements (personnel_id, occurred_at desc);
            create index stock_movements_equipment_idx on stock_movements (equipment_id, occurred_at desc);
            create index stock_movements_txn_idx on stock_movements (stock_transaction_id);
            create index stock_movements_purpose_idx on stock_movements (purpose_id);

            -- Consumption, depletion and monthly reports read withdrawals only.
            create index stock_movements_withdrawals_idx
              on stock_movements (part_id, occurred_at)
              where movement_type = 'withdrawal';

            -- Opening balances cannot be reversed, so a part has at most one (PRD FR-SPIMS-005).
            create unique index stock_movements_one_opening_per_part
              on stock_movements (part_id)
              where movement_type = 'opening';

            create trigger stock_movements_append_only
              before update or delete on stock_movements
              for each row execute function prevent_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_transaction_lines');
        Schema::dropIfExists('stock_transactions');
    }
};
```

- [ ] **Step 6: Create the models and factory**

Create `app/Models/StockTransaction.php`:

```php
<?php

namespace App\Models;

use App\Enums\IdentificationMethod;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Database\Factories\StockTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A withdrawal, replenishment, adjustment or opening balance. Status, number and recorded_at are
 * lifecycle fields set only by App\Domain\Ledger\StockLedger, so they are not fillable.
 *
 * @property int $id
 * @property string|null $transaction_no
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property int $custodian_user_id
 * @property int|null $personnel_id
 * @property int|null $equipment_id
 * @property int|null $purpose_id
 * @property string|null $supplier
 * @property string|null $reference_no
 * @property ReasonCode|null $reason_code
 * @property string|null $remarks
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property bool $is_backdated
 * @property string|null $backdate_reason
 * @property int|null $reverses_transaction_id
 * @property int|null $reversed_by_user_id
 * @property string|null $reversal_reason
 * @property IdentificationMethod|null $identification_method
 * @property int|null $identification_authorised_by_user_id
 * @property string|null $identification_reason
 * @property string|null $paper_form_no
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $custodian
 * @property-read Personnel|null $personnel
 * @property-read Equipment|null $equipment
 * @property-read WithdrawalPurpose|null $purpose
 * @property-read Collection<int, StockTransactionLine> $lines
 * @property-read Collection<int, StockMovement> $movements
 */
#[Fillable([
    'type', 'custodian_user_id', 'personnel_id', 'equipment_id', 'purpose_id', 'supplier', 'reference_no',
    'reason_code', 'remarks', 'occurred_at', 'is_backdated', 'backdate_reason', 'identification_method',
    'identification_authorised_by_user_id', 'identification_reason', 'paper_form_no',
])]
class StockTransaction extends Model
{
    /** @use HasFactory<StockTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'reason_code' => ReasonCode::class,
            'identification_method' => IdentificationMethod::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'is_backdated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<WithdrawalPurpose, $this>
     */
    public function purpose(): BelongsTo
    {
        return $this->belongsTo(WithdrawalPurpose::class, 'purpose_id');
    }

    /**
     * @return HasMany<StockTransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransactionLine::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
```

Create `app/Models/StockTransactionLine.php`:

```php
<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_transaction_id
 * @property int $line_no
 * @property int $part_id
 * @property string $quantity
 * @property int $direction
 * @property int|null $equipment_id
 * @property int|null $purpose_id
 * @property string|null $unit_cost
 * @property string|null $remarks
 * @property-read StockTransaction $transaction
 * @property-read Part $part
 */
#[WithoutTimestamps]
#[Fillable(['line_no', 'part_id', 'quantity', 'direction', 'equipment_id', 'purpose_id', 'unit_cost', 'remarks'])]
class StockTransactionLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'direction' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * The quantity with its stock effect applied: negative removes stock, positive adds it.
     */
    public function signedQuantity(): BigDecimal
    {
        return BigDecimal::of($this->quantity)->multipliedBy($this->direction);
    }

    /**
     * @return BelongsTo<StockTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
```

Create `app/Models/StockMovement.php`:

```php
<?php

namespace App\Models;

use App\Enums\MovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the stock ledger (design §4.1, §4.5). Append-only: a database trigger rejects every
 * UPDATE and DELETE. quantity is signed, and balance_after is the running sum of quantity for the
 * part in id order. Written only by App\Domain\Ledger\StockLedger, so nothing is fillable.
 *
 * @property int $id
 * @property int $part_id
 * @property int $stock_transaction_id
 * @property int|null $stock_transaction_line_id
 * @property MovementType $movement_type
 * @property string $quantity
 * @property string $balance_after
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int|null $personnel_id
 * @property int|null $equipment_id
 * @property int|null $purpose_id
 * @property int $custodian_user_id
 * @property CarbonImmutable $created_at
 * @property-read Part $part
 * @property-read StockTransaction $transaction
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * @return BelongsTo<StockTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
```

Create `database/factories/StockTransactionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\IdentificationMethod;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * Draft stock transactions for tests. The default is a fully attributed draft withdrawal.
 *
 * @extends Factory<StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TransactionType::Withdrawal,
            'status' => TransactionStatus::Draft,
            'custodian_user_id' => User::factory(),
            'personnel_id' => Personnel::factory(),
            'equipment_id' => Equipment::factory(),
            'purpose_id' => fn () => WithdrawalPurpose::query()->orderBy('sort_order')->valueOrFail('id'),
            'identification_method' => IdentificationMethod::BadgeScan,
            'occurred_at' => CarbonImmutable::now(),
            'is_backdated' => false,
        ];
    }

    public function replenishment(): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Replenishment,
            'personnel_id' => null,
            'equipment_id' => null,
            'purpose_id' => null,
            'identification_method' => null,
            'supplier' => fake()->company(),
            'reference_no' => fake()->unique()->numerify('DR-######'),
        ]);
    }

    public function adjustment(ReasonCode $reason = ReasonCode::Miscount): static
    {
        return $this->state([
            'type' => TransactionType::Adjustment,
            'personnel_id' => null,
            'equipment_id' => null,
            'purpose_id' => null,
            'identification_method' => null,
            'reason_code' => $reason,
        ]);
    }

    public function opening(): static
    {
        return $this->state([
            'type' => TransactionType::Opening,
            'personnel_id' => null,
            'equipment_id' => null,
            'purpose_id' => null,
            'identification_method' => null,
        ]);
    }

    /**
     * Entered later from a paper fallback form (PRD FR-SPIMS-012).
     */
    public function backdated(CarbonImmutable $occurredAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'occurred_at' => $occurredAt,
            'is_backdated' => true,
            'backdate_reason' => 'Network outage; entered from the paper fallback form',
            'paper_form_no' => fake()->unique()->numerify('PF-######'),
            'identification_method' => $attributes['type'] === TransactionType::Withdrawal ? IdentificationMethod::PaperForm : null,
        ]);
    }

    /**
     * Add lines after the transaction is created: [part, quantity] or [part, quantity, direction].
     * Direction defaults to the type's direction; adjustment lines must state theirs.
     *
     * @param  list<array{0: Part, 1: string, 2?: int}>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(function (StockTransaction $transaction) use ($lines): void {
            foreach ($lines as $index => $line) {
                $direction = $line[2] ?? $transaction->type->direction()
                    ?? throw new LogicException('Adjustment lines need an explicit direction.');

                $transaction->lines()->create([
                    'line_no' => $index + 1,
                    'part_id' => $line[0]->id,
                    'quantity' => $line[1],
                    'direction' => $direction,
                ]);
            }
        });
    }
}
```

Add `stock_transaction` to the morph map. After Tasks 2–7, `app/Providers/AppServiceProvider.php` reads in full:

```php
<?php

namespace App\Providers;

use App\Domain\Identity\BadgeReader;
use App\Domain\Identity\KeyboardWedgeBadgeReader;
use App\Domain\Settings\InstallSettings;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\Setting;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: saved settings are cached per request or queued job, never across them.
        $this->app->scoped(InstallSettings::class);

        $this->app->bind(BadgeReader::class, KeyboardWedgeBadgeReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Stable type names in audit_logs and role assignments, so renaming a class never orphans the
     * audit trail. Every model that is audited or holds roles must be listed here.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'audit_log' => AuditLog::class,
            'setting' => Setting::class,
            'withdrawal_purpose' => WithdrawalPurpose::class,
            'storage_location' => StorageLocation::class,
            'equipment' => Equipment::class,
            'part' => Part::class,
            'personnel' => Personnel::class,
            'stock_transaction' => StockTransaction::class,
        ]);
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Ledger/LedgerSchemaTest.php
```

Expected: PASS, 15 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add composer.json composer.lock app/Enums/TransactionStatus.php app/Enums/ReasonCode.php app/Enums/IdentificationMethod.php database/migrations/2026_09_14_100006_create_ledger_tables.php app/Models/StockTransaction.php app/Models/StockTransactionLine.php app/Models/StockMovement.php database/factories/StockTransactionFactory.php app/Providers/AppServiceProvider.php tests/Feature/Ledger/LedgerSchemaTest.php
git commit -m "Add stock transactions, lines and the append-only movement ledger"
```

---

## Task 8: Gap-free transaction numbering

**Files:**
- Create: `database/migrations/2026_09_14_100007_create_transaction_number_sequences_table.php`, `app/Domain/Ledger/TransactionNumberer.php`, `tests/Feature/Ledger/TransactionNumbererTest.php`, `tests/Committed/TransactionNumbererGuardTest.php`
- Modify: `phpunit.xml`, `tests/Pest.php`

**Interfaces:**
- Consumes: `InstallSettings::transactionPrefix()` and `InstallSettings::timezone()` (Task 4).
- Produces:
  - `TransactionNumberer::next(TransactionType $type, CarbonImmutable $recordedAt): string`, which returns for example `WD-2026-000123` and throws `LogicException` outside a database transaction.
  - The `tests/Committed` suite, which uses `DatabaseTruncation`.

- [ ] **Step 1: Add the Committed suite**

In `phpunit.xml`, add the suite after `Feature` so it runs last:

```xml
        <testsuite name="Committed">
            <directory>tests/Committed</directory>
        </testsuite>
```

In `tests/Pest.php`, add `use Illuminate\Foundation\Testing\DatabaseTruncation;` and this binding below the Feature binding:

```php
// Tests whose writes must really commit: another connection or process has to see them, or the code
// under test must run outside a wrapping transaction. They truncate tables instead of rolling back.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Committed');
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Ledger/TransactionNumbererTest.php`:

```php
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
```

Create `tests/Committed/TransactionNumbererGuardTest.php`:

```php
<?php

use App\Domain\Ledger\TransactionNumberer;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;

it('refuses to issue a transaction number outside a database transaction', function () {
    expect(fn () => app(TransactionNumberer::class)->next(TransactionType::Withdrawal, CarbonImmutable::now()))
        ->toThrow(LogicException::class, 'inside the posting database transaction');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Ledger/TransactionNumbererTest.php tests/Committed/TransactionNumbererGuardTest.php
```

Expected: FAIL with `Class "App\Domain\Ledger\TransactionNumberer" not found`.

- [ ] **Step 4: Create the sequence table and the numberer**

Create `database/migrations/2026_09_14_100007_create_transaction_number_sequences_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keyed by prefix, not type: renaming a prefix and later reusing an old one can never
        // produce a duplicate number.
        Schema::create('transaction_number_sequences', function (Blueprint $table) {
            $table->text('prefix');
            $table->integer('year');
            $table->bigInteger('last_value');
            $table->primary(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_number_sequences');
    }
};
```

Create `app/Domain/Ledger/TransactionNumberer.php`:

```php
<?php

namespace App\Domain\Ledger;

use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class TransactionNumberer
{
    public function __construct(private InstallSettings $settings) {}

    /**
     * The next number for a transaction type, e.g. WD-2026-000123 (PRD FR-SPIMS-007), using the
     * install's prefix and the year in the install's time zone.
     *
     * Must run inside the posting transaction and after the stock rows are locked. The sequence row
     * stays locked until commit and a rollback returns the number, so completed transactions are
     * numbered without gaps and in commit order.
     */
    public function next(TransactionType $type, CarbonImmutable $recordedAt): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Transaction numbers can only be issued inside the posting database transaction.');
        }

        $prefix = $this->settings->transactionPrefix($type);
        $year = $recordedAt->setTimezone($this->settings->timezone())->year;

        $value = DB::scalar(
            'insert into transaction_number_sequences (prefix, year, last_value) values (?, ?, 1)
             on conflict (prefix, year) do update set last_value = transaction_number_sequences.last_value + 1
             returning last_value',
            [$prefix, $year],
            false,
        );

        return sprintf('%s-%d-%06d', $prefix, $year, $value);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Ledger/TransactionNumbererTest.php tests/Committed/TransactionNumbererGuardTest.php
```

Expected: PASS, 6 tests.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add phpunit.xml tests/Pest.php database/migrations/2026_09_14_100007_create_transaction_number_sequences_table.php app/Domain/Ledger/TransactionNumberer.php tests/Feature/Ledger/TransactionNumbererTest.php tests/Committed/TransactionNumbererGuardTest.php
git commit -m "Number completed transactions per prefix and year without gaps"
```

---

## Task 9: StockLedger — atomic posting

**Files:**
- Create: `app/Domain/Ledger/StockLedger.php`, `app/Domain/Ledger/Shortage.php`, `app/Domain/Ledger/Events/StockTransactionCompleted.php`, `app/Domain/Ledger/Exceptions/InstallNotConfigured.php`, `app/Domain/Ledger/Exceptions/InsufficientStock.php`, `app/Domain/Ledger/Exceptions/OpeningBalanceAlreadyRecorded.php`, `app/Domain/Ledger/Exceptions/StockBusy.php`, `app/Domain/Ledger/Exceptions/TransactionHasNoLines.php`, `app/Domain/Ledger/Exceptions/TransactionNotDraft.php`, `tests/Feature/Ledger/StockLedgerTest.php`
- Modify: `config/spims.php`, `tests/Pest.php`

**Interfaces:**
- Consumes:
  - `InstallSettings::isReadyForStockTransactions()`
  - `TransactionNumberer::next()`
  - `AuditLogger::record()`
  - the models and factories from Tasks 5–7
- Produces:
  - `StockLedger::complete(StockTransaction $transaction, User $actor): StockTransaction`. It returns the completed, locked instance, and it throws:
    - `InstallNotConfigured`
    - `TransactionNotDraft`
    - `TransactionHasNoLines`
    - `InsufficientStock`, whose `$shortages` is a `list<Shortage>` of partNumber, available and requested
    - `OpeningBalanceAlreadyRecorded`
    - `StockBusy`
    - `LogicException`, when a line's direction contradicts its type
  - The event `StockTransactionCompleted(public StockTransaction $transaction)`, which implements `ShouldDispatchAfterCommit`.
  - The test helpers `configureInstall(): void`, `partWithStock(string $onHand, array $attributes = []): Part` and `onHand(Part $part): ?string`.
  - Audit event `completed`.
  - Plan 3 builds `WithdrawalService` on `complete()`. Plan 4 adds reversal and count posting inside `StockLedger`, reusing `lockBalances()`, `writeMovements()` and `writeBalances()`.

- [ ] **Step 1: Add the ledger test helpers to `tests/Pest.php`**

Add these imports:

```php
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
```

Append:

```php
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
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Ledger/StockLedgerTest.php`:

```php
<?php

use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\OpeningBalanceAlreadyRecorded;
use App\Domain\Ledger\Exceptions\TransactionHasNoLines;
use App\Domain\Ledger\Exceptions\TransactionNotDraft;
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\MovementType;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->custodian = User::factory()->create();
});

it('completes a withdrawal with signed movements, resulting balances, a number and the time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 03:15:00', 'UTC'));
    $bearing = partWithStock('20');
    $belt = partWithStock('4');
    $draft = StockTransaction::factory()->withLines([[$bearing, '15'], [$belt, '4']])->create();

    $this->ledger->complete($draft, $this->custodian);

    expect($draft->fresh())
        ->status->toBe(TransactionStatus::Completed)
        ->transaction_no->toBe('WD-2026-000001')
        ->recorded_at->toEqual(CarbonImmutable::parse('2026-09-14 03:15:00', 'UTC'))
        ->occurred_at->toEqual(CarbonImmutable::parse('2026-09-14 03:15:00', 'UTC'));

    expect(StockMovement::query()->where('stock_transaction_id', $draft->id)->orderBy('id')->get()
        ->map(fn (StockMovement $movement): array => [$movement->part_id, $movement->movement_type, $movement->quantity, $movement->balance_after])
        ->all())->toBe([
            [$bearing->id, MovementType::Withdrawal, '-15.000', '5.000'],
            [$belt->id, MovementType::Withdrawal, '-4.000', '0.000'],
        ]);

    expect(onHand($bearing))->toBe('5.000')
        ->and(onHand($belt))->toBe('0.000');
});

it('records who, for which machine and why on every movement, including per-line overrides', function () {
    $part = partWithStock('10');
    $other = partWithStock('10');
    $pump = Equipment::factory()->create();
    $draft = StockTransaction::factory()->withLines([[$part, '1'], [$other, '1']])->create();
    $draft->lines()->where('part_id', $other->id)->update(['equipment_id' => $pump->id]);

    $this->ledger->complete($draft, $this->custodian);

    $movements = StockMovement::query()->where('stock_transaction_id', $draft->id)->get()->keyBy('part_id');

    expect($movements[$part->id])
        ->personnel_id->toBe($draft->personnel_id)
        ->equipment_id->toBe($draft->equipment_id)
        ->purpose_id->toBe($draft->purpose_id)
        ->custodian_user_id->toBe($draft->custodian_user_id)
        ->and($movements[$other->id]->equipment_id)->toBe($pump->id);
});

it('posts all lines or none when one line is short', function () {
    $plenty = partWithStock('50');
    $scarce = partWithStock('2', ['part_number' => 'BRG-6205']);
    $draft = StockTransaction::factory()->withLines([[$plenty, '5'], [$scarce, '3']])->create();

    expect(fn () => $this->ledger->complete($draft, $this->custodian))
        ->toThrow(InsufficientStock::class, 'Insufficient stock for BRG-6205: 2.000 available, 3.000 requested.');

    expect($draft->fresh())->status->toBe(TransactionStatus::Draft)->transaction_no->toBeNull()
        ->and(StockMovement::query()->where('stock_transaction_id', $draft->id)->exists())->toBeFalse()
        ->and(onHand($plenty))->toBe('50.000')
        ->and(onHand($scarce))->toBe('2.000');

    // The refused posting did not use up a transaction number.
    $fits = StockTransaction::factory()->withLines([[$scarce, '2']])->create();
    expect($this->ledger->complete($fits, $this->custodian)->transaction_no)->toEndWith('-000001');
});

it('adds stock for opening balances and replenishments', function () {
    $part = partWithStock('3');
    $receipt = StockTransaction::factory()->replenishment()->withLines([[$part, '12.5']])->create();

    $this->ledger->complete($receipt, $this->custodian);

    expect(onHand($part))->toBe('15.500')
        ->and(StockMovement::query()->where('part_id', $part->id)->orderBy('id')->get()
            ->map(fn (StockMovement $movement): array => [$movement->movement_type, $movement->quantity, $movement->balance_after])
            ->all())
        ->toBe([
            [MovementType::Opening, '3.000', '3.000'],
            [MovementType::Replenishment, '12.500', '15.500'],
        ]);
});

it('applies each adjustment line in its own direction', function () {
    $found = partWithStock('1');
    $damaged = partWithStock('10');
    $draft = StockTransaction::factory()->adjustment(ReasonCode::Miscount)->withLines([[$found, '2', 1], [$damaged, '3', -1]])->create();

    $this->ledger->complete($draft, $this->custodian);

    expect(onHand($found))->toBe('3.000')
        ->and(onHand($damaged))->toBe('7.000');
});

it('keeps decimal quantities exact', function () {
    $oil = partWithStock('0.3', ['unit_of_measure' => 'L']);

    $this->ledger->complete(StockTransaction::factory()->withLines([[$oil, '0.1']])->create(), $this->custodian);
    $this->ledger->complete(StockTransaction::factory()->withLines([[$oil, '0.2']])->create(), $this->custodian);

    // In floating point 0.3 - 0.1 - 0.2 is not zero, and the stock check would refuse it.
    expect(onHand($oil))->toBe('0.000');
});

it('refuses a second opening balance for a part', function () {
    $part = partWithStock('5');
    $again = StockTransaction::factory()->opening()->withLines([[$part, '5']])->create();

    expect(fn () => $this->ledger->complete($again, $this->custodian))
        ->toThrow(OpeningBalanceAlreadyRecorded::class, $part->part_number);
});

it('keeps the paper form date of a backdated entry and appends it in recording order', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 09:00:00', 'UTC'));
    $part = partWithStock('10');
    $this->ledger->complete(StockTransaction::factory()->replenishment()->withLines([[$part, '5']])->create(), $this->custodian);

    $outage = CarbonImmutable::parse('2026-09-12 20:00:00', 'UTC');
    $paper = StockTransaction::factory()->backdated($outage)->withLines([[$part, '4']])->create();

    $this->ledger->complete($paper, $this->custodian);

    expect(StockMovement::query()->where('stock_transaction_id', $paper->id)->sole())
        ->occurred_at->toEqual($outage)
        ->recorded_at->toEqual(CarbonImmutable::parse('2026-09-14 09:00:00', 'UTC'))
        // The balance when it was recorded (15 - 4), not a rewrite of history (design §4.5).
        ->balance_after->toBe('11.000')
        ->and($paper->fresh()?->occurred_at)->toEqual($outage);
});

it('refuses to complete a transaction twice', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    $this->ledger->complete($draft, $this->custodian);

    expect(fn () => $this->ledger->complete($draft, $this->custodian))->toThrow(TransactionNotDraft::class)
        ->and(onHand($part))->toBe('9.000');
});

it('refuses a draft with no lines', function () {
    expect(fn () => $this->ledger->complete(StockTransaction::factory()->create(), $this->custodian))
        ->toThrow(TransactionHasNoLines::class);
});

it('refuses a line whose direction contradicts its type', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1', 1]])->create();

    expect(fn () => $this->ledger->complete($draft, $this->custodian))
        ->toThrow(LogicException::class, 'must have direction -1');
});

it('records no stock until the company name is set', function () {
    $part = Part::factory()->create();
    $opening = StockTransaction::factory()->opening()->withLines([[$part, '5']])->create();
    app(InstallSettings::class)->set('company.name', null, $this->custodian);

    expect(fn () => $this->ledger->complete($opening, $this->custodian))->toThrow(InstallNotConfigured::class)
        ->and(StockMovement::query()->exists())->toBeFalse();
});

it('audits the completion with the acting user', function () {
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    $completed = $this->ledger->complete($draft, $this->custodian);

    expect(AuditLog::query()->where('auditable_type', 'stock_transaction')->where('auditable_id', (string) $draft->id)->sole())
        ->event->toBe('completed')
        ->user_id->toBe($this->custodian->id)
        ->new_values->toBe(['status' => 'completed', 'transaction_no' => $completed->transaction_no]);
});

it('announces each completed transaction', function () {
    Event::fake([StockTransactionCompleted::class]);
    $part = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$part, '1']])->create();

    $this->ledger->complete($draft, $this->custodian);

    Event::assertDispatched(StockTransactionCompleted::class, fn (StockTransactionCompleted $event): bool => $event->transaction->is($draft));
});

it('locks stock rows in part id order, whatever the line order, so stations cannot deadlock', function () {
    $first = partWithStock('10');
    $second = partWithStock('10');
    $draft = StockTransaction::factory()->withLines([[$second, '1'], [$first, '1']])->create();

    DB::enableQueryLog();
    $this->ledger->complete($draft, $this->custodian);

    $lock = collect(DB::getQueryLog())->first(fn (array $entry): bool => str_contains($entry['query'], 'from "part_stock"'));

    expect($lock['query'])->toBe('select * from "part_stock" where "part_id" in (?, ?) order by "part_id" asc for update')
        ->and($lock['bindings'])->toBe([$first->id, $second->id]);
});
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Ledger/StockLedgerTest.php
```

Expected: FAIL with `Class "App\Domain\Ledger\StockLedger" not found`.

- [ ] **Step 4: Add the lock timeout setting**

In `config/spims.php`, add this block after `'badge_pepper'`:

```php
    /*
    |--------------------------------------------------------------------------
    | Stock Lock Timeout
    |--------------------------------------------------------------------------
    |
    | How long a posting waits for another station's stock row locks before
    | failing with StockBusy. Postgres interval syntax: 5s or 500ms.
    |
    */

    'lock_timeout' => env('SPIMS_LOCK_TIMEOUT', '5s'),
```

- [ ] **Step 5: Create the value object, event and exceptions**

Create `app/Domain/Ledger/Shortage.php`:

```php
<?php

namespace App\Domain\Ledger;

final readonly class Shortage
{
    public function __construct(
        public string $partNumber,
        public string $available,
        public string $requested,
    ) {}
}
```

Create `app/Domain/Ledger/Events/StockTransactionCompleted.php`:

```php
<?php

namespace App\Domain\Ledger\Events;

use App\Models\StockTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Stock has changed. Listeners (alert evaluation, from Plan 5) run only after the posting commits.
 */
final readonly class StockTransactionCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(public StockTransaction $transaction) {}
}
```

Create `app/Domain/Ledger/Exceptions/InstallNotConfigured.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use DomainException;

final class InstallNotConfigured extends DomainException
{
    public function __construct()
    {
        parent::__construct('Set the company name in Install Settings before recording stock transactions.');
    }
}
```

Create `app/Domain/Ledger/Exceptions/InsufficientStock.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use App\Domain\Ledger\Shortage;
use DomainException;

final class InsufficientStock extends DomainException
{
    /**
     * @param  list<Shortage>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct(implode(' ', array_map(
            fn (Shortage $shortage): string => "Insufficient stock for {$shortage->partNumber}: {$shortage->available} available, {$shortage->requested} requested.",
            $shortages,
        )));
    }
}
```

Create `app/Domain/Ledger/Exceptions/OpeningBalanceAlreadyRecorded.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use DomainException;

final class OpeningBalanceAlreadyRecorded extends DomainException
{
    /**
     * @param  list<string>  $partNumbers
     */
    public function __construct(public readonly array $partNumbers)
    {
        parent::__construct('An opening balance can only be recorded before any other stock movement. Already has movements: '.implode(', ', $partNumbers).'.');
    }
}
```

Create `app/Domain/Ledger/Exceptions/StockBusy.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;
use Throwable;

final class StockBusy extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Another station is recording stock for these parts. Try again.', 0, $previous);
    }
}
```

Create `app/Domain/Ledger/Exceptions/TransactionHasNoLines.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use App\Models\StockTransaction;
use DomainException;

final class TransactionHasNoLines extends DomainException
{
    public function __construct(public readonly StockTransaction $transaction)
    {
        parent::__construct("Transaction {$transaction->id} has no lines to post.");
    }
}
```

Create `app/Domain/Ledger/Exceptions/TransactionNotDraft.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use App\Models\StockTransaction;
use DomainException;

final class TransactionNotDraft extends DomainException
{
    public function __construct(public readonly StockTransaction $transaction)
    {
        parent::__construct("Transaction {$transaction->id} is {$transaction->status->value}; only a draft can be completed.");
    }
}
```

- [ ] **Step 6: Create the ledger**

Create `app/Domain/Ledger/StockLedger.php`:

```php
<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\OpeningBalanceAlreadyRecorded;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\Exceptions\TransactionHasNoLines;
use App\Domain\Ledger\Exceptions\TransactionNotDraft;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PartStock;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

/**
 * The only writer of stock (design §4.1, §5.1, §5.2). Every change to part_stock.on_hand goes
 * through here, together with the signed ledger movements that explain it.
 */
final readonly class StockLedger
{
    /** Postgres SQLSTATE when lock_timeout expires. Laravel does not retry it, unlike a deadlock. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private InstallSettings $settings,
        private TransactionNumberer $numberer,
        private AuditLogger $audit,
    ) {}

    /**
     * Complete a draft in one short database transaction: lock the transaction, lock its parts'
     * stock rows in part id order, write one signed movement per line with the resulting balance,
     * update the stock cache, number the transaction and audit it. All lines post or none do.
     *
     * @throws InstallNotConfigured
     * @throws TransactionNotDraft
     * @throws TransactionHasNoLines
     * @throws InsufficientStock
     * @throws OpeningBalanceAlreadyRecorded
     * @throws StockBusy
     */
    public function complete(StockTransaction $transaction, User $actor): StockTransaction
    {
        if (! $this->settings->isReadyForStockTransactions()) {
            throw new InstallNotConfigured;
        }

        try {
            $completed = DB::transaction(fn (): StockTransaction => $this->post($transaction->id, $actor), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new StockBusy($exception);
            }

            throw $exception;
        }

        event(new StockTransactionCompleted($completed));

        return $completed;
    }

    private function post(int $transactionId, User $actor): StockTransaction
    {
        $this->applyLockTimeout();

        $transaction = StockTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

        if ($transaction->status !== TransactionStatus::Draft) {
            throw new TransactionNotDraft($transaction);
        }

        $lines = $transaction->lines()->with('part')->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            throw new TransactionHasNoLines($transaction);
        }

        $balances = $this->lockBalances($lines);
        $this->guardDirections($transaction, $lines);
        $this->guardOpeningBalance($transaction, $lines);

        // One line per part is enforced by unique (stock_transaction_id, part_id), so each part's
        // resulting balance is its locked balance plus that single line.
        $resulting = [];

        foreach ($lines as $line) {
            $resulting[$line->part_id] = $balances[$line->part_id]->plus($line->signedQuantity());
        }

        $this->guardSufficientStock($lines, $balances, $resulting);

        $recordedAt = CarbonImmutable::now();
        $occurredAt = $transaction->is_backdated ? $transaction->occurred_at : $recordedAt;

        $this->writeMovements($transaction, $lines, $resulting, $occurredAt, $recordedAt);
        $this->writeBalances($resulting, $recordedAt);

        $transaction->forceFill([
            'status' => TransactionStatus::Completed,
            'transaction_no' => $this->numberer->next($transaction->type, $recordedAt),
            'recorded_at' => $recordedAt,
            'occurred_at' => $occurredAt,
        ])->save();

        $this->audit->record(
            'completed',
            $transaction,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Completed->value, 'transaction_no' => $transaction->transaction_no],
            $actor,
        );

        return $transaction;
    }

    /**
     * A posting waits at most this long for another station's locks, then fails with StockBusy
     * instead of leaving a custodian staring at a frozen screen.
     */
    private function applyLockTimeout(): void
    {
        $timeout = Config::string('spims.lock_timeout');

        if (preg_match('/^\d+(ms|s)$/', $timeout) !== 1) {
            throw new UnexpectedValueException("spims.lock_timeout must look like 5s or 500ms, got [{$timeout}].");
        }

        DB::statement("set local lock_timeout = '{$timeout}'");
    }

    /**
     * Lock every affected stock row in one statement, ordered by part id. The same order for every
     * posting is what keeps two stations from deadlocking (design §5.2).
     *
     * @param  Collection<int, StockTransactionLine>  $lines
     * @return array<int, BigDecimal> locked on_hand by part id
     */
    private function lockBalances(Collection $lines): array
    {
        $partIds = $lines->pluck('part_id')->unique()->sort()->values();

        $stock = PartStock::query()
            ->whereIn('part_id', $partIds)
            ->orderBy('part_id')
            ->lockForUpdate()
            ->get();

        if ($stock->count() !== $partIds->count()) {
            throw new LogicException('Every part must have a part_stock row; the parts insert trigger creates it.');
        }

        return $stock
            ->mapWithKeys(fn (PartStock $row): array => [$row->part_id => BigDecimal::of($row->on_hand)])
            ->all();
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     */
    private function guardDirections(StockTransaction $transaction, Collection $lines): void
    {
        $expected = $transaction->type->direction();

        foreach ($lines as $line) {
            if ($expected !== null && $line->direction !== $expected) {
                throw new LogicException("Line {$line->line_no} of a {$transaction->type->value} must have direction {$expected}.");
            }
        }
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     */
    private function guardOpeningBalance(StockTransaction $transaction, Collection $lines): void
    {
        if ($transaction->type !== TransactionType::Opening) {
            return;
        }

        $partIdsWithMovements = StockMovement::query()
            ->whereIn('part_id', $lines->pluck('part_id'))
            ->distinct()
            ->pluck('part_id');

        if ($partIdsWithMovements->isNotEmpty()) {
            throw new OpeningBalanceAlreadyRecorded(array_values(
                $lines->whereIn('part_id', $partIdsWithMovements)
                    ->map(fn (StockTransactionLine $line): string => $line->part->part_number)
                    ->all(),
            ));
        }
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     * @param  array<int, BigDecimal>  $balances
     * @param  array<int, BigDecimal>  $resulting
     */
    private function guardSufficientStock(Collection $lines, array $balances, array $resulting): void
    {
        $shortages = [];

        foreach ($lines as $line) {
            if ($resulting[$line->part_id]->isNegative()) {
                $shortages[] = new Shortage(
                    $line->part->part_number,
                    (string) $balances[$line->part_id]->toScale(3),
                    (string) BigDecimal::of($line->quantity)->toScale(3),
                );
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStock($shortages);
        }
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     * @param  array<int, BigDecimal>  $resulting
     */
    private function writeMovements(
        StockTransaction $transaction,
        Collection $lines,
        array $resulting,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): void {
        StockMovement::query()->insert($lines->map(fn (StockTransactionLine $line): array => [
            'part_id' => $line->part_id,
            'stock_transaction_id' => $transaction->id,
            'stock_transaction_line_id' => $line->id,
            'movement_type' => $transaction->type->movementType()->value,
            'quantity' => (string) $line->signedQuantity(),
            'balance_after' => (string) $resulting[$line->part_id],
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
            'personnel_id' => $transaction->personnel_id,
            'equipment_id' => $line->equipment_id ?? $transaction->equipment_id,
            'purpose_id' => $line->purpose_id ?? $transaction->purpose_id,
            'custodian_user_id' => $transaction->custodian_user_id,
        ])->all());
    }

    /**
     * One UPDATE for all parts, to keep round trips to a remote database low (design §12: under
     * 500 ms at p95 for a ten-line withdrawal).
     *
     * @param  array<int, BigDecimal>  $resulting
     */
    private function writeBalances(array $resulting, CarbonImmutable $recordedAt): void
    {
        $rows = implode(', ', array_fill(0, count($resulting), '(cast(? as bigint), cast(? as numeric))'));
        $bindings = [$recordedAt];

        foreach ($resulting as $partId => $balance) {
            $bindings[] = $partId;
            $bindings[] = (string) $balance;
        }

        DB::update(
            "update part_stock set on_hand = v.on_hand, updated_at = ?
             from (values {$rows}) as v (part_id, on_hand)
             where part_stock.part_id = v.part_id",
            $bindings,
        );
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Ledger/StockLedgerTest.php
```

Expected: PASS, 15 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add config/spims.php tests/Pest.php app/Domain/Ledger tests/Feature/Ledger/StockLedgerTest.php
git commit -m "Post stock transactions atomically through StockLedger"
```

---

## Task 10: Ledger verification — invariant test and nightly check

**Files:**
- Create: `app/Domain/Ledger/LedgerVerifier.php`, `app/Domain/Ledger/LedgerDiscrepancy.php`, `app/Domain/Ledger/DiscrepancyKind.php`, `app/Console/Commands/VerifyLedger.php`, `tests/Feature/Ledger/LedgerVerifierTest.php`, `tests/Feature/Ledger/LedgerInvariantTest.php`
- Modify: `config/spims.php`, `routes/console.php`

**Interfaces:**
- Consumes: `StockLedger` (Task 9) and the test helpers.
- Produces:
  - `LedgerVerifier::verify(): list<LedgerDiscrepancy>`.
  - `LedgerDiscrepancy(DiscrepancyKind $kind, int $partId, string $partNumber, string $expected, string $actual, ?int $movementId = null)`.
  - `enum DiscrepancyKind { StockCache, RunningBalance }`.
  - The `ledger:verify` command, scheduled daily at `config('spims.nightly_at_utc')`. It exits 1 and logs `critical` on any discrepancy. Plan 7 turns that into an administrator notification.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Ledger/LedgerVerifierTest.php`:

```php
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
```

Create `tests/Feature/Ledger/LedgerInvariantTest.php`:

```php
<?php

use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
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
})->with([1, 2, 3]);
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Ledger/LedgerVerifierTest.php tests/Feature/Ledger/LedgerInvariantTest.php
```

Expected: FAIL with `Class "App\Domain\Ledger\LedgerVerifier" not found`.

- [ ] **Step 3: Create the verifier**

Create `app/Domain/Ledger/DiscrepancyKind.php`:

```php
<?php

namespace App\Domain\Ledger;

enum DiscrepancyKind: string
{
    /** part_stock.on_hand differs from the sum of the part's movements. */
    case StockCache = 'stock_cache';

    /** A movement's balance_after differs from the running sum of movements in id order. */
    case RunningBalance = 'running_balance';
}
```

Create `app/Domain/Ledger/LedgerDiscrepancy.php`:

```php
<?php

namespace App\Domain\Ledger;

final readonly class LedgerDiscrepancy
{
    public function __construct(
        public DiscrepancyKind $kind,
        public int $partId,
        public string $partNumber,
        public string $expected,
        public string $actual,
        public ?int $movementId = null,
    ) {}
}
```

Create `app/Domain/Ledger/LedgerVerifier.php`:

```php
<?php

namespace App\Domain\Ledger;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Re-derives every balance from the ledger (design §4.1, §13.3). Balance history is checked in id
 * order, which is recording order, not occurred_at order (design §4.5).
 */
final class LedgerVerifier
{
    /**
     * @return list<LedgerDiscrepancy>
     */
    public function verify(): array
    {
        return [...$this->stockCacheDiscrepancies(), ...$this->runningBalanceDiscrepancies()];
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function stockCacheDiscrepancies(): array
    {
        $rows = DB::select(<<<'SQL'
            select parts.id as part_id, parts.part_number, part_stock.on_hand,
                   coalesce(sum(stock_movements.quantity), 0) as ledger_sum
            from part_stock
            join parts on parts.id = part_stock.part_id
            left join stock_movements on stock_movements.part_id = part_stock.part_id
            group by parts.id, parts.part_number, part_stock.on_hand
            having part_stock.on_hand <> coalesce(sum(stock_movements.quantity), 0)
            order by parts.id
            SQL);

        return array_values(array_map(fn (stdClass $row): LedgerDiscrepancy => new LedgerDiscrepancy(
            DiscrepancyKind::StockCache,
            (int) $row->part_id,
            (string) $row->part_number,
            expected: (string) $row->ledger_sum,
            actual: (string) $row->on_hand,
        ), $rows));
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function runningBalanceDiscrepancies(): array
    {
        $rows = DB::select(<<<'SQL'
            select ledger.id as movement_id, ledger.part_id, parts.part_number, ledger.balance_after, ledger.running_sum
            from (
                select id, part_id, balance_after,
                       sum(quantity) over (partition by part_id order by id) as running_sum
                from stock_movements
            ) as ledger
            join parts on parts.id = ledger.part_id
            where ledger.balance_after <> ledger.running_sum
            order by ledger.id
            SQL);

        return array_values(array_map(fn (stdClass $row): LedgerDiscrepancy => new LedgerDiscrepancy(
            DiscrepancyKind::RunningBalance,
            (int) $row->part_id,
            (string) $row->part_number,
            expected: (string) $row->running_sum,
            actual: (string) $row->balance_after,
            movementId: (int) $row->movement_id,
        ), $rows));
    }
}
```

- [ ] **Step 4: Create the command and schedule it**

Create `app/Console/Commands/VerifyLedger.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerDiscrepancy;
use App\Domain\Ledger\LedgerVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Re-derive every stock balance from the ledger and report any divergence';

    public function handle(LedgerVerifier $verifier): int
    {
        $discrepancies = $verifier->verify();

        if ($discrepancies === []) {
            $this->components->info('Ledger verified: no discrepancies.');

            return self::SUCCESS;
        }

        $this->table(
            ['Check', 'Part', 'Movement', 'Expected', 'Actual'],
            array_map(fn (LedgerDiscrepancy $discrepancy): array => [
                $discrepancy->kind->value,
                $discrepancy->partNumber,
                $discrepancy->movementId ?? '-',
                $discrepancy->expected,
                $discrepancy->actual,
            ], $discrepancies),
        );

        Log::critical('Ledger verification found discrepancies.', [
            'discrepancies' => array_map(fn (LedgerDiscrepancy $discrepancy): array => get_object_vars($discrepancy), $discrepancies),
        ]);

        $this->components->error(count($discrepancies).' ledger discrepancies found.');

        return self::FAILURE;
    }
}
```

`config/spims.php` now reads in full:

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Badge UID Pepper
    |--------------------------------------------------------------------------
    |
    | Badge UIDs are stored as an HMAC-SHA256 keyed with this pepper (design
    | §8.2). Generate one per install with:
    |   php -r "echo bin2hex(random_bytes(32));"
    | Never change it on a live install: every enrolled badge stops matching.
    |
    */

    'badge_pepper' => env('BADGE_PEPPER'),

    /*
    |--------------------------------------------------------------------------
    | Stock Lock Timeout
    |--------------------------------------------------------------------------
    |
    | How long a posting waits for another station's stock row locks before
    | failing with StockBusy. Postgres interval syntax: 5s or 500ms.
    |
    */

    'lock_timeout' => env('SPIMS_LOCK_TIMEOUT', '5s'),

    /*
    |--------------------------------------------------------------------------
    | Nightly Jobs
    |--------------------------------------------------------------------------
    |
    | UTC time the nightly jobs run. 18:00 UTC is 02:00 in Asia/Manila.
    |
    */

    'nightly_at_utc' => env('SPIMS_NIGHTLY_AT_UTC', '18:00'),

];
```

Replace `routes/console.php` with:

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly proof that every stock balance still equals its ledger (design §13.3).
Schedule::command('ledger:verify')->dailyAt(Config::string('spims.nightly_at_utc'));
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Ledger/LedgerVerifierTest.php tests/Feature/Ledger/LedgerInvariantTest.php
```

Expected: PASS, 9 tests. The invariant test runs three seeds of 120 postings each and takes a few seconds.

- [ ] **Step 6: Prove the invariant test catches a broken ledger**

In `StockLedger::writeMovements()`, temporarily change `'balance_after' => (string) $resulting[$line->part_id],` to `'balance_after' => (string) $line->signedQuantity(),`. Then run:

```bash
php artisan test tests/Feature/Ledger/LedgerInvariantTest.php
```

Expected: FAIL for every seed. `verify()` returns `RunningBalance` discrepancies instead of `[]`. Restore the file and confirm the test passes again:

```bash
git checkout app/Domain/Ledger/StockLedger.php
php artisan test tests/Feature/Ledger/LedgerInvariantTest.php
```

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Ledger/LedgerVerifier.php app/Domain/Ledger/LedgerDiscrepancy.php app/Domain/Ledger/DiscrepancyKind.php app/Console/Commands/VerifyLedger.php config/spims.php routes/console.php tests/Feature/Ledger/LedgerVerifierTest.php tests/Feature/Ledger/LedgerInvariantTest.php
git commit -m "Prove the ledger invariant with a randomized test and a nightly check"
```

---

## Task 11: Concurrency — two stations at once

**Files:**
- Create: `tests/Committed/ConcurrentPostingTest.php`
- Modify: `tests/Pest.php`

**Interfaces:**
- Consumes: `StockLedger`, `LedgerVerifier`, the test helpers and Laravel's `invoke-serialized-closure` command (the command behind `Concurrency::run()`).
- Produces: the test helper `postConcurrently(list<int> $transactionIds, list<int> $lockedPartIds): list<string>`, used again by Plans 3 and 4.

These tests exercise behaviour Task 9 already built, so they should pass on the first run. Step 4 proves they can fail.

- [ ] **Step 1: Add the concurrent-posting helper**

`tests/Pest.php` now reads in full:

```php
<?php

use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            $post = static function (StockLedger $ledger) use ($transactionId): string {
                try {
                    $ledger->complete(StockTransaction::query()->findOrFail($transactionId), User::query()->firstOrFail());

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
```

- [ ] **Step 2: Write the tests**

Create `tests/Committed/ConcurrentPostingTest.php`:

```php
<?php

use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\TransactionStatus;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
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
```

- [ ] **Step 3: Run the tests**

```bash
php artisan config:clear && php artisan test tests/Committed
```

Expected: PASS, 4 tests. The child processes boot from `.env`, so `postConcurrently()` passes them the test database name explicitly, and `config:clear` makes sure no cached configuration overrides it.

- [ ] **Step 4: Prove the tests can fail**

Mutation 1, oversell. In `StockLedger::lockBalances()`, delete the `->lockForUpdate()` line, then run:

```bash
php artisan test tests/Committed/ConcurrentPostingTest.php --filter="last units"
```

Expected: FAIL. Both postings complete and the verifier reports a `stock_cache` discrepancy.

Mutation 2, deadlock. Restore the file with `git checkout app/Domain/Ledger/StockLedger.php`. Then replace the whole `$stock = PartStock::query()…->get();` statement in `lockBalances()` with per-line locking in line order:

```php
        $stock = new Collection($lines->map(
            fn (StockTransactionLine $line): PartStock => PartStock::query()->whereKey($line->part_id)->lockForUpdate()->firstOrFail(),
        )->all());
```

Run:

```bash
php artisan test tests/Committed/ConcurrentPostingTest.php --filter="opposite line order"
```

Expected: FAIL on the deadlock count, even though both postings completed after Laravel retried.

Restore the file and confirm the suite passes:

```bash
git checkout app/Domain/Ledger/StockLedger.php
php artisan test tests/Committed
```

- [ ] **Step 5: Run the full check and commit**

```bash
composer test
git add tests/Pest.php tests/Committed/ConcurrentPostingTest.php
git commit -m "Prove simultaneous postings cannot oversell, deadlock or hang"
```

---

## Plan 1 Is Done When

- [ ] `composer test` passes locally, and CI would run it on PHP 8.4 and 8.5 against `postgres:17`.
- [ ] `php artisan migrate:fresh --seed` on an empty database creates every table, the 4 roles, the 33 permissions and the 6 withdrawal purposes. No hand-written SQL is needed (NFR-SPIMS-013).
- [ ] `php artisan spims:create-admin you@microtech.test "Your Name"` creates an administrator who can sign in at `/login`.
- [ ] `php artisan ledger:verify` reports no discrepancies.
- [ ] The design doc amendments listed in the roadmap ("Design doc amendments made with Plan 1") are committed.

Next: write **Plan 2 — Administration and Master Data** against the code as built, following the roadmap.
