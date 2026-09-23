# SPIMS Plan 3: Withdrawal and Replenishment at the Station — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** At the withdrawal station a custodian enters spare parts by keyboard or scanner, sets equipment
and purpose, reviews the warnings, has the requesting personnel scan their badge **last**, and confirms.
A supervisor standing at the counter authorises an insufficient-stock override or a manual
identification without taking over the custodian's session. The custodian also records deliveries. All
of it works with the keyboard and the badge reader alone.

**Architecture:**
- Drafts are ordinary `stock_transactions` rows with `status = 'draft'`, so a reload or a dropped
  connection resumes the cart (NFR-SPIMS-007). Two new pieces of schema carry what a draft must
  remember: `stock_transactions.identified_at`, and a `withdrawal_overrides` row per authorised line.
- Domain classes in `app/Domain/Withdrawals` and `app/Domain/Replenishments` own the rules;
  `App\Domain\Access\CounterAuthorisation` proves a supervisor stood at the counter.
- `StockLedger` gains one new posting, `completeWithOverrides()`, which commits the override
  adjustments and the withdrawal in **one** database transaction under **one** set of stock row locks.
- Four Livewire screens (withdrawal, receipt, replenishment, part lookup) follow Plan 2's patterns.

**Tech Stack:** as Plans 2 and 2b, with no new dependency. PHP 8.4+, Laravel 13, Livewire 4 with Flux 2
(free components), PostgreSQL 17, `brick/math`, Pest 5, Larastan (PHPStan level 7) and Pint.

**Spec:** `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`, §5.1–§5.4 and §10.

**Related documents:**
- roadmap §4: `docs/superpowers/plans/2026-09-13-spims-microtech-roadmap.md`
- requirements FR-SPIMS-006 to 009, the FR-SPIMS-016 lookup, NFR-SPIMS-005, 006, 007 and 009:
  `docs/prd/Microtech_SPIMS_PRD_v1.0.md`
- Plan 1, for `StockLedger`, `TransactionNumberer` and `PersonnelBadges`:
  `docs/superpowers/plans/2026-09-13-spims-plan-01-foundation-ledger.md`
- Plan 2, for the screen, policy and role-matrix patterns:
  `docs/superpowers/plans/2026-09-19-spims-plan-02-administration-master-data.md`

## Global Constraints

Plans 1, 2 and 2b hold unchanged:

- **PHP `^8.4`** and **PostgreSQL 17** everywhere. Never SQLite.
- **`composer test` passes before every commit.** It runs Pint, PHPStan level 7 over
  `app/ bootstrap/app.php config/ database/ routes/`, then the whole Pest suite. Never add
  `@phpstan-ignore` comments or baseline entries.
  - Inside a worktree-isolated agent session the guard refuses `env -u …`, `bash script` and `awk`
    programs. Run `composer test` plainly: its output is JSON. Use small PHP scripts for text
    processing.
  - Local PHP is capped at `memory_limit=128M`, which is why `composer types:check` passes
    `--memory-limit=512M`.
- **Schema conventions:**
  - `text`, never `varchar`.
  - `timestamptz` at full precision. Never pass a precision to `timestampTz()`.
  - `numeric(14,3)` for quantities, `numeric(14,4)` for unit costs.
  - `text` + `CHECK` instead of enums.
  - Explicitly named indexes.
  - `SchemaConventionsTest` enforces the first two.
  - Postgres functions are `create or replace`, because `migrate:fresh` does not drop them.
- **Quantities are decimal strings in PHP**, with arithmetic in `Brick\Math\BigDecimal`. Never cast a
  quantity to `float` or `int`.
- **The ledger invariant:** for every part, `part_stock.on_hand = SUM(stock_movements.quantity)`. Every
  change to `on_hand` goes through `App\Domain\Ledger\StockLedger`, never a direct write.
  `stock_movements` and `audit_logs` are append-only, enforced by the
  `prevent_append_only_mutation()` trigger — nothing in this plan updates a row in either table.
- **Two distinct actors per transaction:** the **custodian** is the authenticated `User`; the
  **personnel** who received the parts never signs in and is identified by badge at commit time
  (design §3.5).
- **No company-specific values in code.** Purposes, prefixes, thresholds and the identification
  timeout are settings or seed data.
- **The morph map is enforced.** Every audited model is listed in
  `AppServiceProvider::configureMorphMap()`.
- **Every screen authorizes twice.** `mount()` and every data-changing action call
  `$this->authorize(Permission::…)`, and every screen has a role-by-action test built with
  `roleMatrix()`.
- **Nothing is deleted.** A cancelled draft stays on record as `status = 'cancelled'`.
- **A Livewire action must never be named after Livewire's own JS API** (`upload`, `get`, `set`,
  `call`, `dispatch`, `entangle`, `refresh`, …). `LivewireActionNamesTest` fails the suite if one is,
  and the failure is otherwise invisible to Pest, because `->call('x')` bypasses the JS proxy.
- **Livewire 4 names a component class called `Index` in a subfolder after the subfolder**, so
  `App\Livewire\Station\Index` would render `resources/views/livewire/station.blade.php`. This plan
  uses no `Index` under `Station`, so each component's view is `livewire/station/<name>.blade.php`.
- **Tests:**
  - Pest function syntax.
  - A test that expects a database error wraps the failing call in `inSavepoint()`.
  - Audit `old_values` and `new_values` come back from `jsonb` with keys shorter first, equal lengths
    in byte order. A multi-key `toBe()` lists keys in that order.
  - Full-page screen tests need `npm install && npm run build` in the worktree, or every
    `get(route(...))` returns 500 with a missing Vite manifest.
- **Commits:** one per task, with the message given in the task, staged by path. Never `git add -A`.

Added by this plan:

- **The scan is last, and any change undoes it.** Adding, changing or removing a line, changing the
  equipment, the purpose or the remarks, or authorising an override, all clear the identification
  (PRD FR-SPIMS-007). Exactly one method does this — `WithdrawalService::invalidate()` — and every
  mutator calls it. Do not clear identification anywhere else.
- **An identification expires.** `withdrawal.identification_timeout_seconds` (default 120) counts from
  `identified_at`. An expired draft is refused at confirmation and asks for a new scan.
- **A supervisor at the counter never replaces the custodian's session.** `CounterAuthorisation`
  verifies a password and returns the `User` it identifies. It never calls `Auth::login()`.
- **The raw badge UID never leaves `PersonnelBadges`.** Rejected scans are audited with the masked
  form only, through `BadgeIdentity::mask()`.
- **The override adjustment is computed under lock at commit, never at authorisation**, may only
  **increase** a balance, and is discarded when the locked balance already covers the line
  (design §5.3).

## Before You Start

Read these first:

- PRD FR-SPIMS-006, 007, 008 and 009 in full, including every Alternate Flow — six of this plan's
  tests come straight from them — and the FR-SPIMS-016 lookup.
- Design §5.1 (the withdrawal steps and the committed step), §5.2 (lock ordering and short
  transactions), §5.3 (the override path, in full), §5.4 and §10.
- `app/Domain/Ledger/StockLedger.php`, in full. Task 7 changes it, and every private method it has is
  reused rather than copied.
- `app/Domain/Identity/PersonnelBadges.php` and `KeyboardWedgeBadgeReader.php`.
- `database/migrations/2026_09_14_100006_create_ledger_tables.php`, for the CHECK constraints a draft
  and a completed withdrawal must satisfy.
- `app/Livewire/Parts/Index.php` and `app/Livewire/Imports/Review.php` with their views, for the
  screen pattern; `tests/Pest.php` for the helpers.

Work in a worktree off `master` (superpowers:using-git-worktrees). Copy the main checkout's `.env`
into it, then run `composer install` and `npm install && npm run build`. The main checkout has no
`vendor/` or `node_modules/`.

**How the code in this plan was checked.** It was written against `master` at `80595b3`, reading the
merged source of `StockLedger`, `TransactionNumberer`, `PersonnelBadges`, `BadgeIdentity`,
`InstallSettings`, `AuditLogger`, `Navigation`, the `Permission` and `TransactionType` enums, the
ledger migration, `StockTransactionFactory`, `tests/Pest.php`, `LivewireActionNamesTest`,
`LedgerInvariantTest` and `tests/Committed/ConcurrentPostingTest.php`. Every class name, method
signature and constraint name below is copied from that source rather than predicted.

Unlike Plan 2b, this plan's code was **not** executed in a scratch copy first. Treat every code block
as a careful draft, not as verified output: write each task's tests, watch them fail, then write the
code. If a result differs from the expected output, fix the code, keep the test's intent, and record
the deviation with its evidence in the task report.

## Decisions Made in This Plan

These settle the three the roadmap §4 left open, and the design gaps §10 assigns to Plan 3.

1. **A draft's identification time is a column; an override authorisation is its own table.**
   - `stock_transactions.identified_at` is a nullable `timestamptz`. There is at most one
     identification per withdrawal, and `personnel_id` already sits on the row.
   - Override authorisations go in **`withdrawal_overrides`**, one row per `(draft, part)`, not in
     columns on `stock_transaction_lines`. Design §5.6.3 has each override emit **its own single-line
     `adjustment` transaction**, and `stock_transactions.reason_code` is a single column — two short
     lines on one withdrawal can carry different reasons (Found Stock and Unrecorded Receipt), which
     one shared adjustment transaction could not express. The table also holds the authorisation's own
     audit facts that FR-SPIMS-008 requires (supervisor, reason, recorded balance, verified quantity)
     and its outcome at commit (`adjustment_transaction_id` or `discarded_at`).
2. **A supervisor authenticates at the counter by re-entering their own email and password**, not by a
   supervisor badge and not by a PIN.
   - Design §3.3 keeps one identity system, not two: `users` sign in, `personnel` never do. Giving a
     supervisor a badge would put a user into the personnel identity system.
   - `CounterAuthorisation::verify()` checks the password, that the account is Active, and that it
     holds the permission asked for. It returns the `User` and **never touches the custodian's
     session**, so the station stays signed in as the custodian (PRD FR-SPIMS-008 step 2).
   - It is rate limited per email and per address, 5 attempts in 15 minutes, matching the sign-in
     lockout of NFR-SPIMS-004.
   - A supervisor processing the withdrawal themselves may authorise their own override; the audit
     record then shows the same user in both roles, which FR-SPIMS-008 explicitly allows.
   - Two-factor is not re-challenged at the counter. It guards signing in; a counter authorisation is
     a second factor of a different kind — a person physically present — and a 2FA prompt at 03:00
     with a machine down is exactly the friction that drives the storeroom back to paper.
3. **Manual identification is built** (PRD FR-SPIMS-007 alternate flow, Q12 still unanswered).
   The schema already carries it — `identification_method`, `identification_authorised_by_user_id`,
   `identification_reason` and the `manual_identification_is_authorised` CHECK — so building it costs
   little and Q12 can only turn it off, which is a setting-free decision to stop using the screen. It
   needs `Permission::IdentificationAuthorise` (Inventory Supervisor only) and a reason, and marks the
   withdrawal `supervisor_manual` for the Plan 6 Exceptions report.
4. **No automated browser suite is added; the roadmap's keyboard-browser test is deferred.**
   - Neither `pestphp/pest-plugin-browser` nor Playwright is installed, and adding either brings a
     browser download into CI — a change with more operational weight than the one test it buys,
     taken on the plan that needs it across many screens rather than on this one.
   - What replaces it here: the keyboard contract is asserted at the Livewire level (a focused scan
     field, `wire:keydown.enter`, and an `->call()` per action), `LivewireActionNamesTest` keeps
     catching the class of bug that only a browser found in Plan 2b, and the **browser walk in "Plan 3
     Is Done When" is mandatory, not optional**, with the reader simulated by typing and pressing
     Enter.
   - This leaves the roadmap §4 key test "a keyboard-only browser test with the reader simulated as
     keystrokes plus Enter" **unmet**. It is carried to Plan 6 in "Notes for Later Plans", which is
     the first plan with enough screens to justify the dependency.
5. **One open draft per custodian per type.** `openDraft()` returns the custodian's existing draft
   withdrawal if there is one, and creates it otherwise, so a reload, a second tab or a dropped
   connection resumes the same cart (NFR-SPIMS-007) instead of quietly starting a second one.
   Abandoned drafts are never cleaned up automatically — nothing is deleted — and their retention is a
   Plan 7 note.
6. **`complete()` refuses a draft that holds unresolved overrides.** The station calls
   `completeWithOverrides()`; every other caller keeps calling `complete()`. Rather than let
   `complete()` silently ignore an authorisation and fail with `InsufficientStock`, it throws a
   `LogicException` naming the method to use.
7. **Each override posts its own adjustment transaction, numbered before the withdrawal**, because the
   adjustment is what makes the withdrawal possible and the numbers should read in that order.
8. **A discarded override still reports a discrepancy.** When the locked balance already covers the
   line, the withdrawal commits without the adjustment and the override is marked `discarded_at`, but
   `StockDiscrepancyReported` is dispatched anyway: the original discrepancy was real and the part
   needs counting (design §5.3, FR-SPIMS-008 alternate flow).
9. **The discrepancy is an event, not an alert.** `alerts` does not exist until Plan 5, so Task 7
   dispatches `App\Domain\Ledger\Events\StockDiscrepancyReported` after commit and Plan 5 turns it
   into an `inventory_discrepancy` alert. This is the design §10 gap "Plans 3 → 5".
10. **A replenishment is recorded on the day it is received, and Plan 3 goes no further.**
    FR-SPIMS-009 asks for a date received and forbids a future one. But `StockLedger` stamps
    `occurred_at` from the commit unless `is_backdated` is set, and `is_backdated` drags in the
    `backdated_has_paper_form` and `backdated_has_reason` CHECKs, the `backdating.limit_days` setting
    and the two backdating permissions — the FR-SPIMS-012 machinery Plan 4 owns. Inventing a second,
    weaker path to a past `occurred_at` here would leave two ways to backdate the ledger.
    So Plan 3 takes the received date, requires it to be **today in the install time zone**, and
    refuses an earlier one with a message naming the backdated-entry screen. **Recording a delivery
    from an earlier day is carried to Plan 4**, which already has to build that screen.
11. **A duplicate delivery reference warns, and confirming the screen confirms it** (FR-SPIMS-009
    alternate flow). It is not refused: the same DR number legitimately recurs across suppliers, and a
    genuine re-delivery under one reference happens.
12. **The part lookup is its own read-only screen** (`Permission::PartsView`), showing description,
    storage location and on-hand quantity. It is the FR-SPIMS-016 lookup the station needs while a
    person waits, not the movement history, which is Plan 6.

## File Map

```
app/
  Domain/
    Access/
      CounterAuthorisation.php        verify a supervisor's password at the counter         (Task 5)
      Exceptions/AuthorisationRefused.php                                                   (Task 5)
    Ledger/
      StockLedger.php                 gains completeWithOverrides()                         (Task 7)
      Events/StockDiscrepancyReported.php                                                   (Task 7)
    Replenishments/
      ReplenishmentService.php        supplier, reference, lines, duplicate warning         (Task 9)
      Exceptions/ReplenishmentRefused.php                                                   (Task 9)
    Withdrawals/
      WithdrawalService.php           draft lifecycle, lines, context, invalidate, cancel   (Task 2)
      LineReview.php, WithdrawalReview.php, LevelWarning.php   the review value objects     (Task 3)
      WithdrawalIdentification.php    badge scan, timeout, rejection audit                  (Task 4)
      Exceptions/WithdrawalRefused.php                                                      (Task 2)
  Livewire/Station/
    Withdrawal.php                    the station screen                                    (Task 10)
    Receipt.php                       the FR-SPIMS-007 transaction record                   (Task 10)
    Replenishment.php                                                                       (Task 11)
    PartLookup.php                                                                          (Task 11)
  Models/WithdrawalOverride.php                                                             (Task 1)
database/
  factories/WithdrawalOverrideFactory.php                                                   (Task 1)
  migrations/2026_09_23_100001_add_withdrawal_station_tables.php                            (Task 1)
resources/views/livewire/station/{withdrawal,receipt,replenishment,part-lookup}.blade.php   (Tasks 10, 11)
routes/spims.php                      four station routes                                   (Tasks 10, 11)
tests/
  Feature/Withdrawals/                                                                      (Tasks 1–8, 10)
  Feature/Replenishments/                                                                   (Tasks 9, 11)
  Feature/Access/CounterAuthorisationTest.php                                               (Task 5)
  Feature/Ledger/LedgerInvariantTest.php     gains the override generator                   (Task 8)
  Committed/ConcurrentPostingTest.php        gains two override races                       (Task 8)
```

---

## Task 1: Draft identification and override authorisation schema

**Files:**
- Create: `database/migrations/2026_09_23_100001_add_withdrawal_station_tables.php`,
  `app/Models/WithdrawalOverride.php`, `database/factories/WithdrawalOverrideFactory.php`,
  `tests/Feature/Withdrawals/WithdrawalSchemaTest.php`
- Modify: `app/Models/StockTransaction.php`, `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: `stock_transactions`, `parts` and `users` (Plan 1), the `ReasonCode` enum.
- Produces:
  - `stock_transactions.identified_at` (`CarbonImmutable|null`), cast as `datetime` and **not**
    fillable — only `WithdrawalIdentification` (Task 4) writes it.
  - `App\Models\WithdrawalOverride`, with `WithdrawalOverride::REASONS` and `isPending()`.
  - `StockTransaction::overrides(): HasMany<WithdrawalOverride, $this>`.
  - The morph alias `withdrawal_override`.

A draft has to remember two things the ledger tables do not yet hold: **when** the requesting
personnel's badge was scanned, and **which lines a supervisor authorised over the recorded balance**.
The first is one column. The second is a table, one row per short line, because each override posts
its own single-line adjustment transaction with its own reason (design §5.6.3) and
`stock_transactions.reason_code` is a single column.

`recorded_on_hand` is the balance at the moment of authorisation. It is **never** the basis of the
adjustment — that is computed again under lock at commit (design §5.3) — but FR-SPIMS-008 requires the
recorded balance in the audit trail, and it is what the supervisor was looking at when they decided.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/WithdrawalSchemaTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalSchemaTest.php
```

Expected: FAIL — `Class "App\Models\WithdrawalOverride" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_23_100001_add_withdrawal_station_tables.php`:

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
        Schema::table('stock_transactions', function (Blueprint $table) {
            // When the requesting personnel's badge was scanned (PRD FR-SPIMS-007). Cleared by any
            // later change to the draft, and expired by withdrawal.identification_timeout_seconds.
            $table->timestampTz('identified_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transactions
              add constraint identified_has_personnel check (
                identified_at is null or personnel_id is not null
              );
            SQL);

        // A supervisor's authorisation to issue more than the recorded balance (PRD FR-SPIMS-008).
        // One row per short line, not columns on stock_transaction_lines: each override posts its
        // own single-line adjustment transaction with its own reason (design §5.6.3), and
        // stock_transactions.reason_code is a single column.
        Schema::create('withdrawal_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained();
            // What the supervisor counted on the shelf.
            $table->decimal('verified_qty', 14, 3);
            // The balance they were shown. Kept for the audit trail; the adjustment is computed
            // again under lock at commit, never from this (design §5.3).
            $table->decimal('recorded_on_hand', 14, 3);
            $table->text('reason_code');
            $table->foreignId('authorised_by_user_id')->constrained('users');
            $table->timestampTz('authorised_at');
            // Filled at commit: the adjustment this override posted, or the time it was discarded
            // because the locked balance already covered the line.
            $table->foreignId('adjustment_transaction_id')->nullable()->constrained('stock_transactions');
            $table->timestampTz('discarded_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['stock_transaction_id', 'part_id'], 'withdrawal_overrides_transaction_part_unique');
            $table->index('part_id', 'withdrawal_overrides_part_idx');
            $table->index('authorised_by_user_id', 'withdrawal_overrides_authorised_by_idx');
        });

        DB::unprepared(<<<'SQL'
            alter table withdrawal_overrides
              -- PRD FR-SPIMS-008 step 4 offers these two reasons and no others.
              add constraint withdrawal_overrides_reason_code_check
                check (reason_code in ('found_stock', 'unrecorded_receipt')),
              add constraint withdrawal_overrides_verified_qty_positive check (verified_qty > 0),
              add constraint withdrawal_overrides_recorded_on_hand_non_negative check (recorded_on_hand >= 0),
              -- Posted or discarded at commit, never both.
              add constraint withdrawal_overrides_outcome_exclusive check (
                adjustment_transaction_id is null or discarded_at is null
              );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_overrides');

        DB::unprepared('alter table stock_transactions drop constraint identified_has_personnel');

        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropColumn('identified_at');
        });
    }
};
```

`timestampTz()` is called with no precision, so the column keeps microseconds, as
`SchemaConventionsTest` requires. Do not add `->after()`: it is a MySQL-only modifier.

- [ ] **Step 4: Write the model**

Create `app/Models/WithdrawalOverride.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReasonCode;
use Carbon\CarbonImmutable;
use Database\Factories\WithdrawalOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supervisor authorisation to issue more of a part than the recorded balance holds
 * (PRD FR-SPIMS-008, design §5.3). It is attached to a Draft withdrawal; its outcome — the
 * adjustment it posted, or the time it was discarded — is written at commit by
 * App\Domain\Ledger\StockLedger.
 *
 * @property int $id
 * @property int $stock_transaction_id
 * @property int $part_id
 * @property string $verified_qty
 * @property string $recorded_on_hand
 * @property ReasonCode $reason_code
 * @property int $authorised_by_user_id
 * @property CarbonImmutable $authorised_at
 * @property int|null $adjustment_transaction_id
 * @property CarbonImmutable|null $discarded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StockTransaction $transaction
 * @property-read Part $part
 * @property-read User $authorisedBy
 * @property-read StockTransaction|null $adjustment
 */
#[Fillable([
    'part_id', 'verified_qty', 'recorded_on_hand', 'reason_code', 'authorised_by_user_id', 'authorised_at',
])]
class WithdrawalOverride extends Model
{
    /** @use HasFactory<WithdrawalOverrideFactory> */
    use HasFactory;

    /**
     * The only reasons FR-SPIMS-008 offers, and the only two the table's CHECK allows.
     *
     * @var list<ReasonCode>
     */
    public const array REASONS = [ReasonCode::FoundStock, ReasonCode::UnrecordedReceipt];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_qty' => 'decimal:3',
            'recorded_on_hand' => 'decimal:3',
            'reason_code' => ReasonCode::class,
            'authorised_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /**
     * Still waiting for the commit that posts or discards it.
     */
    public function isPending(): bool
    {
        return $this->adjustment_transaction_id === null && $this->discarded_at === null;
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    /**
     * @return BelongsTo<StockTransaction, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'adjustment_transaction_id');
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/WithdrawalOverrideFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ReasonCode;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WithdrawalOverride>
 */
class WithdrawalOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transaction_id' => StockTransaction::factory(),
            'part_id' => Part::factory(),
            'verified_qty' => '10.000',
            'recorded_on_hand' => '0.000',
            'reason_code' => ReasonCode::FoundStock,
            'authorised_by_user_id' => User::factory(),
            'authorised_at' => CarbonImmutable::now(),
        ];
    }
}
```

- [ ] **Step 6: Wire the model into `StockTransaction` and the morph map**

In `app/Models/StockTransaction.php`, add to the class docblock, after
`@property string|null $paper_form_no`:

```php
 * @property CarbonImmutable|null $identified_at
```

and after `@property-read Collection<int, StockMovement> $movements`:

```php
 * @property-read Collection<int, WithdrawalOverride> $overrides
```

Add `'identified_at' => 'datetime',` to `casts()`, directly after `'recorded_at' => 'datetime',`.
Leave `#[Fillable]` alone: like `status` and `transaction_no`, `identified_at` is a lifecycle field
written only by its own service.

Add the relation after `movements()`:

```php
    /**
     * Supervisor authorisations to issue more than the recorded balance (PRD FR-SPIMS-008).
     *
     * @return HasMany<WithdrawalOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(WithdrawalOverride::class);
    }
```

In `app/Providers/AppServiceProvider.php`, add to `configureMorphMap()`, after
`'import_batch' => ImportBatch::class,`:

```php
            'withdrawal_override' => WithdrawalOverride::class,
```

with the matching `use App\Models\WithdrawalOverride;` import.

- [ ] **Step 7: Run the test to verify it passes**

```bash
php artisan migrate
php artisan test tests/Feature/Withdrawals/WithdrawalSchemaTest.php tests/Feature/SchemaConventionsTest.php
```

Expected: PASS, 11 new tests (the reason-code test is a dataset of four), and the schema conventions
test still green — `withdrawal_overrides` uses `text` and full-precision `timestamptz` throughout.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_23_100001_add_withdrawal_station_tables.php app/Models/WithdrawalOverride.php database/factories/WithdrawalOverrideFactory.php app/Models/StockTransaction.php app/Providers/AppServiceProvider.php tests/Feature/Withdrawals/WithdrawalSchemaTest.php
git commit -m "Record a draft's identification time and its supervisor overrides"
```

---

## Task 2: `WithdrawalService` — the draft, its lines and its context

**Files:**
- Create: `app/Domain/Withdrawals/WithdrawalService.php`,
  `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`,
  `tests/Feature/Withdrawals/WithdrawalServiceTest.php`

**Interfaces:**
- Consumes: `StockTransaction`, `StockTransactionLine`, `Part`, `Equipment`, `WithdrawalPurpose`,
  `WithdrawalOverride` (Task 1), `AuditLogger::record()`.
- Produces `App\Domain\Withdrawals\WithdrawalService` with:
  - `openDraft(User $custodian): StockTransaction`
  - `addLine(StockTransaction $draft, Part $part, string $quantity): StockTransactionLine`
  - `setLineQuantity(StockTransaction $draft, StockTransactionLine $line, string $quantity): void`
  - `removeLine(StockTransaction $draft, StockTransactionLine $line): void`
  - `setContext(StockTransaction $draft, ?Equipment $equipment, ?WithdrawalPurpose $purpose, ?string $remarks): void`
  - `setLineContext(StockTransaction $draft, StockTransactionLine $line, ?Equipment $equipment, ?WithdrawalPurpose $purpose): void`
  - `cancel(StockTransaction $draft, User $actor): void`
  - `guardReadyToConfirm(StockTransaction $draft): void`
  - `invalidate(StockTransaction $draft): void`
- Produces `App\Domain\Withdrawals\Exceptions\WithdrawalRefused` with the named constructors listed
  in Step 3. Tasks 4, 5 and 6 add more to the same class.

This is PRD FR-SPIMS-006 in full: a Draft that changes no stock balance, with the rules that keep it
honest. The one method worth reading twice is `invalidate()` — every mutator calls it, and it is the
only place a draft's identification is cleared and its stale overrides removed. If a later task clears
identification anywhere else, the two will drift and the scan will stop being last.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/WithdrawalServiceTest.php`:

```php
<?php

use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(WithdrawalService::class);
    $this->purpose = WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    $this->equipment = Equipment::factory()->create();
});

/**
 * A draft that has been scanned: identified_at set and personnel attached, as Task 4 leaves it.
 */
function identified(StockTransaction $draft): StockTransaction
{
    $draft->forceFill([
        'personnel_id' => Personnel::factory()->create()->id,
        'identified_at' => CarbonImmutable::now(),
    ])->save();

    return $draft;
}

it('opens one draft per custodian and returns the same one again', function () {
    $first = $this->service->openDraft($this->custodian);
    $second = $this->service->openDraft($this->custodian);

    expect($second->is($first))->toBeTrue()
        ->and($first->status)->toBe(TransactionStatus::Draft)
        ->and(StockTransaction::query()->count())->toBe(1);
});

it('gives each custodian their own draft', function () {
    $other = User::factory()->create();

    expect($this->service->openDraft($this->custodian)->is($this->service->openDraft($other)))->toBeFalse();
});

it('adds a line that removes stock', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();

    $line = $this->service->addLine($draft, $part, '3.5');

    expect($line->part_id)->toBe($part->id)
        ->and($line->quantity)->toBe('3.500')
        ->and($line->direction)->toBe(-1)
        ->and($line->line_no)->toBe(1);
});

it('merges the same part entered twice into one line with the total', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();

    $this->service->addLine($draft, $part, '2');
    $this->service->addLine($draft, $part, '1.250');

    expect($draft->lines()->count())->toBe(1)
        ->and($draft->lines()->firstOrFail()->quantity)->toBe('3.250');
});

it('numbers a second part as the next line', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->addLine($draft, Part::factory()->create(), '1');
    $second = $this->service->addLine($draft, Part::factory()->create(), '1');

    expect($second->line_no)->toBe(2);
});

it('refuses an inactive part, naming it', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create(['is_active' => false, 'part_number' => 'BRG-6204']);

    expect(fn () => $this->service->addLine($draft, $part, '1'))
        ->toThrow(WithdrawalRefused::class, 'BRG-6204');
});

it('refuses a quantity of zero or less', function (string $quantity) {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $this->service->addLine($draft, Part::factory()->create(), $quantity))
        ->toThrow(WithdrawalRefused::class);
})->with(['zero' => '0', 'negative' => '-1', 'blank' => ' ', 'words' => 'two']);

it('changes and removes a line', function () {
    $draft = $this->service->openDraft($this->custodian);
    $line = $this->service->addLine($draft, Part::factory()->create(), '5');

    $this->service->setLineQuantity($draft, $line, '2');
    expect($line->fresh()->quantity)->toBe('2.000');

    $this->service->removeLine($draft, $line);
    expect($draft->lines()->count())->toBe(0);
});

it('refuses a line that belongs to another draft', function () {
    $draft = $this->service->openDraft($this->custodian);
    $other = StockTransaction::factory()->withLines([[Part::factory()->create(), '1']])->create();

    expect(fn () => $this->service->removeLine($draft, $other->lines()->firstOrFail()))
        ->toThrow(WithdrawalRefused::class);
});

it('sets the equipment, purpose and remarks of the whole withdrawal', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->setContext($draft, $this->equipment, $this->purpose, 'Bearing seized');

    expect($draft->fresh())
        ->equipment_id->toBe($this->equipment->id)
        ->purpose_id->toBe($this->purpose->id)
        ->remarks->toBe('Bearing seized');
});

it('sets the equipment and purpose of one line', function () {
    $draft = $this->service->openDraft($this->custodian);
    $line = $this->service->addLine($draft, Part::factory()->create(), '1');
    $other = Equipment::factory()->create();

    $this->service->setLineContext($draft, $line, $other, $this->purpose);

    expect($line->fresh())->equipment_id->toBe($other->id)->purpose_id->toBe($this->purpose->id);
});

it('refuses inactive equipment and inactive purposes', function () {
    $draft = $this->service->openDraft($this->custodian);
    $retired = Equipment::factory()->create(['is_active' => false, 'code' => 'CNC-99']);
    $withdrawn = WithdrawalPurpose::query()->create(['code' => 'retired', 'name' => 'Retired purpose', 'is_active' => false]);

    expect(fn () => $this->service->setContext($draft, $retired, $this->purpose, null))
        ->toThrow(WithdrawalRefused::class, 'CNC-99')
        ->and(fn () => $this->service->setContext($draft, $this->equipment, $withdrawn, null))
        ->toThrow(WithdrawalRefused::class, 'Retired purpose');
});

it('cancels a draft and audits it', function () {
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, Part::factory()->create(), '1');

    $this->service->cancel($draft, $this->custodian);

    expect($draft->fresh()->status)->toBe(TransactionStatus::Cancelled)
        ->and(AuditLog::query()->where('auditable_type', 'stock_transaction')->where('event', 'cancelled')->count())->toBe(1);
});

it('refuses to change a draft that is no longer a draft', function () {
    $draft = $this->service->openDraft($this->custodian);
    $this->service->cancel($draft, $this->custodian);

    expect(fn () => $this->service->addLine($draft, Part::factory()->create(), '1'))
        ->toThrow(WithdrawalRefused::class);
});

it('clears the identification on every change to the draft', function (Closure $change) {
    $draft = identified($this->service->openDraft($this->custodian));
    $line = $this->service->addLine($draft, Part::factory()->create(), '4');
    $draft = identified($draft->fresh());

    $change($this->service, $draft, $line);

    expect($draft->fresh())->identified_at->toBeNull()->personnel_id->toBeNull();
})->with([
    'adding a line' => [fn (WithdrawalService $s, StockTransaction $d) => $s->addLine($d, Part::factory()->create(), '1')],
    'changing a quantity' => [fn (WithdrawalService $s, StockTransaction $d, $line) => $s->setLineQuantity($d, $line, '1')],
    'removing a line' => [fn (WithdrawalService $s, StockTransaction $d, $line) => $s->removeLine($d, $line)],
    'setting the context' => [fn (WithdrawalService $s, StockTransaction $d) => $s->setContext($d, null, null, 'note')],
    'setting a line context' => [fn (WithdrawalService $s, StockTransaction $d, $line) => $s->setLineContext($d, $line, null, null)],
]);

it('keeps an override while the line stays within the verified quantity', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();
    $line = $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
    ]);

    $this->service->setLineQuantity($draft, $line, '4');

    expect($draft->overrides()->count())->toBe(1);
});

it('removes an override when the line is raised beyond the verified quantity', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();
    $line = $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
    ]);

    $this->service->setLineQuantity($draft, $line, '5.001');

    expect($draft->overrides()->count())->toBe(0);
});

it('removes an override when its line is removed', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();
    $line = $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
    ]);

    $this->service->removeLine($draft, $line);

    expect($draft->overrides()->count())->toBe(0);
});

it('refuses to confirm a draft that is not complete', function (Closure $prepare, string $expected) {
    $draft = $this->service->openDraft($this->custodian);
    $prepare($this->service, $draft, $this->equipment, $this->purpose);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))
        ->toThrow(WithdrawalRefused::class, $expected);
})->with([
    'no lines' => [fn () => null, 'at least one spare part'],
    'no equipment' => [
        function (WithdrawalService $s, StockTransaction $d, Equipment $e, WithdrawalPurpose $p) {
            $s->addLine($d, Part::factory()->create(), '1');
            $s->setContext($d, null, $p, null);
        },
        'Equipment is required',
    ],
    'no purpose' => [
        function (WithdrawalService $s, StockTransaction $d, Equipment $e) {
            $s->addLine($d, Part::factory()->create(), '1');
            $s->setContext($d, $e, null, null);
        },
        'purpose is required',
    ],
]);

it('requires remarks when the purpose asks for them', function () {
    $purpose = WithdrawalPurpose::query()->create([
        'code' => 'other_activity',
        'name' => 'Other Maintenance Activity',
        'requires_remarks' => true,
    ]);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, Part::factory()->create(), '1');
    $this->service->setContext($draft, $this->equipment, $purpose, null);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'Other Maintenance Activity');

    $this->service->setContext($draft, $this->equipment, $purpose, 'Trial fit on the new jig');

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))->not->toThrow(WithdrawalRefused::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalServiceTest.php
```

Expected: FAIL — `Class "App\Domain\Withdrawals\WithdrawalService" does not exist`.

- [ ] **Step 3: Write the refusal**

Create `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`:

```php
<?php

namespace App\Domain\Withdrawals\Exceptions;

use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\WithdrawalPurpose;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-006, 007 or 008 stopped a change to a draft withdrawal, or stopped its
 * confirmation. The message is shown to the custodian at the counter, so it names the part, the
 * equipment or the purpose at fault rather than describing the rule.
 */
final class WithdrawalRefused extends RuntimeException
{
    public static function notADraft(StockTransaction $transaction): self
    {
        return new self(__('This withdrawal is :status and can no longer be changed.', [
            'status' => $transaction->status->value,
        ]));
    }

    public static function inactivePart(Part $part): self
    {
        return new self(__('Spare part :part is Inactive and cannot be withdrawn.', [
            'part' => $part->part_number,
        ]));
    }

    public static function inactiveEquipment(Equipment $equipment): self
    {
        return new self(__('Equipment :equipment is Inactive.', ['equipment' => $equipment->code]));
    }

    public static function inactivePurpose(WithdrawalPurpose $purpose): self
    {
        return new self(__('Withdrawal purpose :purpose is Inactive.', ['purpose' => $purpose->name]));
    }

    public static function quantityNotPositive(string $quantity): self
    {
        return new self(__('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($quantity)]));
    }

    public static function lineNotOnDraft(): self
    {
        return new self(__('That line is not part of this withdrawal.'));
    }

    public static function noLines(): self
    {
        return new self(__('Add at least one spare part before confirming.'));
    }

    public static function equipmentRequired(): self
    {
        return new self(__('Equipment is required before a withdrawal can be confirmed.'));
    }

    public static function purposeRequired(): self
    {
        return new self(__('A withdrawal purpose is required before a withdrawal can be confirmed.'));
    }

    public static function remarksRequired(WithdrawalPurpose $purpose): self
    {
        return new self(__('Remarks are required for the purpose :purpose.', ['purpose' => $purpose->name]));
    }
}
```

- [ ] **Step 4: Write the service**

Create `app/Domain/Withdrawals/WithdrawalService.php`:

```php
<?php

namespace App\Domain\Withdrawals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use App\Models\WithdrawalOverride;
use App\Models\WithdrawalPurpose;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;

/**
 * A draft withdrawal and everything that can be done to it before the badge scan
 * (PRD FR-SPIMS-006). Nothing here changes a stock balance: that is StockLedger's alone.
 */
final readonly class WithdrawalService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The custodian's open draft withdrawal, created if they have none. One per custodian, so a
     * reload, a second tab or a connection that dropped mid-transaction resumes the same cart
     * (NFR-SPIMS-007) instead of quietly starting a second one. Two requests racing here would
     * create two drafts; the older one always wins afterwards, and neither holds stock.
     */
    public function openDraft(User $custodian): StockTransaction
    {
        $draft = StockTransaction::query()
            ->where('custodian_user_id', $custodian->id)
            ->where('type', TransactionType::Withdrawal)
            ->where('status', TransactionStatus::Draft)
            ->orderBy('id')
            ->first();

        return $draft ?? StockTransaction::query()->create([
            'type' => TransactionType::Withdrawal,
            'custodian_user_id' => $custodian->id,
            // Overwritten with the commit time unless the entry is backdated (Plan 4).
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Add a part, or add to the line it already has: FR-SPIMS-006 combines the same spare part
     * entered twice into one line with the total, and the table enforces it with
     * unique (stock_transaction_id, part_id).
     *
     * @throws WithdrawalRefused
     */
    public function addLine(StockTransaction $draft, Part $part, string $quantity): StockTransactionLine
    {
        $this->guardDraft($draft);
        $amount = $this->quantity($quantity);

        if (! $part->is_active) {
            throw WithdrawalRefused::inactivePart($part);
        }

        $line = $draft->lines()->where('part_id', $part->id)->first();

        if ($line instanceof StockTransactionLine) {
            $line->forceFill(['quantity' => (string) BigDecimal::of($line->quantity)->plus($amount)])->save();
        } else {
            $line = $draft->lines()->create([
                'line_no' => ((int) $draft->lines()->max('line_no')) + 1,
                'part_id' => $part->id,
                'quantity' => (string) $amount,
                // A withdrawal always removes stock (TransactionType::Withdrawal->direction()).
                'direction' => -1,
            ]);
        }

        $this->invalidate($draft);

        return $line;
    }

    /**
     * @throws WithdrawalRefused
     */
    public function setLineQuantity(StockTransaction $draft, StockTransactionLine $line, string $quantity): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->forceFill(['quantity' => (string) $this->quantity($quantity)])->save();

        $this->invalidate($draft);
    }

    /**
     * @throws WithdrawalRefused
     */
    public function removeLine(StockTransaction $draft, StockTransactionLine $line): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->delete();

        $this->invalidate($draft);
    }

    /**
     * The equipment, purpose and remarks of the whole withdrawal (FR-SPIMS-006 step 5). Whether
     * they are all present is checked at confirmation, so a custodian can fill them in any order.
     *
     * @throws WithdrawalRefused
     */
    public function setContext(
        StockTransaction $draft,
        ?Equipment $equipment,
        ?WithdrawalPurpose $purpose,
        ?string $remarks,
    ): void {
        $this->guardDraft($draft);
        $this->guardActiveContext($equipment, $purpose);

        $remarks = $remarks === null || trim($remarks) === '' ? null : trim($remarks);

        $draft->forceFill([
            'equipment_id' => $equipment?->id,
            'purpose_id' => $purpose?->id,
            'remarks' => $remarks,
        ])->save();

        $this->invalidate($draft);
    }

    /**
     * One line for a different machine or purpose (FR-SPIMS-006 alternate flow). A line's own
     * equipment and purpose win over the withdrawal's when the movement is written.
     *
     * @throws WithdrawalRefused
     */
    public function setLineContext(
        StockTransaction $draft,
        StockTransactionLine $line,
        ?Equipment $equipment,
        ?WithdrawalPurpose $purpose,
    ): void {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);
        $this->guardActiveContext($equipment, $purpose);

        $line->forceFill(['equipment_id' => $equipment?->id, 'purpose_id' => $purpose?->id])->save();

        $this->invalidate($draft);
    }

    /**
     * An abandoned withdrawal (FR-SPIMS-006 alternate flow). The draft is kept, as Cancelled:
     * nothing is deleted, and no stock movement was ever written.
     *
     * @throws WithdrawalRefused
     */
    public function cancel(StockTransaction $draft, User $actor): void
    {
        $this->guardDraft($draft);

        $draft->forceFill(['status' => TransactionStatus::Cancelled])->save();

        $this->audit->record(
            'cancelled',
            $draft,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Cancelled->value],
            $actor,
        );
    }

    /**
     * Everything FR-SPIMS-006 requires of a withdrawal before it may be confirmed. Task 4 adds the
     * identification checks of FR-SPIMS-007 to this method.
     *
     * @throws WithdrawalRefused
     */
    public function guardReadyToConfirm(StockTransaction $draft): void
    {
        $this->guardDraft($draft);

        if ($draft->lines()->count() === 0) {
            throw WithdrawalRefused::noLines();
        }

        if ($draft->equipment_id === null) {
            throw WithdrawalRefused::equipmentRequired();
        }

        $purpose = $draft->purpose;

        if (! $purpose instanceof WithdrawalPurpose) {
            throw WithdrawalRefused::purposeRequired();
        }

        if ($purpose->requires_remarks && ($draft->remarks === null || trim($draft->remarks) === '')) {
            throw WithdrawalRefused::remarksRequired($purpose);
        }
    }

    /**
     * The one place a draft's identification is cleared and its stale overrides removed. Every
     * mutator above calls it, which is what makes the badge scan the last step (FR-SPIMS-007) and
     * stops an authorisation outliving the quantity it was given for (FR-SPIMS-008). Never clear
     * either of them anywhere else.
     */
    public function invalidate(StockTransaction $draft): void
    {
        $this->pruneOverrides($draft);
        $this->clearIdentification($draft);
    }

    private function clearIdentification(StockTransaction $draft): void
    {
        if ($draft->identified_at === null && $draft->personnel_id === null) {
            return;
        }

        $draft->forceFill([
            'identified_at' => null,
            'personnel_id' => null,
            'identification_method' => null,
            'identification_authorised_by_user_id' => null,
            'identification_reason' => null,
        ])->save();
    }

    /**
     * FR-SPIMS-008: an authorisation covers a verified quantity. If its line is gone, or its
     * quantity has been raised beyond what the supervisor verified, the authorisation goes too and
     * a new one is needed.
     */
    private function pruneOverrides(StockTransaction $draft): void
    {
        $quantities = $draft->lines()->pluck('quantity', 'part_id');

        foreach ($draft->overrides()->get() as $override) {
            $quantity = $quantities->get($override->part_id);

            if ($quantity === null || BigDecimal::of($quantity)->isGreaterThan($override->verified_qty)) {
                $override->delete();
            }
        }
    }

    /**
     * @throws WithdrawalRefused
     */
    private function quantity(string $quantity): BigDecimal
    {
        try {
            $amount = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw WithdrawalRefused::quantityNotPositive($quantity);
        }

        if (! $amount->isPositive()) {
            throw WithdrawalRefused::quantityNotPositive($quantity);
        }

        return $amount;
    }

    /**
     * @throws WithdrawalRefused
     */
    private function guardDraft(StockTransaction $draft): void
    {
        if ($draft->status !== TransactionStatus::Draft) {
            throw WithdrawalRefused::notADraft($draft);
        }
    }

    /**
     * @throws WithdrawalRefused
     */
    private function guardLineOnDraft(StockTransaction $draft, StockTransactionLine $line): void
    {
        if ($line->stock_transaction_id !== $draft->id) {
            throw WithdrawalRefused::lineNotOnDraft();
        }
    }

    /**
     * @throws WithdrawalRefused
     */
    private function guardActiveContext(?Equipment $equipment, ?WithdrawalPurpose $purpose): void
    {
        if ($equipment instanceof Equipment && ! $equipment->is_active) {
            throw WithdrawalRefused::inactiveEquipment($equipment);
        }

        if ($purpose instanceof WithdrawalPurpose && ! $purpose->is_active) {
            throw WithdrawalRefused::inactivePurpose($purpose);
        }
    }
}
```

`BigDecimal::of('2')->toScale(3)` yields `2.000`, which is what the `numeric(14,3)` column stores, so
a merged line's total never drifts. `MathException` is `brick/math`'s base exception and covers both
a value that is not a number and a scale that would lose digits.

The `WithdrawalOverride` import is used by `pruneOverrides()` through the `overrides()` relation's
generic type; if PHPStan reports it unused, delete the `use` line rather than adding an ignore.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalServiceTest.php
```

Expected: PASS, 24 new tests (two datasets of four and five, one of three).

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Withdrawals/WithdrawalService.php app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php tests/Feature/Withdrawals/WithdrawalServiceTest.php
git commit -m "Prepare a draft withdrawal: lines, merging, equipment and purpose"
```

---

## Task 3: The review — resulting balances and warnings

**Files:**
- Create: `app/Enums/LevelWarning.php`, `app/Domain/Withdrawals/LineReview.php`,
  `app/Domain/Withdrawals/WithdrawalReview.php`,
  `tests/Feature/Withdrawals/WithdrawalReviewTest.php`
- Modify: `app/Domain/Withdrawals/WithdrawalService.php`

**Interfaces:**
- Consumes: `WithdrawalService` (Task 2), `WithdrawalOverride` (Task 1), `Part`, `PartStock`.
- Produces:
  - `App\Enums\LevelWarning` with cases `Critical` and `Minimum` and a `label()`.
  - `App\Domain\Withdrawals\LineReview`, a readonly value object with public
    `line`, `onHand`, `resulting`, `isShort`, `override`, `level`, and `needsOverride(): bool`.
  - `App\Domain\Withdrawals\WithdrawalReview` with `lines`, `isShort(): bool`,
    `needsOverride(): bool` and `shortPartNumbers(): list<string>`.
  - `WithdrawalService::review(StockTransaction $draft): WithdrawalReview`.

PRD FR-SPIMS-006 steps 4 and 7: the custodian sees each line's description, location, current balance
and **resulting** balance, plus a warning on any line that exceeds the available stock or would take
the part **to or below** its minimum or critical level.

Two details that are easy to get wrong:

- **A line already covered by a supervisor override shows the balance it will really leave**, which is
  `verified_qty − quantity`, not `on_hand − quantity`. Otherwise the screen keeps showing a negative
  resulting balance after the supervisor has authorised, and the custodian cannot tell whether the
  authorisation took.
- **A short line gets no level warning.** Its shortage is the warning; adding "below minimum" under a
  red "not enough stock" is noise at a counter where someone is waiting.

This is a read of the live balance, taken **outside** any transaction and holding no locks (design
§5.2). It is advisory: the balance is read again under lock at commit, and only that read decides.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/WithdrawalReviewTest.php`:

```php
<?php

use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\LevelWarning;
use App\Models\User;
use App\Models\WithdrawalOverride;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(WithdrawalService::class);
});

it('shows the balance each line will leave', function () {
    $part = partWithStock('20', ['min_level' => '2', 'critical_level' => '1']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, '7.5');

    $review = $this->service->review($draft);

    expect($review->lines)->toHaveCount(1)
        ->and($review->lines[0]->onHand)->toBe('20.000')
        ->and($review->lines[0]->resulting)->toBe('12.500')
        ->and($review->lines[0]->isShort)->toBeFalse()
        ->and($review->lines[0]->level)->toBeNull()
        ->and($review->isShort())->toBeFalse();
});

it('flags a line that exceeds the available stock', function () {
    $part = partWithStock('3', ['part_number' => 'BRG-6204']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, '5');

    $review = $this->service->review($draft);

    expect($review->lines[0]->isShort)->toBeTrue()
        ->and($review->lines[0]->needsOverride())->toBeTrue()
        ->and($review->lines[0]->level)->toBeNull()
        ->and($review->isShort())->toBeTrue()
        ->and($review->needsOverride())->toBeTrue()
        ->and($review->shortPartNumbers())->toBe(['BRG-6204']);
});

it('shows the balance an authorised override will really leave', function () {
    $part = partWithStock('3');
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '8.000',
        'recorded_on_hand' => '3.000',
    ]);

    $review = $this->service->review($draft);

    expect($review->lines[0]->isShort)->toBeTrue()
        ->and($review->lines[0]->needsOverride())->toBeFalse()
        ->and($review->lines[0]->onHand)->toBe('3.000')
        ->and($review->lines[0]->resulting)->toBe('3.000')
        ->and($review->needsOverride())->toBeFalse();
});

it('warns when a line takes a part to or below a level', function (string $onHand, string $quantity, ?LevelWarning $expected) {
    $part = partWithStock($onHand, ['min_level' => '5', 'critical_level' => '2']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, $quantity);

    expect($this->service->review($draft)->lines[0]->level)->toBe($expected);
})->with([
    'well above the minimum' => ['20', '1', null],
    'one above the minimum' => ['20', '14', null],
    'exactly the minimum' => ['20', '15', LevelWarning::Minimum],
    'below the minimum' => ['20', '16', LevelWarning::Minimum],
    'exactly the critical level' => ['20', '18', LevelWarning::Critical],
    'below the critical level' => ['20', '19', LevelWarning::Critical],
    'down to nothing' => ['20', '20', LevelWarning::Critical],
]);

it('reviews an empty draft as empty', function () {
    $draft = $this->service->openDraft($this->custodian);

    expect($this->service->review($draft)->lines)->toBe([])
        ->and($this->service->review($draft)->isShort())->toBeFalse();
});

it('reviews lines in line order', function () {
    $first = partWithStock('10', ['part_number' => 'AAA-1']);
    $second = partWithStock('10', ['part_number' => 'BBB-2']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $second, '1');
    $this->service->addLine($draft, $first, '1');

    expect(array_map(fn ($line) => $line->line->part->part_number, $this->service->review($draft)->lines))
        ->toBe(['BBB-2', 'AAA-1']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalReviewTest.php
```

Expected: FAIL — `Call to undefined method App\Domain\Withdrawals\WithdrawalService::review()`.

- [ ] **Step 3: Write the level warning enum**

Create `app/Enums/LevelWarning.php`:

```php
<?php

namespace App\Enums;

/**
 * How far a withdrawal would take a spare part down (PRD FR-SPIMS-006 step 7). Critical is the
 * more severe of the two, and a line can only carry one.
 */
enum LevelWarning: string
{
    case Critical = 'critical';
    case Minimum = 'minimum';

    public function label(): string
    {
        return match ($this) {
            self::Critical => __('At or below the critical level'),
            self::Minimum => __('At or below the minimum level'),
        };
    }
}
```

- [ ] **Step 4: Write the value objects**

Create `app/Domain/Withdrawals/LineReview.php`:

```php
<?php

namespace App\Domain\Withdrawals;

use App\Enums\LevelWarning;
use App\Models\StockTransactionLine;
use App\Models\WithdrawalOverride;

/**
 * One line of a draft withdrawal as the custodian sees it at review (PRD FR-SPIMS-006 step 4).
 * Advisory only: the balance is read again under lock at commit, and that read decides.
 */
final readonly class LineReview
{
    public function __construct(
        public StockTransactionLine $line,
        /** The recorded balance now, to three decimals. */
        public string $onHand,
        /** What the balance becomes, counting an authorised override. */
        public string $resulting,
        /** The quantity exceeds the recorded balance. */
        public bool $isShort,
        public ?WithdrawalOverride $override,
        public ?LevelWarning $level,
    ) {}

    /**
     * Short, and no supervisor has authorised it yet: the custodian must reduce the quantity,
     * remove the line, or request an override (FR-SPIMS-006 alternate flow).
     */
    public function needsOverride(): bool
    {
        return $this->isShort && ! $this->override instanceof WithdrawalOverride;
    }
}
```

Create `app/Domain/Withdrawals/WithdrawalReview.php`:

```php
<?php

namespace App\Domain\Withdrawals;

/**
 * A whole draft withdrawal as the custodian sees it at review.
 */
final readonly class WithdrawalReview
{
    /**
     * @param  list<LineReview>  $lines
     */
    public function __construct(public array $lines) {}

    public function isShort(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->isShort) {
                return true;
            }
        }

        return false;
    }

    /**
     * At least one line is short and unauthorised, so the withdrawal cannot be confirmed as it is.
     */
    public function needsOverride(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->needsOverride()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The part numbers of every short line, for a message that names them.
     *
     * @return list<string>
     */
    public function shortPartNumbers(): array
    {
        $numbers = [];

        foreach ($this->lines as $line) {
            if ($line->isShort) {
                $numbers[] = $line->line->part->part_number;
            }
        }

        return $numbers;
    }
}
```

- [ ] **Step 5: Add `review()` to the service**

In `app/Domain/Withdrawals/WithdrawalService.php`, add these imports:

```php
use App\Enums\LevelWarning;
use App\Models\PartStock;
```

and add the two methods after `guardReadyToConfirm()`:

```php
    /**
     * The draft as the custodian sees it at review (FR-SPIMS-006 steps 4 and 7): each line's
     * recorded balance, the balance it will leave, and its warning. Read outside any transaction
     * and holding no locks (design §5.2) — the balance that decides is the one read under lock at
     * commit.
     */
    public function review(StockTransaction $draft): WithdrawalReview
    {
        $lines = $draft->lines()->with(['part.stock', 'part.defaultLocation'])->orderBy('line_no')->get();
        $overrides = $draft->overrides()->get();
        $reviews = [];

        foreach ($lines as $line) {
            $override = $overrides->first(
                fn (WithdrawalOverride $override): bool => $override->part_id === $line->part_id,
            );

            $stock = $line->part->stock;
            $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');
            $quantity = BigDecimal::of($line->quantity);
            $isShort = $onHand->isLessThan($quantity);

            // An authorised override raises the balance to the verified quantity at commit, so the
            // resulting balance the custodian is shown is the one that override will really leave.
            $base = $isShort && $override instanceof WithdrawalOverride
                ? BigDecimal::of($override->verified_qty)
                : $onHand;

            $resulting = $base->minus($quantity);

            $reviews[] = new LineReview(
                line: $line,
                onHand: (string) $onHand->toScale(3),
                resulting: (string) $resulting->toScale(3),
                isShort: $isShort,
                override: $override,
                level: $this->levelWarning($line->part, $resulting),
            );
        }

        return new WithdrawalReview($reviews);
    }

    /**
     * FR-SPIMS-006 step 7: at or below the minimum or critical level, the more severe first. A
     * short line gets none — its shortage is already the warning.
     */
    private function levelWarning(Part $part, BigDecimal $resulting): ?LevelWarning
    {
        if ($resulting->isNegative()) {
            return null;
        }

        if ($resulting->isLessThanOrEqualTo($part->critical_level)) {
            return LevelWarning::Critical;
        }

        return $resulting->isLessThanOrEqualTo($part->min_level) ? LevelWarning::Minimum : null;
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalReviewTest.php tests/Feature/Withdrawals/WithdrawalServiceTest.php
```

Expected: PASS, 12 new tests (the level dataset is seven), and Task 2's tests still green.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Enums/LevelWarning.php app/Domain/Withdrawals/LineReview.php app/Domain/Withdrawals/WithdrawalReview.php app/Domain/Withdrawals/WithdrawalService.php tests/Feature/Withdrawals/WithdrawalReviewTest.php
git commit -m "Show the resulting balance and the stock warnings of a draft withdrawal"
```

---

## Task 4: The badge scan, its time limit and its rejections

**Files:**
- Create: `app/Domain/Withdrawals/WithdrawalIdentification.php`,
  `tests/Feature/Withdrawals/WithdrawalIdentificationTest.php`
- Modify: `app/Domain/Withdrawals/WithdrawalService.php`,
  `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`

**Interfaces:**
- Consumes: `PersonnelBadges::identify()`, `BadgeIdentity::mask()`, `BadgeReader::normalise()`,
  `InstallSettings::get('withdrawal.identification_timeout_seconds')`, `AuditLogger::record()`,
  `WithdrawalRefused` (Task 2).
- Produces `App\Domain\Withdrawals\WithdrawalIdentification` with:
  - `identify(StockTransaction $draft, string $rawBadge, User $actor): Personnel`
  - `clear(StockTransaction $draft): void`
  - `isCurrent(StockTransaction $draft): bool`
  - `expiresAt(StockTransaction $draft): ?CarbonImmutable`
  - `timeoutSeconds(): int`
  - the constant `MAX_SCANS_PER_MINUTE`
- Changes `WithdrawalService::invalidate()` to delegate its clearing here, and
  `guardReadyToConfirm()` to require a current identification.

Design §5.1 step 5, PRD FR-SPIMS-007. The dependency runs one way only: `WithdrawalService` uses
`WithdrawalIdentification`, never the reverse. That is what lets the private `clearIdentification()`
Task 2 wrote move here and stay the single place a draft's identification is cleared, while every
mutator still calls exactly one method, `WithdrawalService::invalidate()`.

Three rules from FR-SPIMS-007 that the code must carry, not the screen:

- A badge that is **not enrolled, or whose holder is Inactive**, is rejected and the withdrawal stays
  Draft. `PersonnelBadges::identify()` already collapses both into `null`.
- An identification **expires**. `withdrawal.identification_timeout_seconds` (default 120) counts from
  `identified_at`, and an expired draft is refused at confirmation with a message asking for a new scan.
- The **raw badge UID is never written anywhere**. A rejected scan is audited with
  `BadgeIdentity::mask()` only, and an unreadable scan with no badge value at all.

The scan field is the busiest input in the system and a stuck reader can repeat indefinitely, so scans
are rate limited per signed-in custodian.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/WithdrawalIdentificationTest.php`:

```php
<?php

use App\Domain\Identity\BadgeIdentity;
use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\IdentificationMethod;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(WithdrawalService::class);
    $this->identification = app(WithdrawalIdentification::class);
    $this->draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($this->draft, Part::factory()->create(), '1');
    $this->person = Personnel::factory()->create(['full_name' => 'Rosalie Mendoza']);
    app(PersonnelBadges::class)->enroll($this->person, 'A1234BCDE', $this->custodian);
});

afterEach(fn () => RateLimiter::clear('badge-scan:'.$this->custodian->id));

it('identifies the person who scanned and stamps the time', function () {
    $person = $this->identification->identify($this->draft, 'a1234-bcde', $this->custodian);

    expect($person->is($this->person))->toBeTrue()
        ->and($this->draft->fresh())
        ->personnel_id->toBe($this->person->id)
        ->identification_method->toBe(IdentificationMethod::BadgeScan)
        ->identified_at->not->toBeNull();
});

it('refuses a badge nobody holds and audits the rejection with the masked badge only', function () {
    expect(fn () => $this->identification->identify($this->draft, 'FFFF9999', $this->custodian))
        ->toThrow(WithdrawalRefused::class, 'not recognised');

    $audit = AuditLog::query()->where('event', 'badge_scan_rejected')->sole();

    expect($this->draft->fresh()->personnel_id)->toBeNull()
        ->and($audit->auditable_type)->toBe('stock_transaction')
        ->and($audit->new_values)->toBe([
            'reason' => 'not_recognised',
            'badge_uid_masked' => app(BadgeIdentity::class)->mask('FFFF9999'),
        ]);
});

it('refuses the badge of a person who has left', function () {
    $this->person->forceFill(['is_active' => false])->save();

    expect(fn () => $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian))
        ->toThrow(WithdrawalRefused::class, 'not recognised');
});

it('refuses unreadable reader input and records no badge value', function () {
    expect(fn () => $this->identification->identify($this->draft, '   ', $this->custodian))
        ->toThrow(WithdrawalRefused::class, 'could not be read');

    expect(AuditLog::query()->where('event', 'badge_scan_rejected')->sole()->new_values)
        ->toBe(['reason' => 'unreadable']);
});

it('stops a reader that will not stop scanning', function () {
    for ($scan = 1; $scan <= WithdrawalIdentification::MAX_SCANS_PER_MINUTE; $scan++) {
        rescue(fn () => $this->identification->identify($this->draft, 'FFFF9999', $this->custodian));
    }

    expect(fn () => $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian))
        ->toThrow(WithdrawalRefused::class, 'Too many badge scans');
});

it('expires an identification after the configured time limit', function () {
    app(InstallSettings::class)->set('withdrawal.identification_timeout_seconds', 60, $this->custodian);
    $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian);

    expect($this->identification->isCurrent($this->draft->fresh()))->toBeTrue();

    $this->travel(61)->seconds();

    expect($this->identification->isCurrent($this->draft->fresh()))->toBeFalse()
        ->and($this->identification->timeoutSeconds())->toBe(60);
});

it('clears an identification when the person at the counter is not the one on screen', function () {
    $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian);

    $this->identification->clear($this->draft);

    expect($this->draft->fresh())->identified_at->toBeNull()->personnel_id->toBeNull();
});

it('refuses to confirm a withdrawal that has not been identified', function () {
    $this->service->setContext($this->draft, \App\Models\Equipment::factory()->create(), \App\Models\WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail(), null);

    expect(fn () => $this->service->guardReadyToConfirm($this->draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'scan');
});

it('refuses to confirm a withdrawal whose identification has expired', function () {
    $this->service->setContext($this->draft, \App\Models\Equipment::factory()->create(), \App\Models\WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail(), null);
    $this->identification->identify($this->draft->fresh(), 'A1234BCDE', $this->custodian);

    $this->travel($this->identification->timeoutSeconds() + 1)->seconds();

    expect(fn () => $this->service->guardReadyToConfirm($this->draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'expired');
});

it('clears the identification when the cart changes after the scan', function () {
    $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian);

    $this->service->addLine($this->draft->fresh(), Part::factory()->create(), '1');

    expect($this->draft->fresh()->identified_at)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalIdentificationTest.php
```

Expected: FAIL — `Class "App\Domain\Withdrawals\WithdrawalIdentification" does not exist`.

- [ ] **Step 3: Add the refusals**

In `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`, add these five named constructors after
`remarksRequired()`:

```php
    public static function badgeUnreadable(): self
    {
        return new self(__('That badge could not be read. Scan it again, or ask a supervisor to identify the person.'));
    }

    public static function badgeNotRecognised(): self
    {
        return new self(__('That badge is not recognised. It may not be enrolled, or the person may no longer be Active.'));
    }

    public static function scanningTooFast(int $seconds): self
    {
        return new self(__('Too many badge scans. Wait :seconds seconds and try again.', ['seconds' => $seconds]));
    }

    public static function notIdentified(): self
    {
        return new self(__('Ask the person receiving the parts to scan their badge before confirming.'));
    }

    public static function identificationExpired(int $seconds): self
    {
        return new self(__('The badge scan expired after :seconds seconds. Ask for a new scan.', ['seconds' => $seconds]));
    }
```

- [ ] **Step 4: Write the identification service**

Create `app/Domain/Withdrawals/WithdrawalIdentification.php`:

```php
<?php

namespace App\Domain\Withdrawals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\BadgeIdentity;
use App\Domain\Identity\BadgeReader;
use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Enums\IdentificationMethod;
use App\Enums\TransactionStatus;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use UnexpectedValueException;

/**
 * Step 5 of design §5.1: the requesting personnel scans their badge, last, and the custodian sees
 * who the system thinks is standing at the counter (PRD FR-SPIMS-007). The raw badge UID never
 * leaves this class — a rejected scan is recorded masked, and an unreadable one not at all.
 */
final readonly class WithdrawalIdentification
{
    /** A stuck keyboard-wedge reader can repeat forever; this is per signed-in custodian. */
    public const int MAX_SCANS_PER_MINUTE = 20;

    public function __construct(
        private PersonnelBadges $badges,
        private BadgeReader $reader,
        private BadgeIdentity $identity,
        private InstallSettings $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Identify the person a scan belongs to and attach them to the draft.
     *
     * @throws WithdrawalRefused
     */
    public function identify(StockTransaction $draft, string $rawBadge, User $actor): Personnel
    {
        if ($draft->status !== TransactionStatus::Draft) {
            throw WithdrawalRefused::notADraft($draft);
        }

        $key = 'badge-scan:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_SCANS_PER_MINUTE)) {
            throw WithdrawalRefused::scanningTooFast(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key);

        try {
            // Normalising here as well as inside PersonnelBadges is safe — the canonical form
            // normalises to itself — and it is what lets a rejected scan be recorded masked.
            $uid = $this->reader->normalise($rawBadge);
        } catch (InvalidBadgeInput) {
            $this->reject($draft, 'unreadable', null, $actor);

            throw WithdrawalRefused::badgeUnreadable();
        }

        $personnel = $this->badges->identify($uid);

        if (! $personnel instanceof Personnel) {
            $this->reject($draft, 'not_recognised', $this->identity->mask($uid), $actor);

            throw WithdrawalRefused::badgeNotRecognised();
        }

        $draft->forceFill([
            'personnel_id' => $personnel->id,
            'identification_method' => IdentificationMethod::BadgeScan,
            'identification_authorised_by_user_id' => null,
            'identification_reason' => null,
            'identified_at' => CarbonImmutable::now(),
        ])->save();

        return $personnel;
    }

    /**
     * The single place a draft's identification is cleared. Reached from
     * WithdrawalService::invalidate() whenever the draft changes, and directly when the person at
     * the counter is not the one on screen (FR-SPIMS-007 alternate flow).
     */
    public function clear(StockTransaction $draft): void
    {
        if ($draft->identified_at === null && $draft->personnel_id === null) {
            return;
        }

        $draft->forceFill([
            'identified_at' => null,
            'personnel_id' => null,
            'identification_method' => null,
            'identification_authorised_by_user_id' => null,
            'identification_reason' => null,
        ])->save();
    }

    /**
     * When this draft's identification stops being good enough to confirm on.
     */
    public function expiresAt(StockTransaction $draft): ?CarbonImmutable
    {
        return $draft->identified_at?->addSeconds($this->timeoutSeconds());
    }

    public function isCurrent(StockTransaction $draft): bool
    {
        $expiresAt = $this->expiresAt($draft);

        return $expiresAt instanceof CarbonImmutable && $expiresAt->isFuture();
    }

    public function timeoutSeconds(): int
    {
        $seconds = $this->settings->get('withdrawal.identification_timeout_seconds');

        if (! is_int($seconds)) {
            throw new UnexpectedValueException('Install setting [withdrawal.identification_timeout_seconds] must be an integer.');
        }

        return $seconds;
    }

    /**
     * NFR-SPIMS-004: a scan that failed is worth keeping, but only in its masked form.
     */
    private function reject(StockTransaction $draft, string $reason, ?string $masked, User $actor): void
    {
        $values = ['reason' => $reason];

        if ($masked !== null) {
            $values['badge_uid_masked'] = $masked;
        }

        $this->audit->record('badge_scan_rejected', $draft, null, $values, $actor);
    }
}
```

- [ ] **Step 5: Move the clearing and require an identification to confirm**

In `app/Domain/Withdrawals/WithdrawalService.php`:

Inject the new service — replace the constructor with:

```php
    public function __construct(
        private AuditLogger $audit,
        private WithdrawalIdentification $identification,
    ) {}
```

Delete the private `clearIdentification()` method entirely, and replace `invalidate()` with:

```php
    /**
     * The one method every mutator above calls. It prunes the overrides its lines no longer justify
     * and clears the identification through WithdrawalIdentification, which is the only place that
     * clearing happens. This is what makes the badge scan the last step (FR-SPIMS-007) and stops an
     * authorisation outliving the quantity it was given for (FR-SPIMS-008).
     */
    public function invalidate(StockTransaction $draft): void
    {
        $this->pruneOverrides($draft);
        $this->identification->clear($draft);
    }
```

Add the identification checks to the end of `guardReadyToConfirm()`, after the remarks check:

```php
        if ($draft->personnel_id === null || $draft->identification_method === null) {
            throw WithdrawalRefused::notIdentified();
        }

        if (! $this->identification->isCurrent($draft)) {
            throw WithdrawalRefused::identificationExpired($this->identification->timeoutSeconds());
        }
```

- [ ] **Step 6: Fix Task 2's confirmation tests**

Task 2's "refuses to confirm a draft that is not complete" dataset and its remarks test now stop one
step earlier than before: a complete-but-unscanned draft raises `notIdentified()` rather than passing.
In `tests/Feature/Withdrawals/WithdrawalServiceTest.php`, change the last assertion of *"requires
remarks when the purpose asks for them"* from

```php
    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))->not->toThrow(WithdrawalRefused::class);
```

to

```php
    // Remarks are satisfied; the next thing missing is the badge scan of FR-SPIMS-007.
    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'scan their badge');
```

Leave the rest of that file alone: every other case throws before reaching the identification checks.

- [ ] **Step 7: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Withdrawals
```

Expected: PASS, 10 new tests, and Tasks 1–3 still green.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/Withdrawals/WithdrawalIdentification.php app/Domain/Withdrawals/WithdrawalService.php app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php tests/Feature/Withdrawals/WithdrawalIdentificationTest.php tests/Feature/Withdrawals/WithdrawalServiceTest.php
git commit -m "Identify the requesting personnel by badge, last, with a time limit"
```

---

## Task 5: A supervisor at the counter, and manual identification

**Files:**
- Create: `app/Domain/Access/CounterAuthorisation.php`,
  `app/Domain/Access/Exceptions/AuthorisationRefused.php`,
  `tests/Feature/Access/CounterAuthorisationTest.php`,
  `tests/Feature/Withdrawals/ManualIdentificationTest.php`
- Modify: `app/Domain/Withdrawals/WithdrawalIdentification.php`,
  `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`

**Interfaces:**
- Consumes: `User`, the `Permission` enum, `Hash`, `RateLimiter`, `AuditLogger::record()`.
- Produces `App\Domain\Access\CounterAuthorisation` with
  `verify(string $email, string $password, Permission $permission, string $fromAddress): User`
  and the constants `MAX_ATTEMPTS` and `DECAY_SECONDS`.
- Produces `App\Domain\Access\Exceptions\AuthorisationRefused`.
- Adds `WithdrawalIdentification::identifyManually(StockTransaction $draft, Personnel $personnel, User $supervisor, string $reason): void`.

This is the roadmap's second open decision, settled: **a supervisor re-enters their own email and
password at the station**, and the station stays signed in as the custodian. Nothing here calls
`Auth::login()`, sets a session, or issues a remember-me token. The result is a `User` to attribute an
authorisation to — the proof that a person holding the permission stood at the counter.

It serves both places a supervisor is needed: the override of Task 6, and the manual identification of
FR-SPIMS-007 built here.

Two details:

- **Email is matched ignoring case.** Plan 2 left the profile screen storing email as typed, so
  `where('email', …)` on a lowercased value would miss an account saved as `Supervisor@…`.
- **Every failed path costs an attempt**, including a correct password on an account that is
  deactivated or lacks the permission. Otherwise the lockout tells an attacker which of the three
  they got wrong.

Two-factor is deliberately not re-challenged here; see Decision 2.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Access/CounterAuthorisationTest.php`:

```php
<?php

use App\Domain\Access\CounterAuthorisation;
use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->counter = app(CounterAuthorisation::class);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->supervisor->forceFill(['email' => 'rita.ilagan@microtech.test', 'password' => Hash::make('counter-secret')])->save();
});

afterEach(fn () => RateLimiter::clear('counter-authorisation:'.Permission::OverridesAuthorise->value.'|rita.ilagan@microtech.test|10.0.0.5'));

it('identifies the supervisor standing at the counter', function () {
    $user = $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    expect($user->is($this->supervisor))->toBeTrue();
});

it('never signs the supervisor in', function () {
    $this->actingAs($custodian = userWithRoles(Role::Custodian));

    $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    expect(auth()->user()?->is($custodian))->toBeTrue();
});

it('matches the email whatever case it was saved in', function () {
    $this->supervisor->forceFill(['email' => 'Rita.Ilagan@Microtech.test'])->save();

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('refuses the wrong password, an unknown email, a deactivated account and a role without the permission', function (Closure $attempt, string $expected) {
    expect(fn () => $attempt($this->counter, $this->supervisor))->toThrow(AuthorisationRefused::class, $expected);
})->with([
    'wrong password' => [
        fn (CounterAuthorisation $c) => $c->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'),
        'do not match',
    ],
    'unknown email' => [
        fn (CounterAuthorisation $c) => $c->verify('nobody@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'),
        'do not match',
    ],
    'deactivated account' => [
        function (CounterAuthorisation $c, User $supervisor) {
            $supervisor->forceFill(['is_active' => false])->save();

            return $c->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');
        },
        'no longer Active',
    ],
    'role without the permission' => [
        function (CounterAuthorisation $c, User $supervisor) {
            $supervisor->syncRoles([Role::Custodian]);

            return $c->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');
        },
        'not allowed to authorise',
    ],
]);

it('locks the counter after repeated failures and releases it later', function () {
    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5'))
        ->toThrow(AuthorisationRefused::class, 'Too many attempts');

    $this->travel(CounterAuthorisation::DECAY_SECONDS + 1)->seconds();

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('forgets the failures once the supervisor gets it right', function () {
    rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    $this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5');

    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS - 1; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.5')->is($this->supervisor))
        ->toBeTrue();
});

it('counts each station separately', function () {
    for ($attempt = 1; $attempt <= CounterAuthorisation::MAX_ATTEMPTS; $attempt++) {
        rescue(fn () => $this->counter->verify('rita.ilagan@microtech.test', 'wrong', Permission::OverridesAuthorise, '10.0.0.5'));
    }

    expect($this->counter->verify('rita.ilagan@microtech.test', 'counter-secret', Permission::OverridesAuthorise, '10.0.0.9')->is($this->supervisor))
        ->toBeTrue();

    RateLimiter::clear('counter-authorisation:'.Permission::OverridesAuthorise->value.'|rita.ilagan@microtech.test|10.0.0.9');
});
```

Create `tests/Feature/Withdrawals/ManualIdentificationTest.php`:

```php
<?php

use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\IdentificationMethod;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->identification = app(WithdrawalIdentification::class);
    $this->service = app(WithdrawalService::class);
    $this->draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($this->draft, Part::factory()->create(), '1');
    $this->person = Personnel::factory()->create(['full_name' => 'Rosalie Mendoza']);
});

it('lets a supervisor identify a person who has no badge', function () {
    $this->identification->identifyManually($this->draft, $this->person, $this->supervisor, 'Badge left at home; verified by department head');

    expect($this->draft->fresh())
        ->personnel_id->toBe($this->person->id)
        ->identification_method->toBe(IdentificationMethod::SupervisorManual)
        ->identification_authorised_by_user_id->toBe($this->supervisor->id)
        ->identification_reason->toBe('Badge left at home; verified by department head')
        ->identified_at->not->toBeNull();
});

it('audits the manual identification', function () {
    $this->identification->identifyManually($this->draft, $this->person, $this->supervisor, 'Reader failed');

    expect(AuditLog::query()->where('event', 'identified_manually')->sole())
        ->user_id->toBe($this->supervisor->id)
        ->auditable_type->toBe('stock_transaction')
        ->new_values->toBe([
            'reason' => 'Reader failed',
            'personnel_id' => $this->person->id,
            'identification_method' => IdentificationMethod::SupervisorManual->value,
        ]);
});

it('refuses a user who may not authorise identification', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $identify = fn () => $this->identification->identifyManually($this->draft, $this->person, $user, 'Reader failed');

    $allowed
        ? expect($identify())->toBeNull()
        : expect($identify)->toThrow(WithdrawalRefused::class, 'not allowed');
})->with(roleMatrix('S'));

it('refuses a reason that is blank', function () {
    expect(fn () => $this->identification->identifyManually($this->draft, $this->person, $this->supervisor, '   '))
        ->toThrow(WithdrawalRefused::class, 'reason');
});

it('refuses a person who is no longer Active', function () {
    $this->person->forceFill(['is_active' => false])->save();

    expect(fn () => $this->identification->identifyManually($this->draft, $this->person, $this->supervisor, 'Reader failed'))
        ->toThrow(WithdrawalRefused::class, 'Rosalie Mendoza');
});

it('expires a manual identification like a scan', function () {
    $this->identification->identifyManually($this->draft, $this->person, $this->supervisor, 'Reader failed');

    $this->travel($this->identification->timeoutSeconds() + 1)->seconds();

    expect($this->identification->isCurrent($this->draft->fresh()))->toBeFalse();
});

it('is cleared by a change to the cart, like a scan', function () {
    $this->identification->identifyManually($this->draft, $this->person, $this->supervisor, 'Reader failed');

    $this->service->addLine($this->draft->fresh(), Part::factory()->create(), '1');

    expect($this->draft->fresh())->identification_method->toBeNull()->identification_reason->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Access/CounterAuthorisationTest.php tests/Feature/Withdrawals/ManualIdentificationTest.php
```

Expected: FAIL — `Class "App\Domain\Access\CounterAuthorisation" does not exist`.

- [ ] **Step 3: Write the refusal**

Create `app/Domain/Access/Exceptions/AuthorisationRefused.php`:

```php
<?php

namespace App\Domain\Access\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * A supervisor's authorisation at the station was not accepted (PRD FR-SPIMS-008 step 2). The
 * message is read out loud at a counter, so it says what to do next and never which of the email,
 * the password or the account was wrong.
 */
final class AuthorisationRefused extends RuntimeException
{
    public static function credentials(): self
    {
        return new self(__('Those credentials do not match an account that can authorise this.'));
    }

    public static function inactive(User $user): self
    {
        return new self(__('The account for :email is no longer Active.', ['email' => $user->email]));
    }

    public static function notPermitted(User $user): self
    {
        return new self(__(':name is not allowed to authorise this. Ask an Inventory Supervisor.', [
            'name' => $user->name,
        ]));
    }

    public static function tooManyAttempts(int $seconds): self
    {
        return new self(__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]));
    }
}
```

- [ ] **Step 4: Write the counter authorisation**

Create `app/Domain/Access/CounterAuthorisation.php`:

```php
<?php

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A supervisor proves they are standing at the station (PRD FR-SPIMS-008 step 2) without the
 * station ever leaving the custodian's session (design §3.5: the custodian is the attributed
 * actor). Nothing here calls Auth::login(), touches the session, or issues a remember-me token —
 * the result is a User to attribute an authorisation to, not a sign-in.
 */
final readonly class CounterAuthorisation
{
    /** Matches the sign-in lockout of NFR-SPIMS-004: five attempts, then fifteen minutes. */
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 900;

    /**
     * The user these credentials identify, if they may exercise the permission asked for.
     *
     * @param  string  $fromAddress  the station's address, so one station's failures do not lock
     *                               a supervisor out of every other station
     *
     * @throws AuthorisationRefused
     */
    public function verify(string $email, string $password, Permission $permission, string $fromAddress): User
    {
        $email = Str::lower(trim($email));
        $key = "counter-authorisation:{$permission->value}|{$email}|{$fromAddress}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw AuthorisationRefused::tooManyAttempts(RateLimiter::availableIn($key));
        }

        // Matched ignoring case: Plan 2 leaves the profile screen storing email as typed.
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user instanceof User || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthorisationRefused::credentials();
        }

        // A correct password still costs an attempt when the account cannot authorise, so the
        // lockout never tells anyone which of the three things they got wrong.
        if (! $user->is_active) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthorisationRefused::inactive($user);
        }

        if (! $user->can($permission)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthorisationRefused::notPermitted($user);
        }

        RateLimiter::clear($key);

        return $user;
    }
}
```

- [ ] **Step 5: Add manual identification**

In `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`, add these three named constructors and
the `use App\Models\Personnel;` and `use App\Models\User;` imports:

```php
    public static function notAllowedToIdentify(User $user): self
    {
        return new self(__(':name is not allowed to identify someone without a badge.', ['name' => $user->name]));
    }

    public static function identificationReasonRequired(): self
    {
        return new self(__('Give the reason the badge could not be scanned.'));
    }

    public static function inactivePersonnel(Personnel $personnel): self
    {
        return new self(__(':name is no longer Active and cannot receive spare parts.', [
            'name' => $personnel->full_name,
        ]));
    }
```

In `app/Domain/Withdrawals/WithdrawalIdentification.php`, add the imports:

```php
use App\Enums\Permission;
```

and the method, after `identify()`:

```php
    /**
     * FR-SPIMS-007 alternate flow: the person has no badge, or the reader has failed, and an
     * Inventory Supervisor identifies them by hand with a reason. The withdrawal is marked
     * supervisor_manual and appears in the Plan 6 Exceptions report. Pending Microtech's answer to
     * Q12.
     *
     * @throws WithdrawalRefused
     */
    public function identifyManually(
        StockTransaction $draft,
        Personnel $personnel,
        User $supervisor,
        string $reason,
    ): void {
        if ($draft->status !== TransactionStatus::Draft) {
            throw WithdrawalRefused::notADraft($draft);
        }

        if (! $supervisor->can(Permission::IdentificationAuthorise)) {
            throw WithdrawalRefused::notAllowedToIdentify($supervisor);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw WithdrawalRefused::identificationReasonRequired();
        }

        if (! $personnel->is_active) {
            throw WithdrawalRefused::inactivePersonnel($personnel);
        }

        $draft->forceFill([
            'personnel_id' => $personnel->id,
            'identification_method' => IdentificationMethod::SupervisorManual,
            'identification_authorised_by_user_id' => $supervisor->id,
            'identification_reason' => $reason,
            'identified_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->record('identified_manually', $draft, null, [
            'reason' => $reason,
            'personnel_id' => $personnel->id,
            'identification_method' => IdentificationMethod::SupervisorManual->value,
        ], $supervisor);
    }
```

The audit values are listed shortest key first — `reason`, `personnel_id`,
`identification_method` — which is the order `jsonb` returns them, so the test's `toBe()` matches.

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Access tests/Feature/Withdrawals
```

Expected: PASS, 17 new tests (the refusal dataset is four, the role matrix four), and Tasks 1–4 still
green.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Access/CounterAuthorisation.php app/Domain/Access/Exceptions/AuthorisationRefused.php app/Domain/Withdrawals/WithdrawalIdentification.php app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php tests/Feature/Access/CounterAuthorisationTest.php tests/Feature/Withdrawals/ManualIdentificationTest.php
git commit -m "Let a supervisor authorise at the counter without taking the station"
```

---

## Task 6: Authorising an insufficient-stock override on the draft

**Files:**
- Create: `tests/Feature/Withdrawals/OverrideAuthorisationTest.php`
- Modify: `app/Domain/Withdrawals/WithdrawalService.php`,
  `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`

**Interfaces:**
- Consumes: `WithdrawalOverride` and `WithdrawalOverride::REASONS` (Task 1), `PartStock`,
  `Permission::OverridesAuthorise`, `AuditLogger::record()`.
- Produces `WithdrawalService::authoriseOverride(StockTransaction $draft, Part $part, string $verifiedQty, ReasonCode $reason, User $supervisor): WithdrawalOverride`.

PRD FR-SPIMS-008 steps 1 to 5 and step 9: the supervisor has counted the shelf, enters what is really
there, picks Found Stock or Unrecorded Receipt, and the authorisation is attached to the **Draft**. No
stock moves here and no lock is taken — design §5.3 is explicit that authorisation is a human step and
cannot be performed while holding row locks. Task 7 does the posting.

`recorded_on_hand` is stored because FR-SPIMS-008 requires the recorded balance in the audit trail. It
is **not** what the adjustment is computed from: at commit the balance is read again under lock, and
that read decides (design §5.3). A plan reviewer should check that nothing in Task 7 reads
`recorded_on_hand` arithmetically.

Re-authorising the same part replaces the previous row rather than adding a second, because the table
holds one override per `(draft, part)` and a supervisor correcting their own count should not have to
undo anything first. `guardDraft()` is the first line of the method and the only entry point, so an
override that Task 7 has already resolved — one carrying an `adjustment_transaction_id` or a
`discarded_at` — can never be reopened by this path: its withdrawal is Completed by then, not Draft.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/OverrideAuthorisationTest.php`:

```php
<?php

use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->service = app(WithdrawalService::class);
    $this->part = partWithStock('3', ['part_number' => 'BRG-6204']);
    $this->draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($this->draft, $this->part, '5');
});

it('attaches the supervisor authorisation to the draft', function () {
    $override = $this->service->authoriseOverride($this->draft, $this->part, '8', ReasonCode::FoundStock, $this->supervisor);

    expect($override->verified_qty)->toBe('8.000')
        ->and($override->recorded_on_hand)->toBe('3.000')
        ->and($override->reason_code)->toBe(ReasonCode::FoundStock)
        ->and($override->authorised_by_user_id)->toBe($this->supervisor->id)
        ->and($override->isPending())->toBeTrue()
        ->and($this->draft->overrides()->count())->toBe(1)
        ->and(onHand($this->part))->toBe('3.000');
});

it('audits the override with the supervisor, reason, recorded balance and verified quantity', function () {
    $this->service->authoriseOverride($this->draft, $this->part, '8', ReasonCode::UnrecordedReceipt, $this->supervisor);

    expect(AuditLog::query()->where('event', 'override_authorised')->sole())
        ->user_id->toBe($this->supervisor->id)
        ->auditable_type->toBe('withdrawal_override')
        ->new_values->toBe([
            'part_id' => $this->part->id,
            'reason_code' => ReasonCode::UnrecordedReceipt->value,
            'verified_qty' => '8.000',
            'recorded_on_hand' => '3.000',
        ]);
});

it('replaces a previous authorisation for the same part', function () {
    $this->service->authoriseOverride($this->draft, $this->part, '8', ReasonCode::FoundStock, $this->supervisor);
    $second = $this->service->authoriseOverride($this->draft, $this->part, '9', ReasonCode::UnrecordedReceipt, $this->supervisor);

    expect($this->draft->overrides()->count())->toBe(1)
        ->and($second->verified_qty)->toBe('9.000')
        ->and($second->reason_code)->toBe(ReasonCode::UnrecordedReceipt);
});

it('lets only a supervisor authorise', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $authorise = fn () => $this->service->authoriseOverride($this->draft, $this->part, '8', ReasonCode::FoundStock, $user);

    $allowed
        ? expect($authorise()->authorised_by_user_id)->toBe($user->id)
        : expect($authorise)->toThrow(WithdrawalRefused::class, 'not allowed to authorise');
})->with(roleMatrix('S'));

it('lets a supervisor processing the withdrawal authorise their own override', function () {
    $draft = $this->service->openDraft($this->supervisor);
    $this->service->addLine($draft, $this->part, '5');

    $override = $this->service->authoriseOverride($draft, $this->part, '8', ReasonCode::FoundStock, $this->supervisor);

    expect($override->authorised_by_user_id)->toBe($this->supervisor->id)
        ->and($draft->custodian_user_id)->toBe($this->supervisor->id);
});

it('refuses a part that is not on the draft', function () {
    expect(fn () => $this->service->authoriseOverride($this->draft, Part::factory()->create(['part_number' => 'ZZZ-1']), '8', ReasonCode::FoundStock, $this->supervisor))
        ->toThrow(WithdrawalRefused::class, 'ZZZ-1');
});

it('refuses a verified quantity below what the line asks for', function () {
    expect(fn () => $this->service->authoriseOverride($this->draft, $this->part, '4', ReasonCode::FoundStock, $this->supervisor))
        ->toThrow(WithdrawalRefused::class, 'at least 5.000');
});

it('refuses an override on a line the balance already covers', function () {
    $stocked = partWithStock('20', ['part_number' => 'BLT-100']);
    $this->service->addLine($this->draft, $stocked, '2');

    expect(fn () => $this->service->authoriseOverride($this->draft, $stocked, '25', ReasonCode::FoundStock, $this->supervisor))
        ->toThrow(WithdrawalRefused::class, 'enough BLT-100');
});

it('refuses a reason that is not one of the two', function (ReasonCode $reason) {
    expect(fn () => $this->service->authoriseOverride($this->draft, $this->part, '8', $reason, $this->supervisor))
        ->toThrow(WithdrawalRefused::class, 'Found Stock');
})->with([
    'miscount' => ReasonCode::Miscount,
    'shrinkage' => ReasonCode::Shrinkage,
    'other' => ReasonCode::Other,
]);

it('clears an identification taken before the override', function () {
    $this->draft->forceFill([
        'personnel_id' => Personnel::factory()->create()->id,
        'identified_at' => now(),
    ])->save();

    $this->service->authoriseOverride($this->draft, $this->part, '8', ReasonCode::FoundStock, $this->supervisor);

    expect($this->draft->fresh())->identified_at->toBeNull()
        ->and($this->draft->overrides()->count())->toBe(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/OverrideAuthorisationTest.php
```

Expected: FAIL — `Call to undefined method App\Domain\Withdrawals\WithdrawalService::authoriseOverride()`.

- [ ] **Step 3: Add the refusals**

In `app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php`, add these four named constructors:

```php
    public static function notAllowedToOverride(User $user): self
    {
        return new self(__(':name is not allowed to authorise an override. Ask an Inventory Supervisor.', [
            'name' => $user->name,
        ]));
    }

    public static function partNotOnDraft(Part $part): self
    {
        return new self(__('Spare part :part is not on this withdrawal.', ['part' => $part->part_number]));
    }

    public static function verifiedQuantityTooLow(Part $part, string $quantity): self
    {
        return new self(__('The verified quantity for :part must be at least :quantity.', [
            'part' => $part->part_number,
            'quantity' => $quantity,
        ]));
    }

    public static function overrideNotNeeded(Part $part): self
    {
        return new self(__('There is already enough :part recorded; no override is needed.', [
            'part' => $part->part_number,
        ]));
    }

    public static function invalidOverrideReason(): self
    {
        return new self(__('An override reason must be Found Stock or Unrecorded Receipt.'));
    }
```

- [ ] **Step 4: Add the authorisation to the service**

In `app/Domain/Withdrawals/WithdrawalService.php`, add the imports:

```php
use App\Enums\Permission;
use App\Enums\ReasonCode;
```

and add the method after `cancel()`:

```php
    /**
     * PRD FR-SPIMS-008 steps 1 to 5: a supervisor has counted the shelf and authorises issuing more
     * of a part than the recorded balance holds. The authorisation is attached to the Draft and no
     * lock is taken — a human step cannot be performed while holding row locks (design §5.3).
     * The adjustment it will post is computed under lock at commit, in Task 7's
     * StockLedger::completeWithOverrides(), never from recorded_on_hand here.
     *
     * @throws WithdrawalRefused
     */
    public function authoriseOverride(
        StockTransaction $draft,
        Part $part,
        string $verifiedQty,
        ReasonCode $reason,
        User $supervisor,
    ): WithdrawalOverride {
        $this->guardDraft($draft);

        if (! $supervisor->can(Permission::OverridesAuthorise)) {
            throw WithdrawalRefused::notAllowedToOverride($supervisor);
        }

        if (! in_array($reason, WithdrawalOverride::REASONS, true)) {
            throw WithdrawalRefused::invalidOverrideReason();
        }

        $line = $draft->lines()->where('part_id', $part->id)->first();

        if (! $line instanceof StockTransactionLine) {
            throw WithdrawalRefused::partNotOnDraft($part);
        }

        $verified = $this->quantity($verifiedQty);
        $quantity = BigDecimal::of($line->quantity);

        if ($verified->isLessThan($quantity)) {
            throw WithdrawalRefused::verifiedQuantityTooLow($part, (string) $quantity->toScale(3));
        }

        $stock = PartStock::query()->find($part->id);
        $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');

        if (! $onHand->isLessThan($quantity)) {
            throw WithdrawalRefused::overrideNotNeeded($part);
        }

        $override = $draft->overrides()->updateOrCreate(['part_id' => $part->id], [
            'verified_qty' => (string) $verified,
            'recorded_on_hand' => (string) $onHand->toScale(3),
            'reason_code' => $reason,
            'authorised_by_user_id' => $supervisor->id,
            'authorised_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record('override_authorised', $override, null, [
            'part_id' => $part->id,
            'reason_code' => $reason->value,
            'verified_qty' => (string) $verified,
            'recorded_on_hand' => (string) $onHand->toScale(3),
        ], $supervisor);

        // The cart's stock story changed, so any scan taken before this one is no longer the last
        // step. The override itself survives: its line is within the quantity just verified.
        $this->invalidate($draft);

        return $override;
    }
```

`updateOrCreate()` needs `part_id` to be fillable on `WithdrawalOverride`, which Task 1's `#[Fillable]`
already allows. The audit values are listed shortest key first — `part_id`, `reason_code`,
`verified_qty`, `recorded_on_hand` — which is the order `jsonb` returns keys of equal length in byte
order, so the test's `toBe()` matches.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Withdrawals
```

Expected: PASS, 14 new tests (the role matrix is four, the reason dataset three), and Tasks 1–5 still
green.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Withdrawals/WithdrawalService.php app/Domain/Withdrawals/Exceptions/WithdrawalRefused.php tests/Feature/Withdrawals/OverrideAuthorisationTest.php
git commit -m "Attach a supervisor's insufficient-stock override to a draft withdrawal"
```

---

## Task 7: The override posting in `StockLedger`

**Files:**
- Create: `app/Domain/Ledger/Events/StockDiscrepancyReported.php`,
  `tests/Feature/Withdrawals/OverridePostingTest.php`
- Modify: `app/Domain/Ledger/StockLedger.php`

**Interfaces:**
- Consumes: every private method `StockLedger` already has — `applyLockTimeout()`,
  `lockBalances()`, `guardDirections()`, `guardSufficientStock()`, `writeMovements()`,
  `writeBalances()` — plus `TransactionNumberer::next()` and `AuditLogger::record()`.
- Produces:
  - `StockLedger::completeWithOverrides(StockTransaction $withdrawal, User $actor): StockTransaction`
  - `App\Domain\Ledger\Events\StockDiscrepancyReported` with public `partId`,
    `withdrawalOverrideId`, `withdrawalTransactionId` and `wasAdjusted`.
  - `StockLedger::complete()` now throws `LogicException` on a draft holding pending overrides.

This is design §5.3 in code, and the one task in this plan where a mistake corrupts the ledger rather
than annoying a custodian. Four rules decide everything:

1. **One lock acquisition.** Every override's part is already a line of the withdrawal, so the union
   is the withdrawal's own part set and `lockBalances()` is called once, in part id order (§5.2).
2. **One balance write.** The adjustments' movements and the withdrawal's movements are both computed
   *before* either is written, and `writeBalances()` is called **once**, with the final map. Calling
   it twice would have the second call overwrite the first, because it is a single `UPDATE … FROM
   (VALUES …)` keyed by part.
3. **The adjustment is `verified_qty − on_hand_locked`, computed here**, never from the
   `recorded_on_hand` the supervisor saw. Nothing in this method reads `recorded_on_hand`
   arithmetically.
4. **It may only increase.** `TransactionType::Adjustment->direction()` is `null`, so
   `guardDirections()` returns early for adjustments and nothing else enforces this. The check below
   is the only expression of design §5.3's "may only increase", and it lives here on purpose.

`postWithOverrides()` repeats about twenty lines of `post()`. Leave it repeated: a shared method with
an "are there overrides" flag would put the two orderings that matter — lock once, write balances once
— behind a conditional, which is exactly where this kind of bug hides.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/OverridePostingTest.php`:

```php
<?php

use App\Domain\Ledger\Events\StockDiscrepancyReported;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
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
function authorise(StockTransaction $draft, $part, string $verified, string $recorded, ReasonCode $reason = ReasonCode::FoundStock): WithdrawalOverride
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
        ->and($completed->transaction_no)->toStartWith('WD-')
        ->and($override->fresh()->adjustment->id)->toBeLessThan($completed->id + 1);
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
    // 8 − recorded_on_hand would have given.
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/OverridePostingTest.php
```

Expected: FAIL — `Call to undefined method App\Domain\Ledger\StockLedger::completeWithOverrides()`.

- [ ] **Step 3: Write the event**

Create `app/Domain/Ledger/Events/StockDiscrepancyReported.php`:

```php
<?php

namespace App\Domain\Ledger\Events;

/**
 * A supervisor override showed that the recorded balance did not match the shelf (PRD
 * FR-SPIMS-008 step 8, design §5.3). Dispatched after the commit that posted the withdrawal.
 *
 * Plan 5 turns this into an inventory_discrepancy alert. Until then nothing listens: the event is
 * the seam that keeps Plan 3 from depending on an alerts table that does not exist yet (design §10
 * gap, "Plans 3 → 5").
 */
final readonly class StockDiscrepancyReported
{
    public function __construct(
        public int $partId,
        public int $withdrawalOverrideId,
        public int $withdrawalTransactionId,
        /**
         * False when the locked balance already covered the line, so the override was discarded
         * and no adjustment was written. The discrepancy is reported either way: it was real, and
         * the part needs counting.
         */
        public bool $wasAdjusted,
    ) {}
}
```

- [ ] **Step 4: Refuse overrides on the ordinary path**

In `app/Domain/Ledger/StockLedger.php`, inside `post()`, immediately after the existing
`TransactionNotDraft` check, add:

```php
        // The station posts a withdrawal through completeWithOverrides(). Ignoring an
        // authorisation here would fail with InsufficientStock and look like a stock problem.
        if ($transaction->overrides()->whereNull('adjustment_transaction_id')->whereNull('discarded_at')->exists()) {
            throw new LogicException(
                "Transaction {$transaction->id} holds supervisor overrides; post it with completeWithOverrides()."
            );
        }
```

- [ ] **Step 5: Write the override posting**

In `app/Domain/Ledger/StockLedger.php`, add these imports:

```php
use App\Domain\Ledger\Events\StockDiscrepancyReported;
use App\Enums\MovementType;
use App\Models\WithdrawalOverride;
```

and add the two methods after `complete()` and its `post()`:

```php
    /**
     * Complete a draft withdrawal together with the adjustments its supervisor overrides authorise
     * (design §5.3, PRD FR-SPIMS-008). One adjustment transaction per override, plus the
     * withdrawal, all in one database transaction under one set of stock row locks. Either every
     * row is written or none is.
     *
     * @throws InstallNotConfigured
     * @throws TransactionNotDraft
     * @throws TransactionHasNoLines
     * @throws InsufficientStock
     * @throws StockBusy
     */
    public function completeWithOverrides(StockTransaction $withdrawal, User $actor): StockTransaction
    {
        if (! $this->settings->isReadyForStockTransactions()) {
            throw new InstallNotConfigured;
        }

        try {
            /** @var array{0: StockTransaction, 1: list<StockDiscrepancyReported>} $posted */
            $posted = DB::transaction(fn (): array => $this->postWithOverrides($withdrawal->id, $actor), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new StockBusy($exception);
            }

            throw $exception;
        }

        [$completed, $discrepancies] = $posted;

        event(new StockTransactionCompleted($completed));

        foreach ($discrepancies as $discrepancy) {
            event($discrepancy);
        }

        return $completed;
    }

    /**
     * @return array{0: StockTransaction, 1: list<StockDiscrepancyReported>}
     */
    private function postWithOverrides(int $transactionId, User $actor): array
    {
        $this->applyLockTimeout();

        $withdrawal = StockTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

        if ($withdrawal->type !== TransactionType::Withdrawal) {
            throw new LogicException(
                "completeWithOverrides() posts withdrawals; transaction {$withdrawal->id} is a {$withdrawal->type->value}."
            );
        }

        if ($withdrawal->status !== TransactionStatus::Draft) {
            throw new TransactionNotDraft($withdrawal);
        }

        $lines = $withdrawal->lines()->with('part')->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            throw new TransactionHasNoLines($withdrawal);
        }

        // Every override's part is already a line of this withdrawal, so the union of locked parts
        // is the withdrawal's own set and the ordering of design §5.2 is unaffected.
        $balances = $this->lockBalances($lines);
        $this->guardDirections($withdrawal, $lines);

        $recordedAt = CarbonImmutable::now();
        $occurredAt = $withdrawal->is_backdated ? $withdrawal->occurred_at : $recordedAt;

        /** @var list<StockDiscrepancyReported> $discrepancies */
        $discrepancies = [];
        /** @var list<array{0: WithdrawalOverride, 1: BigDecimal}> $adjustments */
        $adjustments = [];

        foreach ($withdrawal->overrides()->orderBy('part_id')->get() as $override) {
            $line = $lines->firstWhere('part_id', $override->part_id);
            $onHand = $balances[$override->part_id] ?? null;

            // Its line is gone. WithdrawalService prunes these, so this is belt and braces.
            if (! $line instanceof StockTransactionLine || ! $onHand instanceof BigDecimal) {
                $override->forceFill(['discarded_at' => $recordedAt])->save();

                continue;
            }

            // Design §5.3: the locked balance already covers the line, so the withdrawal commits
            // without the adjustment and the override is discarded — but the discrepancy was real
            // and the part still needs counting, so it is reported all the same.
            if (! BigDecimal::of($line->quantity)->isGreaterThan($onHand)) {
                $override->forceFill(['discarded_at' => $recordedAt])->save();

                $this->audit->record('override_discarded', $override, null, [
                    'on_hand' => (string) $onHand->toScale(3),
                ], $actor);

                $discrepancies[] = new StockDiscrepancyReported(
                    $override->part_id, $override->id, $withdrawal->id, false,
                );

                continue;
            }

            // Computed here, under lock, never from the recorded_on_hand the supervisor saw.
            $increase = BigDecimal::of($override->verified_qty)->minus($onHand);

            // An override adjustment may only increase the balance (design §5.3). Nothing else
            // enforces this: TransactionType::Adjustment->direction() is null, so
            // guardDirections() returns early for adjustment lines.
            if (! $increase->isPositive()) {
                continue;
            }

            $adjustments[] = [$override, $increase];
            $balances[$override->part_id] = $onHand->plus($increase);
        }

        // Each override posts its own single-line adjustment, so two short lines can carry two
        // different reasons (design §5.6.3).
        foreach ($adjustments as [$override, $increase]) {
            $adjustment = StockTransaction::query()->create([
                'type' => TransactionType::Adjustment,
                'custodian_user_id' => $withdrawal->custodian_user_id,
                'reason_code' => $override->reason_code,
                'remarks' => __('Supervisor override during withdrawal.'),
                'occurred_at' => $occurredAt,
            ]);

            $adjustmentLine = $adjustment->lines()->create([
                'line_no' => 1,
                'part_id' => $override->part_id,
                'quantity' => (string) $increase,
                'direction' => 1,
            ]);

            StockMovement::query()->insert([
                'part_id' => $override->part_id,
                'stock_transaction_id' => $adjustment->id,
                'stock_transaction_line_id' => $adjustmentLine->id,
                'movement_type' => MovementType::Adjustment->value,
                'quantity' => (string) $increase,
                'balance_after' => (string) $balances[$override->part_id],
                'occurred_at' => $occurredAt,
                'recorded_at' => $recordedAt,
                'personnel_id' => null,
                'equipment_id' => null,
                'purpose_id' => null,
                'custodian_user_id' => $withdrawal->custodian_user_id,
            ]);

            $adjustment->forceFill([
                'status' => TransactionStatus::Completed,
                'transaction_no' => $this->numberer->next(TransactionType::Adjustment, $recordedAt),
                'recorded_at' => $recordedAt,
            ])->save();

            $override->forceFill(['adjustment_transaction_id' => $adjustment->id])->save();

            $this->audit->record('override_posted', $override, null, [
                'quantity' => (string) $increase,
                'adjustment_transaction_no' => $adjustment->transaction_no,
            ], $actor);

            $discrepancies[] = new StockDiscrepancyReported(
                $override->part_id, $override->id, $withdrawal->id, true,
            );
        }

        // One line per part is enforced by unique (stock_transaction_id, part_id), so each part's
        // resulting balance is its locked balance — raised by any adjustment above — plus its line.
        $resulting = [];

        foreach ($lines as $line) {
            $resulting[$line->part_id] = $balances[$line->part_id]->plus($line->signedQuantity());
        }

        $this->guardSufficientStock($lines, $balances, $resulting);

        $this->writeMovements($withdrawal, $lines, $resulting, $occurredAt, $recordedAt);

        // Once, with the final map. The adjustments' balance_after values were computed above,
        // before this write, because writeBalances() is a single UPDATE keyed by part and a second
        // call would overwrite the first.
        $this->writeBalances($resulting, $recordedAt);

        $withdrawal->forceFill([
            'status' => TransactionStatus::Completed,
            'transaction_no' => $this->numberer->next(TransactionType::Withdrawal, $recordedAt),
            'recorded_at' => $recordedAt,
            'occurred_at' => $occurredAt,
        ])->save();

        $this->audit->record(
            'completed',
            $withdrawal,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Completed->value, 'transaction_no' => $withdrawal->transaction_no],
            $actor,
        );

        return [$withdrawal, $discrepancies];
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Withdrawals/OverridePostingTest.php tests/Feature/Ledger
```

Expected: PASS, 12 new tests (the discrepancy dataset is two), and every Plan 1 ledger test still
green — in particular `LedgerInvariantTest`, which calls `complete()` on drafts that hold no
overrides and is therefore untouched by Step 4's guard.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Ledger/StockLedger.php app/Domain/Ledger/Events/StockDiscrepancyReported.php tests/Feature/Withdrawals/OverridePostingTest.php
git commit -m "Post an override adjustment and its withdrawal in one commit"
```

---

## Task 8: The override posting in the invariant and concurrency suites

**Files:**
- Modify: `tests/Feature/Ledger/LedgerInvariantTest.php`, `tests/Committed/ConcurrentPostingTest.php`,
  `tests/Pest.php`

**Interfaces:**
- Consumes: `StockLedger::completeWithOverrides()` (Task 7), `WithdrawalOverride` (Task 1),
  `postConcurrently()`.
- Produces: `postConcurrently()` now posts a draft that carries overrides through
  `completeWithOverrides()`, so later plans' concurrency tests get the same behaviour for free.

The roadmap's standing rule: *a plan that needs a new kind of posting extends `StockLedger` and adds
that posting to the invariant test*. Design §13.1 calls that test the single most valuable one in the
system, and an override posting writes **two** transactions against **one** lock acquisition — exactly
the shape the invariant exists to catch.

The generator change is small on purpose: where the random sequence produces a withdrawal that would
go negative, one time in three a supervisor "verifies the shelf" and authorises every short line, so
the withdrawal posts through the override path instead of being refused. The independent model then
expects each overridden part to land at `verified_qty − quantity`, which with a verified quantity
equal to the line is exactly zero — the boundary the `on_hand >= 0` constraint guards.

- [ ] **Step 1: Teach `postConcurrently()` about overrides**

In `tests/Pest.php`, inside `postConcurrently()`, replace the child closure with:

```php
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
```

Nothing else in the helper changes; the comment above it about the closure taking no parameters still
applies.

- [ ] **Step 2: Write the failing concurrency tests**

Append to `tests/Committed/ConcurrentPostingTest.php`:

```php
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
```

Add the imports this file does not already have, at the top:

```php
use App\Enums\TransactionType;
use App\Models\WithdrawalOverride;
```

- [ ] **Step 3: Run the concurrency tests to verify they fail**

```bash
php artisan config:clear
php artisan test tests/Committed/ConcurrentPostingTest.php
```

Expected: FAIL before Step 1 is applied (`complete()` refuses a draft holding overrides, so the child
process reports a `LogicException`). With Step 1 applied they should pass; if they do not, the failure
is real — read the child's output, which `postConcurrently()` re-throws in full.

- [ ] **Step 4: Add overrides to the invariant generator**

In `tests/Feature/Ledger/LedgerInvariantTest.php`, add the import:

```php
use App\Models\WithdrawalOverride;
```

and, inside the `for ($step = 1; $step <= 120; $step++)` loop, immediately **after** the line that
creates `$draft` and **before** the existing `try {`, insert:

```php
        // Design §5.3: one short withdrawal in three is issued anyway, after a supervisor counts
        // the shelf and authorises every short line. Two transactions per posting, one lock
        // acquisition, and every overridden part lands exactly on zero — the boundary the
        // on_hand >= 0 constraint guards.
        if ($type === TransactionType::Withdrawal && $wouldGoNegative && mt_rand(1, 3) === 1) {
            $overridden = [];

            foreach ($lines as [$part, $quantity]) {
                if ($expected[$part->id]->isLessThan($quantity)) {
                    $overridden[$part->id] = true;
                    WithdrawalOverride::factory()->create([
                        'stock_transaction_id' => $draft->id,
                        'part_id' => $part->id,
                        'verified_qty' => $quantity,
                        'recorded_on_hand' => (string) $expected[$part->id],
                        'authorised_by_user_id' => $custodian->id,
                    ]);
                }
            }

            $ledger->completeWithOverrides($draft, $custodian);

            foreach ($lines as [$part, $quantity]) {
                $expected[$part->id] = isset($overridden[$part->id])
                    ? BigDecimal::zero()
                    : $expected[$part->id]->minus($quantity);
            }

            $this->travel(mt_rand(1, 240))->minutes();

            continue;
        }
```

The `$overridden` map is built before any expectation is updated, so a part is judged short against
the balance it had when the draft was made, not against a balance the same loop already changed.

- [ ] **Step 5: Run the invariant test to verify it still passes**

```bash
php artisan test tests/Feature/Ledger/LedgerInvariantTest.php
```

Expected: PASS for every seed. A failure names its seed — replay that one seed and read the
independent model's expectation against the ledger before changing anything.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add tests/Pest.php tests/Feature/Ledger/LedgerInvariantTest.php tests/Committed/ConcurrentPostingTest.php
git commit -m "Prove the override posting against the invariant and two stations"
```

---

## Task 9: `ReplenishmentService`

**Files:**
- Create: `app/Domain/Replenishments/ReplenishmentService.php`,
  `app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php`,
  `tests/Feature/Replenishments/ReplenishmentServiceTest.php`

**Interfaces:**
- Consumes: `StockTransaction`, `StockTransactionLine`, `Part`, `PartStock`,
  `InstallSettings::timezone()`, `AuditLogger::record()`.
- Produces `App\Domain\Replenishments\ReplenishmentService` with:
  - `openDraft(User $custodian): StockTransaction`
  - `setDelivery(StockTransaction $draft, string $supplier, string $referenceNo, string $receivedOn): void`
  - `addLine(StockTransaction $draft, Part $part, string $quantity, ?string $unitCost): StockTransactionLine`
  - `setLineQuantity(StockTransaction $draft, StockTransactionLine $line, string $quantity, ?string $unitCost): void`
  - `removeLine(StockTransaction $draft, StockTransactionLine $line): void`
  - `cancel(StockTransaction $draft, User $actor): void`
  - `guardReadyToConfirm(StockTransaction $draft): void`
  - `duplicateDelivery(StockTransaction $draft): ?StockTransaction`
  - `overMaximum(StockTransaction $draft): list<Part>`
- Produces `App\Domain\Replenishments\Exceptions\ReplenishmentRefused`.

PRD FR-SPIMS-009, design §5.4. A replenishment is the simple half of this plan: positive lines, no
personnel, no badge, no override. Its whole weight is in two warnings and one refusal.

- **The duplicate delivery warns, it does not refuse** (FR-SPIMS-009 alternate flow). The same DR
  number legitimately recurs across suppliers, and a genuine second delivery under one reference
  happens. `duplicateDelivery()` returns the earlier completed replenishment so the screen can name
  it; confirming the screen confirms it.
- **Going over the maximum level warns and still confirms.** `max_level` is nullable, so a part
  without one never warns.
- **The received date must be today in the install time zone.** See Decision 10: the ledger stamps
  `occurred_at` from the commit unless `is_backdated`, and `is_backdated` is Plan 4's. The refusal
  names the screen that will exist then, so the message does not have to change later.

`setDelivery()` takes the date as the `Y-m-d` string the date input produces, and compares it against
today in the install time zone rather than in UTC — at 08:00 in Manila it is still the previous day in
UTC, and a custodian on the early shift must not be told their delivery is in the future.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Replenishments/ReplenishmentServiceTest.php`:

```php
<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Domain\Replenishments\ReplenishmentService;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(ReplenishmentService::class);
    $this->today = CarbonImmutable::now()->setTimezone(app(InstallSettings::class)->timezone())->toDateString();
});

it('opens one replenishment draft per custodian', function () {
    $first = $this->service->openDraft($this->custodian);

    expect($this->service->openDraft($this->custodian)->is($first))->toBeTrue()
        ->and($first->type)->toBe(TransactionType::Replenishment)
        ->and($first->status)->toBe(TransactionStatus::Draft);
});

it('keeps a replenishment draft separate from a withdrawal draft', function () {
    $withdrawal = app(\App\Domain\Withdrawals\WithdrawalService::class)->openDraft($this->custodian);

    expect($this->service->openDraft($this->custodian)->is($withdrawal))->toBeFalse();
});

it('records the supplier, the reference and the date received', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->setDelivery($draft, '  Pacific Bearings Inc. ', ' dr-00921 ', $this->today);

    expect($draft->fresh())->supplier->toBe('Pacific Bearings Inc.')->reference_no->toBe('DR-00921');
});

it('adds a line that adds stock', function () {
    $draft = $this->service->openDraft($this->custodian);

    $line = $this->service->addLine($draft, Part::factory()->create(), '12', '145.5000');

    expect($line->direction)->toBe(1)->and($line->quantity)->toBe('12.000')->and($line->unit_cost)->toBe('145.5000');
});

it('merges a repeated part into one line', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();

    $this->service->addLine($draft, $part, '5', null);
    $this->service->addLine($draft, $part, '3', null);

    expect($draft->lines()->count())->toBe(1)->and($draft->lines()->firstOrFail()->quantity)->toBe('8.000');
});

it('refuses an inactive part, a quantity of zero or less and a negative unit cost', function (Closure $attempt, string $expected) {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $attempt($this->service, $draft))->toThrow(ReplenishmentRefused::class, $expected);
})->with([
    'inactive part' => [
        fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(['is_active' => false, 'part_number' => 'OLD-1']), '1', null),
        'OLD-1',
    ],
    'zero quantity' => [fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(), '0', null), 'greater than zero'],
    'negative unit cost' => [fn (ReplenishmentService $s, $d) => $s->addLine($d, Part::factory()->create(), '1', '-1'), 'unit cost'],
]);

it('refuses a delivery with no supplier or no reference', function () {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $this->service->setDelivery($draft, '  ', 'DR-1', $this->today))
        ->toThrow(ReplenishmentRefused::class, 'Supplier')
        ->and(fn () => $this->service->setDelivery($draft, 'Pacific Bearings Inc.', ' ', $this->today))
        ->toThrow(ReplenishmentRefused::class, 'reference');
});

it('refuses a date received that is not today in the install time zone', function (int $days, string $expected) {
    $draft = $this->service->openDraft($this->custodian);
    $date = CarbonImmutable::parse($this->today)->addDays($days)->toDateString();

    expect(fn () => $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', $date))
        ->toThrow(ReplenishmentRefused::class, $expected);
})->with([
    'tomorrow' => [1, 'in the future'],
    'yesterday' => [-1, 'backdated entry'],
]);

it('accepts today in the install time zone even when UTC has not caught up', function () {
    app(InstallSettings::class)->set('regional.timezone', 'Asia/Manila', $this->custodian);
    // 00:30 in Manila on the 24th is still 16:30 UTC on the 23rd.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 16:30:00', 'UTC'));
    $draft = $this->service->openDraft($this->custodian);

    $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', '2026-09-24');

    expect($draft->fresh()->reference_no)->toBe('DR-1');
});

it('warns about a delivery reference already recorded for the same supplier', function () {
    $part = Part::factory()->create();
    $earlier = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($earlier, 'Pacific Bearings Inc.', 'DR-00921', $this->today);
    $this->service->addLine($earlier, $part, '5', null);
    app(StockLedger::class)->complete($earlier, $this->custodian);

    $draft = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($draft, 'pacific bearings inc.', 'dr-00921', $this->today);

    expect($this->service->duplicateDelivery($draft)?->is($earlier))->toBeTrue();
});

it('does not warn when the same reference comes from another supplier', function () {
    $earlier = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($earlier, 'Pacific Bearings Inc.', 'DR-00921', $this->today);
    $this->service->addLine($earlier, Part::factory()->create(), '5', null);
    app(StockLedger::class)->complete($earlier, $this->custodian);

    $draft = $this->service->openDraft($this->custodian);
    $this->service->setDelivery($draft, 'Luzon Belting Corp.', 'DR-00921', $this->today);

    expect($this->service->duplicateDelivery($draft))->toBeNull();
});

it('warns about a line that takes a part over its maximum level', function () {
    $overshoot = partWithStock('8', ['max_level' => '10', 'part_number' => 'BRG-1']);
    $within = partWithStock('1', ['max_level' => '10']);
    $unlimited = partWithStock('1', ['max_level' => null]);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $overshoot, '5', null);
    $this->service->addLine($draft, $within, '5', null);
    $this->service->addLine($draft, $unlimited, '5000', null);

    expect(array_map(fn (Part $part): string => $part->part_number, $this->service->overMaximum($draft)))
        ->toBe(['BRG-1']);
});

it('refuses to confirm until the delivery and at least one line are there', function () {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $this->service->guardReadyToConfirm($draft))->toThrow(ReplenishmentRefused::class, 'Supplier');

    $this->service->setDelivery($draft, 'Pacific Bearings Inc.', 'DR-1', $this->today);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))->toThrow(ReplenishmentRefused::class, 'at least one');

    $this->service->addLine($draft, Part::factory()->create(), '1', null);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))->not->toThrow(ReplenishmentRefused::class);
});

it('cancels a draft and audits it', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->cancel($draft, $this->custodian);

    expect($draft->fresh()->status)->toBe(TransactionStatus::Cancelled)
        ->and(AuditLog::query()->where('event', 'cancelled')->count())->toBe(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Replenishments/ReplenishmentServiceTest.php
```

Expected: FAIL — `Class "App\Domain\Replenishments\ReplenishmentService" does not exist`.

- [ ] **Step 3: Write the refusal**

Create `app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php`:

```php
<?php

namespace App\Domain\Replenishments\Exceptions;

use App\Models\Part;
use App\Models\StockTransaction;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-009 stopped a change to a draft replenishment, or stopped its
 * confirmation.
 */
final class ReplenishmentRefused extends RuntimeException
{
    public static function notADraft(StockTransaction $transaction): self
    {
        return new self(__('This replenishment is :status and can no longer be changed.', [
            'status' => $transaction->status->value,
        ]));
    }

    public static function inactivePart(Part $part): self
    {
        return new self(__('Spare part :part is Inactive. Reactivate it before receiving stock.', [
            'part' => $part->part_number,
        ]));
    }

    public static function quantityNotPositive(string $quantity): self
    {
        return new self(__('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($quantity)]));
    }

    public static function unitCostNegative(string $unitCost): self
    {
        return new self(__('A unit cost of :cost is not a number of zero or more.', ['cost' => trim($unitCost)]));
    }

    public static function lineNotOnDraft(): self
    {
        return new self(__('That line is not part of this replenishment.'));
    }

    public static function supplierRequired(): self
    {
        return new self(__('Supplier is required.'));
    }

    public static function referenceRequired(): self
    {
        return new self(__('A delivery receipt or purchase order reference is required.'));
    }

    public static function receivedInFuture(): self
    {
        return new self(__('The date received cannot be in the future.'));
    }

    public static function receivedEarlier(): self
    {
        return new self(__('Record a delivery received on an earlier day as a backdated entry, on the paper fallback screen.'));
    }

    public static function noLines(): self
    {
        return new self(__('Add at least one spare part received before confirming.'));
    }
}
```

- [ ] **Step 4: Write the service**

Create `app/Domain/Replenishments/ReplenishmentService.php`:

```php
<?php

namespace App\Domain\Replenishments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Spare parts received into the storeroom (PRD FR-SPIMS-009, design §5.4). Positive lines, no
 * personnel and no badge: the accountability here is the supplier and the delivery reference.
 * Nothing in this class changes a stock balance — StockLedger::complete() does that.
 */
final readonly class ReplenishmentService
{
    public function __construct(
        private AuditLogger $audit,
        private InstallSettings $settings,
    ) {}

    /**
     * The custodian's open replenishment draft, created if they have none. Independent of their
     * withdrawal draft: the two screens are used one after the other all shift.
     */
    public function openDraft(User $custodian): StockTransaction
    {
        $draft = StockTransaction::query()
            ->where('custodian_user_id', $custodian->id)
            ->where('type', TransactionType::Replenishment)
            ->where('status', TransactionStatus::Draft)
            ->orderBy('id')
            ->first();

        return $draft ?? StockTransaction::query()->create([
            'type' => TransactionType::Replenishment,
            'custodian_user_id' => $custodian->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * FR-SPIMS-009 step 2. The reference is stored trimmed and uppercased so DR-1 and dr-1 are one
     * reference, which is what makes the duplicate warning below reliable.
     *
     * @param  string  $receivedOn  a Y-m-d date, read in the install time zone
     *
     * @throws ReplenishmentRefused
     */
    public function setDelivery(StockTransaction $draft, string $supplier, string $referenceNo, string $receivedOn): void
    {
        $this->guardDraft($draft);

        $supplier = trim($supplier);
        $referenceNo = Str::upper(trim($referenceNo));

        if ($supplier === '') {
            throw ReplenishmentRefused::supplierRequired();
        }

        if ($referenceNo === '') {
            throw ReplenishmentRefused::referenceRequired();
        }

        // Compared in the install time zone: at 00:30 in Manila it is still yesterday in UTC, and
        // the early shift must not be told that today's delivery is in the future.
        $today = CarbonImmutable::now()->setTimezone($this->settings->timezone())->toDateString();

        if ($receivedOn > $today) {
            throw ReplenishmentRefused::receivedInFuture();
        }

        if ($receivedOn < $today) {
            throw ReplenishmentRefused::receivedEarlier();
        }

        $draft->forceFill(['supplier' => $supplier, 'reference_no' => $referenceNo])->save();
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function addLine(StockTransaction $draft, Part $part, string $quantity, ?string $unitCost): StockTransactionLine
    {
        $this->guardDraft($draft);
        $amount = $this->quantity($quantity);
        $cost = $this->unitCost($unitCost);

        if (! $part->is_active) {
            throw ReplenishmentRefused::inactivePart($part);
        }

        $line = $draft->lines()->where('part_id', $part->id)->first();

        if ($line instanceof StockTransactionLine) {
            $line->forceFill([
                'quantity' => (string) BigDecimal::of($line->quantity)->plus($amount),
                'unit_cost' => $cost ?? $line->unit_cost,
            ])->save();

            return $line;
        }

        return $draft->lines()->create([
            'line_no' => ((int) $draft->lines()->max('line_no')) + 1,
            'part_id' => $part->id,
            'quantity' => (string) $amount,
            // A replenishment always adds stock (TransactionType::Replenishment->direction()).
            'direction' => 1,
            'unit_cost' => $cost,
        ]);
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function setLineQuantity(StockTransaction $draft, StockTransactionLine $line, string $quantity, ?string $unitCost): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->forceFill([
            'quantity' => (string) $this->quantity($quantity),
            'unit_cost' => $this->unitCost($unitCost),
        ])->save();
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function removeLine(StockTransaction $draft, StockTransactionLine $line): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->delete();
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function cancel(StockTransaction $draft, User $actor): void
    {
        $this->guardDraft($draft);

        $draft->forceFill(['status' => TransactionStatus::Cancelled])->save();

        $this->audit->record(
            'cancelled',
            $draft,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Cancelled->value],
            $actor,
        );
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function guardReadyToConfirm(StockTransaction $draft): void
    {
        $this->guardDraft($draft);

        if ($draft->supplier === null || trim($draft->supplier) === '') {
            throw ReplenishmentRefused::supplierRequired();
        }

        if ($draft->reference_no === null || trim($draft->reference_no) === '') {
            throw ReplenishmentRefused::referenceRequired();
        }

        if ($draft->lines()->count() === 0) {
            throw ReplenishmentRefused::noLines();
        }
    }

    /**
     * FR-SPIMS-009 alternate flow: the same reference already recorded for the same supplier. This
     * warns, it does not refuse — one reference legitimately covers two deliveries, and the same DR
     * number recurs across suppliers.
     */
    public function duplicateDelivery(StockTransaction $draft): ?StockTransaction
    {
        if ($draft->supplier === null || $draft->reference_no === null) {
            return null;
        }

        return StockTransaction::query()
            ->where('type', TransactionType::Replenishment)
            ->where('status', TransactionStatus::Completed)
            ->whereKeyNot($draft->id)
            ->whereRaw('lower(supplier) = ?', [Str::lower(trim($draft->supplier))])
            ->where('reference_no', $draft->reference_no)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * FR-SPIMS-009 alternate flow: the parts this delivery takes above their maximum level. A part
     * with no maximum never appears.
     *
     * @return list<Part>
     */
    public function overMaximum(StockTransaction $draft): array
    {
        $over = [];

        foreach ($draft->lines()->with('part.stock')->orderBy('line_no')->get() as $line) {
            $part = $line->part;

            if ($part->max_level === null) {
                continue;
            }

            $stock = $part->stock;
            $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');

            if ($onHand->plus($line->quantity)->isGreaterThan($part->max_level)) {
                $over[] = $part;
            }
        }

        return $over;
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function quantity(string $quantity): BigDecimal
    {
        try {
            $amount = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw ReplenishmentRefused::quantityNotPositive($quantity);
        }

        if (! $amount->isPositive()) {
            throw ReplenishmentRefused::quantityNotPositive($quantity);
        }

        return $amount;
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function unitCost(?string $unitCost): ?string
    {
        if ($unitCost === null || trim($unitCost) === '') {
            return null;
        }

        try {
            $cost = BigDecimal::of(trim($unitCost))->toScale(4);
        } catch (MathException) {
            throw ReplenishmentRefused::unitCostNegative($unitCost);
        }

        if ($cost->isNegative()) {
            throw ReplenishmentRefused::unitCostNegative($unitCost);
        }

        return (string) $cost;
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function guardDraft(StockTransaction $draft): void
    {
        if ($draft->status !== TransactionStatus::Draft) {
            throw ReplenishmentRefused::notADraft($draft);
        }
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function guardLineOnDraft(StockTransaction $draft, StockTransactionLine $line): void
    {
        if ($line->stock_transaction_id !== $draft->id) {
            throw ReplenishmentRefused::lineNotOnDraft();
        }
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Replenishments/ReplenishmentServiceTest.php
```

Expected: PASS, 18 new tests (two datasets of three and two).

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Replenishments/ReplenishmentService.php app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php tests/Feature/Replenishments/ReplenishmentServiceTest.php
git commit -m "Record spare parts received against a supplier and a delivery reference"
```

---

## Task 10: The withdrawal station screen and its receipt

**Files:**
- Create: `app/Livewire/Station/Withdrawal.php`, `app/Livewire/Station/Receipt.php`,
  `resources/views/livewire/station/withdrawal.blade.php`,
  `resources/views/livewire/station/receipt.blade.php`,
  `tests/Feature/Withdrawals/WithdrawalStationTest.php`
- Modify: `routes/spims.php`

**Interfaces:**
- Consumes: `WithdrawalService` (Tasks 2, 3, 6), `WithdrawalIdentification` (Tasks 4, 5),
  `CounterAuthorisation` (Task 5), `StockLedger::completeWithOverrides()` (Task 7), `PartSearch`,
  `Permission::WithdrawalsProcess`, `Permission::MovementsView`.
- Produces the routes `station.withdrawal` and `station.receipt`.

Design §5.1 on one screen, keyboard first. The whole flow has to work with the keyboard and the badge
reader alone (NFR-SPIMS-009), so:

- The **part entry field is autofocused** and submits on Enter. A keyboard-wedge reader typing a part
  number behaves exactly like a custodian typing one.
- The **scan field submits on Enter** as well, which is what the reader sends after the UID.
- Every action is reachable by Tab and Enter; nothing depends on hover or drag.

Two things that only look like screen concerns and are really correctness concerns:

- **No action here re-implements a rule.** Every refusal comes from a domain exception and is shown as
  it was written, because those messages are read out at a counter.
- **The receipt is a separate route**, not a flash message, so a custodian can reopen it, and because
  FR-SPIMS-007's postcondition lists the fields a transaction record must show.

Action names are checked against `LivewireActionNamesTest`: `confirm`, not `commit`; `saveContext`,
not `set`; `addPart`, not `call`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Withdrawals/WithdrawalStationTest.php`:

```php
<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Livewire\Station\Withdrawal;
use App\Models\Equipment;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\WithdrawalPurpose;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->supervisor->forceFill(['email' => 'rita@microtech.test', 'password' => Hash::make('counter-secret')])->save();
    $this->equipment = Equipment::factory()->create(['code' => 'CNC-01']);
    $this->purpose = WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    $this->part = partWithStock('10', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing']);
    $this->person = Personnel::factory()->create(['full_name' => 'Rosalie Mendoza', 'employee_no' => 'EMP-0042']);
    app(PersonnelBadges::class)->enroll($this->person, 'A1234BCDE', $this->custodian);
});

it('opens the station only for the roles that process withdrawals', function (Role $role, bool $allowed) {
    $response = $this->actingAs(userWithRoles($role))->get(route('station.withdrawal'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(roleMatrix('CS'));

it('adds a spare part entered by its exact number', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'brg-6204')
        ->set('quantity', '3')
        ->call('enterPart')
        ->assertHasNoErrors()
        ->assertSee('Deep groove ball bearing');

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->lines()->firstOrFail()->quantity)->toBe('3.000');
});

it('offers matches when the entry is not a part number', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'ball bearing')
        ->call('enterPart')
        ->assertHasErrors('partEntry')
        ->assertSee('BRG-6204');
});

it('walks the whole scan-last flow and lands on the receipt', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '4')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->assertHasNoErrors()
        ->assertSee('Rosalie Mendoza')
        ->call('confirm')
        ->assertHasNoErrors();

    // Typed, because partWithStock() has already completed an opening transaction for this part.
    $completed = StockTransaction::query()
        ->where('type', TransactionType::Withdrawal)
        ->where('status', TransactionStatus::Completed)
        ->sole();

    $component->assertRedirect(route('station.receipt', ['transaction' => $completed->id]));

    expect(onHand($this->part))->toBe('6.000')
        ->and($completed->transaction_no)->toStartWith('WD-');
});

it('requires a new scan after the cart changes', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan')
        ->set('quantity', '2')
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('confirm')
        ->assertHasErrors('draft');

    expect(StockTransaction::query()
        ->where('type', TransactionType::Withdrawal)
        ->where('status', TransactionStatus::Completed)
        ->count())->toBe(0);
});

it('refuses to confirm once the scan has expired', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan');

    $this->travel(app(WithdrawalIdentification::class)->timeoutSeconds() + 1)->seconds();

    $component->call('confirm')->assertHasErrors('draft');
});

it('rejects a badge nobody holds without touching the draft', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('badge', 'FFFF9999')
        ->call('scan')
        ->assertHasErrors('badge');
});

it('lets a supervisor authorise an override at the counter', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '5')
        ->set('overrideReason', ReasonCode::FoundStock->value)
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('authoriseOverride')
        ->assertHasNoErrors();

    $draft = app(WithdrawalService::class)->openDraft($this->custodian);

    expect($draft->overrides()->count())->toBe(1)
        ->and($draft->overrides()->firstOrFail()->authorised_by_user_id)->toBe($this->supervisor->id);
});

it('refuses an override on wrong supervisor credentials and never clears the password into the draft', function () {
    $short = partWithStock('1', ['part_number' => 'BLT-100']);

    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BLT-100')
        ->set('quantity', '5')
        ->call('enterPart')
        ->call('requestOverride', $short->id)
        ->set('verifiedQty', '5')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'wrong')
        ->call('authoriseOverride')
        ->assertHasErrors('supervisorPassword')
        ->assertSet('supervisorPassword', '');

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->overrides()->count())->toBe(0);
});

it('lets a supervisor identify a person who has no badge', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->set('manualPersonnelId', (string) $this->person->id)
        ->set('manualReason', 'Badge left at home')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('identifyManually')
        ->assertHasNoErrors()
        ->assertSee('Rosalie Mendoza');
});

it('names the spare parts when stock ran out while the custodian was reviewing', function () {
    $component = Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '9')
        ->call('enterPart')
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->call('saveContext')
        ->set('badge', 'A1234BCDE')
        ->call('scan');

    // Another station takes the stock between the scan and the confirmation.
    $other = StockTransaction::factory()->withLines([[$this->part, '8']])->create();
    app(\App\Domain\Ledger\StockLedger::class)->complete($other, $this->custodian);

    $component->call('confirm')->assertHasErrors('draft')->assertSee('BRG-6204');
});

it('cancels a draft and starts a clean one', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->set('partEntry', 'BRG-6204')
        ->call('enterPart')
        ->call('cancelDraft')
        ->assertHasNoErrors();

    expect(app(WithdrawalService::class)->openDraft($this->custodian)->lines()->count())->toBe(0)
        ->and(StockTransaction::query()->where('status', TransactionStatus::Cancelled)->count())->toBe(1);
});

it('shows the transaction record the requirement lists, with the badge masked', function () {
    $draft = StockTransaction::factory()->withLines([[$this->part, '4']])->create([
        'custodian_user_id' => $this->custodian->id,
        'personnel_id' => $this->person->id,
        'equipment_id' => $this->equipment->id,
        'purpose_id' => $this->purpose->id,
    ]);
    $completed = app(\App\Domain\Ledger\StockLedger::class)->complete($draft, $this->custodian);

    $this->actingAs($this->custodian)
        ->get(route('station.receipt', ['transaction' => $completed->id]))
        ->assertOk()
        ->assertSee($completed->transaction_no)
        ->assertSee('Rosalie Mendoza')
        ->assertSee('EMP-0042')
        ->assertSee('BRG-6204')
        ->assertSee('Deep groove ball bearing')
        ->assertSee('CNC-01')
        ->assertSee($this->person->fresh()->badge_uid_masked)
        ->assertDontSee('A1234BCDE');
});

it('keeps the scan field on Enter', function () {
    Livewire::actingAs($this->custodian)->test(Withdrawal::class)
        ->assertSeeHtml('wire:keydown.enter.prevent="scan"')
        ->assertSeeHtml('wire:keydown.enter.prevent="enterPart"');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Withdrawals/WithdrawalStationTest.php
```

Expected: FAIL — `Route [station.withdrawal] not defined.`

- [ ] **Step 3: Write the station component**

Create `app/Livewire/Station/Withdrawal.php`:

```php
<?php

namespace App\Livewire\Station;

use App\Domain\Access\CounterAuthorisation;
use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\Shortage;
use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\PartSearch;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalReview;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\Permission;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\WithdrawalOverride;
use App\Models\WithdrawalPurpose;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The withdrawal station (design §5.1, PRD FR-SPIMS-006 to 008). Keyboard first: the part entry and
 * the badge scan both submit on Enter, which is what a keyboard-wedge reader sends.
 *
 * No rule is implemented here. Every refusal comes from a domain exception and is shown as written,
 * because these messages are read out loud at a counter.
 */
#[Title('Withdrawal station')]
class Withdrawal extends Component
{
    use ActsAsSignedInUser;

    public string $partEntry = '';

    public string $quantity = '1';

    public string $equipmentId = '';

    public string $purposeId = '';

    public string $remarks = '';

    public string $badge = '';

    public int $overridePartId = 0;

    public string $verifiedQty = '';

    public string $overrideReason = '';

    public string $manualPersonnelId = '';

    public string $manualReason = '';

    /** Shared by the override and manual-identification dialogs. Never persisted. */
    public string $supervisorEmail = '';

    public string $supervisorPassword = '';

    public function mount(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        $draft = $this->draft();
        $this->equipmentId = (string) ($draft->equipment_id ?? '');
        $this->purposeId = (string) ($draft->purpose_id ?? '');
        $this->remarks = (string) ($draft->remarks ?? '');
        $this->overrideReason = ReasonCode::FoundStock->value;
    }

    /**
     * Step 2 of design §5.1. An exact part number is added straight away; anything else leaves the
     * entry in place and the matches below it (FR-SPIMS-006 alternate flow).
     */
    public function enterPart(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $entry = trim($this->partEntry);

        if ($entry === '') {
            return;
        }

        $part = Part::query()->whereRaw('lower(part_number) = ?', [Str::lower($entry)])->first();

        if (! $part instanceof Part) {
            $this->addError('partEntry', __('No spare part has that number. Pick one from the matches below.'));

            return;
        }

        $this->addPart($part->id);
    }

    public function addPart(int $partId): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            app(WithdrawalService::class)->addLine($this->draft(), Part::query()->findOrFail($partId), $this->quantity);
        } catch (WithdrawalRefused $refused) {
            $this->addError('partEntry', $refused->getMessage());

            return;
        }

        $this->partEntry = '';
        $this->quantity = '1';
        $this->forget();
    }

    public function removeLine(int $lineId): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $draft = $this->draft();
        $line = $draft->lines()->whereKey($lineId)->first();

        if ($line instanceof StockTransactionLine) {
            app(WithdrawalService::class)->removeLine($draft, $line);
        }

        $this->forget();
    }

    public function saveContext(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            app(WithdrawalService::class)->setContext(
                $this->draft(),
                $this->equipmentId === '' ? null : Equipment::query()->findOrFail((int) $this->equipmentId),
                $this->purposeId === '' ? null : WithdrawalPurpose::query()->findOrFail((int) $this->purposeId),
                $this->remarks,
            );
        } catch (WithdrawalRefused $refused) {
            $this->addError('equipmentId', $refused->getMessage());

            return;
        }

        $this->forget();
    }

    /**
     * Step 5 of design §5.1: the reader types the UID and presses Enter.
     */
    public function scan(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            app(WithdrawalIdentification::class)->identify($this->draft(), $this->badge, $this->actor());
        } catch (WithdrawalRefused $refused) {
            $this->addError('badge', $refused->getMessage());

            return;
        } finally {
            $this->badge = '';
        }

        $this->forget();
    }

    /**
     * FR-SPIMS-007 alternate flow: the person at the counter is not the one on screen.
     */
    public function clearIdentification(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        app(WithdrawalIdentification::class)->clear($this->draft());

        $this->forget();
    }

    public function requestOverride(int $partId): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $this->overridePartId = $partId;
        $this->verifiedQty = '';
        $this->overrideReason = ReasonCode::FoundStock->value;
        $this->supervisorEmail = '';
        $this->supervisorPassword = '';

        Flux::modal('supervisor-override')->show();
    }

    /**
     * FR-SPIMS-008 steps 2 to 5. The supervisor's credentials identify them; they never sign in,
     * and the station stays the custodian's.
     */
    public function authoriseOverride(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            $supervisor = app(CounterAuthorisation::class)->verify(
                $this->supervisorEmail,
                $this->supervisorPassword,
                Permission::OverridesAuthorise,
                (string) request()->ip(),
            );

            app(WithdrawalService::class)->authoriseOverride(
                $this->draft(),
                Part::query()->findOrFail($this->overridePartId),
                $this->verifiedQty,
                ReasonCode::from($this->overrideReason),
                $supervisor,
            );
        } catch (AuthorisationRefused $refused) {
            $this->addError('supervisorPassword', $refused->getMessage());

            return;
        } catch (WithdrawalRefused $refused) {
            $this->addError('verifiedQty', $refused->getMessage());

            return;
        } finally {
            $this->supervisorPassword = '';
        }

        Flux::modal('supervisor-override')->close();
        Flux::toast(variant: 'success', text: __('Override authorised. The adjustment is recorded when you confirm.'));
        $this->forget();
    }

    /**
     * FR-SPIMS-007 alternate flow, pending Microtech's answer to Q12.
     */
    public function identifyManually(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            $supervisor = app(CounterAuthorisation::class)->verify(
                $this->supervisorEmail,
                $this->supervisorPassword,
                Permission::IdentificationAuthorise,
                (string) request()->ip(),
            );

            app(WithdrawalIdentification::class)->identifyManually(
                $this->draft(),
                Personnel::query()->findOrFail((int) $this->manualPersonnelId),
                $supervisor,
                $this->manualReason,
            );
        } catch (AuthorisationRefused $refused) {
            $this->addError('supervisorPassword', $refused->getMessage());

            return;
        } catch (WithdrawalRefused $refused) {
            $this->addError('manualReason', $refused->getMessage());

            return;
        } finally {
            $this->supervisorPassword = '';
        }

        Flux::modal('manual-identification')->close();
        $this->forget();
    }

    /**
     * Steps 6 to 8 of design §5.1, in one short database transaction.
     */
    public function confirm(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $draft = $this->draft();

        try {
            app(WithdrawalService::class)->guardReadyToConfirm($draft);
            $completed = app(StockLedger::class)->completeWithOverrides($draft, $this->actor());
        } catch (WithdrawalRefused|InstallNotConfigured $refused) {
            $this->addError('draft', $refused->getMessage());

            return;
        } catch (InsufficientStock $short) {
            $this->addError('draft', __('Stock changed while you were reviewing. Short: :parts', [
                'parts' => implode(', ', array_map(
                    fn (Shortage $shortage): string => $shortage->partNumber,
                    $short->shortages,
                )),
            ]));
            $this->forget();

            return;
        } catch (StockBusy $busy) {
            $this->addError('draft', $busy->getMessage());

            return;
        }

        $this->redirectRoute('station.receipt', ['transaction' => $completed->id], navigate: true);
    }

    public function cancelDraft(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        app(WithdrawalService::class)->cancel($this->draft(), $this->actor());

        $this->reset(['partEntry', 'quantity', 'equipmentId', 'purposeId', 'remarks', 'badge']);
        $this->forget();

        Flux::toast(variant: 'success', text: __('Withdrawal cancelled. Nothing was recorded.'));
    }

    #[Computed]
    public function draft(): StockTransaction
    {
        return app(WithdrawalService::class)->openDraft($this->actor());
    }

    #[Computed]
    public function review(): WithdrawalReview
    {
        return app(WithdrawalService::class)->review($this->draft());
    }

    /**
     * The spare parts an entry that is not a part number matches (FR-SPIMS-006 alternate flow).
     *
     * @return Collection<int, Part>
     */
    #[Computed]
    public function matches(): Collection
    {
        if (trim($this->partEntry) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->partEntry)
            ->where('is_active', true)
            ->with(['stock', 'defaultLocation'])
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Equipment>
     */
    #[Computed]
    public function equipmentOptions(): Collection
    {
        return Equipment::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * @return Collection<int, WithdrawalPurpose>
     */
    #[Computed]
    public function purposeOptions(): Collection
    {
        return WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * @return Collection<int, Personnel>
     */
    #[Computed]
    public function personnelOptions(): Collection
    {
        return Personnel::query()->where('is_active', true)->orderBy('full_name')->get();
    }

    /**
     * Drop the cached draft and review, so the next render reads what was just written.
     */
    private function forget(): void
    {
        unset($this->draft, $this->review, $this->matches);
    }
}
```

- [ ] **Step 4: Write the receipt component**

Create `app/Livewire/Station/Receipt.php`:

```php
<?php

namespace App\Livewire\Station;

use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\StockTransaction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The transaction record PRD FR-SPIMS-007 lists as a postcondition, on its own route so the
 * custodian can reopen it. Badge identifiers are masked here as everywhere (NFR-SPIMS-004).
 */
#[Title('Transaction record')]
class Receipt extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $transactionId;

    public function mount(StockTransaction $transaction): void
    {
        $this->authorize(Permission::MovementsView);

        $this->transactionId = $transaction->id;
    }

    #[Computed]
    public function transaction(): StockTransaction
    {
        return StockTransaction::query()
            ->with(['personnel', 'equipment', 'purpose', 'custodian', 'lines.part', 'movements'])
            ->findOrFail($this->transactionId);
    }
}
```

- [ ] **Step 5: Write the views**

Create `resources/views/livewire/station/withdrawal.blade.php`:

```blade
<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Withdrawal station') }}</flux:heading>

        <flux:button icon="magnifying-glass" :href="route('station.part-lookup')" wire:navigate>
            {{ __('Part lookup') }}
        </flux:button>
    </div>

    <flux:error name="draft" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input
            wire:model="partEntry"
            wire:keydown.enter.prevent="enterPart"
            :label="__('Part number')"
            :placeholder="__('Scan or type, then press Enter')"
            class="max-w-sm"
            autofocus
        />
        <flux:input wire:model="quantity" :label="__('Quantity')" class="max-w-32" inputmode="decimal" />
        <flux:button wire:click="enterPart">{{ __('Add') }}</flux:button>
    </div>

    @if ($this->matches->isNotEmpty())
        <div class="mb-6 space-y-2">
            <flux:text>{{ __('Matches') }}</flux:text>
            @foreach ($this->matches as $match)
                <flux:button size="sm" wire:click="addPart({{ $match->id }})" wire:key="match-{{ $match->id }}">
                    {{ $match->part_number }} — {{ $match->description }}
                    ({{ \App\Support\Quantity::display($match->stock?->on_hand) }} {{ $match->unit_of_measure }})
                </flux:button>
            @endforeach
        </div>
    @endif

    <flux:table class="mb-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            <flux:table.column align="end">{{ __('On hand') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Resulting') }}</flux:table.column>
            <flux:table.column>{{ __('Warning') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->review->lines as $line)
                <flux:table.row wire:key="line-{{ $line->line->id }}">
                    <flux:table.cell>{{ $line->line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->line->part->description }}</flux:table.cell>
                    <flux:table.cell>{{ $line->line->part->defaultLocation?->code }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($line->onHand) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($line->line->quantity) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($line->resulting) }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($line->needsOverride())
                            <flux:badge size="sm" color="red">{{ __('Not enough stock') }}</flux:badge>
                        @elseif ($line->override)
                            <flux:badge size="sm" color="amber">{{ __('Override authorised') }}</flux:badge>
                        @elseif ($line->level)
                            <flux:badge size="sm" color="amber">{{ $line->level->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($line->needsOverride())
                            <flux:button size="sm" wire:click="requestOverride({{ $line->line->part_id }})">
                                {{ __('Request override') }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="subtle" wire:click="removeLine({{ $line->line->id }})">
                            {{ __('Remove') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:select wire:model="equipmentId" :label="__('Equipment')" class="max-w-xs">
            <flux:select.option value="">{{ __('Choose equipment') }}</flux:select.option>
            @foreach ($this->equipmentOptions as $option)
                <flux:select.option :value="$option->id">{{ $option->code }} — {{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="purposeId" :label="__('Purpose')" class="max-w-xs">
            <flux:select.option value="">{{ __('Choose a purpose') }}</flux:select.option>
            @foreach ($this->purposeOptions as $option)
                <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="remarks" :label="__('Remarks')" class="max-w-sm" />
        <flux:button wire:click="saveContext">{{ __('Save') }}</flux:button>
    </div>
    <flux:error name="equipmentId" />

    <flux:separator class="my-6" />

    @if ($this->draft->personnel)
        <flux:callout icon="identification" class="mb-6">
            <flux:callout.heading>{{ $this->draft->personnel->full_name }}</flux:callout.heading>
            <flux:callout.text>
                {{ $this->draft->personnel->employee_no }}
                @if ($this->draft->personnel->department) · {{ $this->draft->personnel->department }} @endif
                · {{ $this->draft->personnel->badge_uid_masked }}
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="clearIdentification">{{ __('Not this person') }}</flux:button>
            </x-slot>
        </flux:callout>
    @else
        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input
                wire:model="badge"
                wire:keydown.enter.prevent="scan"
                :label="__('Scan personnel ID')"
                :placeholder="__('Ask the person to scan their badge')"
                class="max-w-sm"
            />
            <flux:button wire:click="scan">{{ __('Scan') }}</flux:button>
            <flux:modal.trigger name="manual-identification">
                <flux:button variant="subtle">{{ __('No badge?') }}</flux:button>
            </flux:modal.trigger>
        </div>
        <flux:error name="badge" />
    @endif

    <div class="flex flex-wrap gap-4">
        <flux:button variant="primary" wire:click="confirm">{{ __('Confirm withdrawal') }}</flux:button>
        <flux:button variant="danger" wire:click="cancelDraft">{{ __('Cancel') }}</flux:button>
    </div>

    <flux:modal name="supervisor-override" class="md:w-96">
        <form wire:submit="authoriseOverride" class="space-y-6">
            <flux:heading size="lg">{{ __('Supervisor override') }}</flux:heading>
            <flux:text>{{ __('Enter the quantity counted on the shelf. The adjustment is recorded when the withdrawal is confirmed.') }}</flux:text>

            <flux:input wire:model="verifiedQty" :label="__('Verified quantity on the shelf')" inputmode="decimal" />

            <flux:select wire:model="overrideReason" :label="__('Reason')">
                @foreach (\App\Models\WithdrawalOverride::REASONS as $reason)
                    <flux:select.option :value="$reason->value">{{ __(\Illuminate\Support\Str::headline($reason->value)) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="supervisorEmail" type="email" :label="__('Supervisor email')" />
            <flux:input wire:model="supervisorPassword" type="password" :label="__('Supervisor password')" viewable="false" />

            <flux:button variant="primary" type="submit">{{ __('Authorise') }}</flux:button>
        </form>
    </flux:modal>

    <flux:modal name="manual-identification" class="md:w-96">
        <form wire:submit="identifyManually" class="space-y-6">
            <flux:heading size="lg">{{ __('Identify without a badge') }}</flux:heading>

            <flux:select wire:model="manualPersonnelId" :label="__('Person receiving the parts')">
                <flux:select.option value="">{{ __('Choose a person') }}</flux:select.option>
                @foreach ($this->personnelOptions as $option)
                    <flux:select.option :value="$option->id">{{ $option->full_name }} ({{ $option->employee_no }})</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="manualReason" :label="__('Reason the badge could not be scanned')" />
            <flux:input wire:model="supervisorEmail" type="email" :label="__('Supervisor email')" />
            <flux:input wire:model="supervisorPassword" type="password" :label="__('Supervisor password')" viewable="false" />

            <flux:button variant="primary" type="submit">{{ __('Identify') }}</flux:button>
        </form>
    </flux:modal>
</section>
```

Create `resources/views/livewire/station/receipt.blade.php`:

```blade
@php($transaction = $this->transaction)

<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $transaction->transaction_no }}</flux:heading>
        <flux:button :href="route('station.withdrawal')" wire:navigate>{{ __('New withdrawal') }}</flux:button>
    </div>

    <dl class="mb-6 grid grid-cols-2 gap-4">
        <div>
            <flux:text size="sm">{{ __('Date and time') }}</flux:text>
            <flux:text>{{ $transaction->recorded_at?->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone()) }}</flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Custodian') }}</flux:text>
            <flux:text>{{ $transaction->custodian->name }}</flux:text>
        </div>
        @if ($transaction->personnel)
            <div>
                <flux:text size="sm">{{ __('Received by') }}</flux:text>
                <flux:text>{{ $transaction->personnel->full_name }} ({{ $transaction->personnel->employee_no }})</flux:text>
            </div>
            <div>
                <flux:text size="sm">{{ __('Badge') }}</flux:text>
                <flux:text>{{ $transaction->personnel->badge_uid_masked }}</flux:text>
            </div>
        @endif
        <div>
            <flux:text size="sm">{{ __('Equipment') }}</flux:text>
            <flux:text>{{ $transaction->equipment?->code }} {{ $transaction->equipment?->name }}</flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Purpose') }}</flux:text>
            <flux:text>{{ $transaction->purpose?->name }}</flux:text>
        </div>
        @if ($transaction->remarks)
            <div class="col-span-2">
                <flux:text size="sm">{{ __('Remarks') }}</flux:text>
                <flux:text>{{ $transaction->remarks }}</flux:text>
            </div>
        @endif
    </dl>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Remaining balance') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($transaction->lines as $line)
                <flux:table.row wire:key="receipt-line-{{ $line->id }}">
                    <flux:table.cell>{{ $line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->part->description }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($line->quantity) }} {{ $line->part->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($transaction->movements->firstWhere('stock_transaction_line_id', $line->id)?->balance_after) }}
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

- [ ] **Step 6: Add the routes**

In `routes/spims.php`, add `use App\Livewire\Station;` to the imports and these two routes inside the
existing `auth`/`verified` group, after the `imports` routes:

```php
    Route::livewire('station/withdrawal', Station\Withdrawal::class)->name('station.withdrawal');
    Route::livewire('station/receipt/{transaction}', Station\Receipt::class)
        ->whereNumber('transaction')
        ->name('station.receipt');
```

`station.part-lookup`, referenced by the view's button, is added in Task 11. Until then the view will
raise "Route [station.part-lookup] not defined" — add Task 11's route line first if you want this
task's screen to render on its own while developing.

- [ ] **Step 7: Run the test to verify it passes**

```bash
npm run build
php artisan test tests/Feature/Withdrawals/WithdrawalStationTest.php
```

Expected: PASS, 16 new tests (the role matrix is four). If every full-page `get()` returns 500, the
Vite manifest is missing — run `npm install && npm run build` in the worktree.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Livewire/Station/Withdrawal.php app/Livewire/Station/Receipt.php resources/views/livewire/station/withdrawal.blade.php resources/views/livewire/station/receipt.blade.php routes/spims.php tests/Feature/Withdrawals/WithdrawalStationTest.php
git commit -m "Build the withdrawal station and its transaction record"
```

---

## Task 11: The replenishment screen and the part lookup

**Files:**
- Create: `app/Livewire/Station/Replenishment.php`, `app/Livewire/Station/PartLookup.php`,
  `resources/views/livewire/station/replenishment.blade.php`,
  `resources/views/livewire/station/part-lookup.blade.php`,
  `tests/Feature/Replenishments/ReplenishmentScreenTest.php`,
  `tests/Feature/Parts/PartLookupTest.php`
- Modify: `routes/spims.php`

**Interfaces:**
- Consumes: `ReplenishmentService` (Task 9), `StockLedger::complete()`, `PartSearch`,
  `Permission::ReplenishmentsRecord`, `Permission::PartsView`.
- Produces the routes `station.replenishment` and `station.part-lookup`.

The replenishment screen is the withdrawal screen with the hard parts removed: no badge, no override,
no personnel. Its two warnings — a duplicate delivery reference and a line that goes over the maximum
level — are shown and **do not block**; confirming the screen confirms them (FR-SPIMS-009 alternate
flows).

The part lookup is the FR-SPIMS-016 read the station needs while someone waits: description, storage
location and on-hand quantity, nothing else. Movement history is Plan 6.

A custodian who receives a part that is not in SPIMS yet creates it on the spare parts screen with
`PartsCreateOnReceipt`, which Plan 2 already built; the screen links there rather than duplicating the
form.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Replenishments/ReplenishmentScreenTest.php`:

```php
<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Replenishments\ReplenishmentService;
use App\Domain\Settings\InstallSettings;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Livewire\Station\Replenishment;
use App\Models\Part;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->part = partWithStock('4', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing', 'max_level' => '10']);
    $this->today = CarbonImmutable::now()->setTimezone(app(InstallSettings::class)->timezone())->toDateString();
});

it('opens the replenishment screen only for the roles that record deliveries', function (Role $role, bool $allowed) {
    $response = $this->actingAs(userWithRoles($role))->get(route('station.replenishment'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(roleMatrix('CS'));

it('records a delivery from end to end', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '6')
        ->set('unitCost', '145.50')
        ->call('enterPart')
        ->assertHasNoErrors()
        ->call('confirm')
        ->assertHasNoErrors();

    expect(onHand($this->part))->toBe('10.000')
        ->and(\App\Models\StockTransaction::query()->where('status', TransactionStatus::Completed)->where('type', \App\Enums\TransactionType::Replenishment)->sole()->transaction_no)
        ->toStartWith('RP-');
});

it('warns about a delivery reference already used by that supplier and still confirms', function () {
    $service = app(ReplenishmentService::class);
    $earlier = $service->openDraft($this->custodian);
    $service->setDelivery($earlier, 'Pacific Bearings Inc.', 'DR-00921', $this->today);
    $service->addLine($earlier, $this->part, '1', null);
    app(StockLedger::class)->complete($earlier, $this->custodian);

    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '1')
        ->call('enterPart')
        ->assertSee(__('already recorded'))
        ->call('confirm')
        ->assertHasNoErrors();
});

it('warns about a line that goes over the maximum level and still confirms', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', $this->today)
        ->call('saveDelivery')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '20')
        ->call('enterPart')
        ->assertSee(__('above its maximum level'))
        ->call('confirm')
        ->assertHasNoErrors();

    expect(onHand($this->part))->toBe('24.000');
});

it('refuses a delivery received on an earlier day and names the backdated entry screen', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-1')
        ->set('receivedOn', CarbonImmutable::parse($this->today)->subDay()->toDateString())
        ->call('saveDelivery')
        ->assertHasErrors('receivedOn');
});

it('refuses to confirm before there is a delivery and a line', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->call('confirm')
        ->assertHasErrors('draft');
});

it('adds a part on Enter', function () {
    Livewire::actingAs($this->custodian)->test(Replenishment::class)
        ->assertSeeHtml('wire:keydown.enter.prevent="enterPart"');
});
```

Create `tests/Feature/Parts/PartLookupTest.php`:

```php
<?php

use App\Enums\Role;
use App\Livewire\Station\PartLookup;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->part = partWithStock('7.5', [
        'part_number' => 'BRG-6204',
        'description' => 'Deep groove ball bearing',
        'unit_of_measure' => 'pc',
    ]);
});

it('opens the lookup for every role that can see spare parts', function (Role $role, bool $allowed) {
    $response = $this->actingAs(userWithRoles($role))->get(route('station.part-lookup'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(roleMatrix('CSPA'));

it('shows the description, the location and what is on hand', function () {
    $this->part->defaultLocation()->associate(\App\Models\StorageLocation::factory()->create(['code' => 'A-01-3']))->save();

    Livewire::actingAs(userWithRoles(Role::Custodian))->test(PartLookup::class)
        ->set('search', 'ball bearing')
        ->assertSee('BRG-6204')
        ->assertSee('Deep groove ball bearing')
        ->assertSee('A-01-3')
        ->assertSee('7.5');
});

it('shows nothing until something is typed', function () {
    Livewire::actingAs(userWithRoles(Role::Custodian))->test(PartLookup::class)
        ->assertDontSee('BRG-6204');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Replenishments/ReplenishmentScreenTest.php tests/Feature/Parts/PartLookupTest.php
```

Expected: FAIL — `Route [station.replenishment] not defined.`

- [ ] **Step 3: Write the replenishment component**

Create `app/Livewire/Station/Replenishment.php`:

```php
<?php

namespace App\Livewire\Station;

use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\PartSearch;
use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Domain\Replenishments\ReplenishmentService;
use App\Domain\Settings\InstallSettings;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Spare parts received into the storeroom (PRD FR-SPIMS-009, design §5.4). The withdrawal station
 * without the badge, the override or the personnel: what carries the accountability here is the
 * supplier and the delivery reference.
 */
#[Title('Record a delivery')]
class Replenishment extends Component
{
    use ActsAsSignedInUser;

    public string $supplier = '';

    public string $referenceNo = '';

    public string $receivedOn = '';

    public string $partEntry = '';

    public string $quantity = '1';

    public string $unitCost = '';

    public function mount(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        $draft = $this->draft();
        $this->supplier = (string) ($draft->supplier ?? '');
        $this->referenceNo = (string) ($draft->reference_no ?? '');
        $this->receivedOn = CarbonImmutable::now()
            ->setTimezone(app(InstallSettings::class)->timezone())
            ->toDateString();
    }

    public function saveDelivery(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        try {
            app(ReplenishmentService::class)->setDelivery($this->draft(), $this->supplier, $this->referenceNo, $this->receivedOn);
        } catch (ReplenishmentRefused $refused) {
            $this->addError('receivedOn', $refused->getMessage());

            return;
        }

        $this->forget();
    }

    public function enterPart(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        $entry = trim($this->partEntry);

        if ($entry === '') {
            return;
        }

        $part = Part::query()->whereRaw('lower(part_number) = ?', [Str::lower($entry)])->first();

        if (! $part instanceof Part) {
            $this->addError('partEntry', __('No spare part has that number. Pick one from the matches below, or create it on the spare parts screen.'));

            return;
        }

        $this->addPart($part->id);
    }

    public function addPart(int $partId): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        try {
            app(ReplenishmentService::class)->addLine(
                $this->draft(),
                Part::query()->findOrFail($partId),
                $this->quantity,
                $this->unitCost === '' ? null : $this->unitCost,
            );
        } catch (ReplenishmentRefused $refused) {
            $this->addError('partEntry', $refused->getMessage());

            return;
        }

        $this->partEntry = '';
        $this->quantity = '1';
        $this->unitCost = '';
        $this->forget();
    }

    public function removeLine(int $lineId): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        $draft = $this->draft();
        $line = $draft->lines()->whereKey($lineId)->first();

        if ($line instanceof StockTransactionLine) {
            app(ReplenishmentService::class)->removeLine($draft, $line);
        }

        $this->forget();
    }

    public function confirm(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        $draft = $this->draft();

        try {
            app(ReplenishmentService::class)->guardReadyToConfirm($draft);
            app(StockLedger::class)->complete($draft, $this->actor());
        } catch (ReplenishmentRefused|InstallNotConfigured $refused) {
            $this->addError('draft', $refused->getMessage());

            return;
        } catch (StockBusy $busy) {
            $this->addError('draft', $busy->getMessage());

            return;
        }

        $this->reset(['supplier', 'referenceNo', 'partEntry', 'quantity', 'unitCost']);
        $this->forget();

        Flux::toast(variant: 'success', text: __('Delivery recorded and stock updated.'));
    }

    public function cancelDraft(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        app(ReplenishmentService::class)->cancel($this->draft(), $this->actor());

        $this->reset(['supplier', 'referenceNo', 'partEntry', 'quantity', 'unitCost']);
        $this->forget();
    }

    #[Computed]
    public function draft(): StockTransaction
    {
        return app(ReplenishmentService::class)->openDraft($this->actor());
    }

    #[Computed]
    public function duplicate(): ?StockTransaction
    {
        return app(ReplenishmentService::class)->duplicateDelivery($this->draft());
    }

    /**
     * @return list<Part>
     */
    #[Computed]
    public function overMaximum(): array
    {
        return app(ReplenishmentService::class)->overMaximum($this->draft());
    }

    /**
     * @return Collection<int, Part>
     */
    #[Computed]
    public function matches(): Collection
    {
        if (trim($this->partEntry) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->partEntry)
            ->where('is_active', true)
            ->with('stock')
            ->limit(8)
            ->get();
    }

    private function forget(): void
    {
        unset($this->draft, $this->duplicate, $this->overMaximum, $this->matches);
    }
}
```

- [ ] **Step 4: Write the lookup component**

Create `app/Livewire/Station/PartLookup.php`:

```php
<?php

namespace App\Livewire\Station;

use App\Domain\Parts\PartSearch;
use App\Enums\Permission;
use App\Models\Part;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The read PRD FR-SPIMS-016 asks for at the counter: what a spare part is, where it is, and how
 * much of it there is. Nothing more — the movement history is Plan 6.
 */
#[Title('Part lookup')]
class PartLookup extends Component
{
    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::PartsView);
    }

    /**
     * @return Collection<int, Part>
     */
    #[Computed]
    public function results(): Collection
    {
        if (trim($this->search) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->search)
            ->with(['stock', 'defaultLocation'])
            ->limit(25)
            ->get();
    }
}
```

- [ ] **Step 5: Write the views**

Create `resources/views/livewire/station/replenishment.blade.php`:

```blade
<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Record a delivery') }}</flux:heading>

    <flux:error name="draft" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input wire:model="supplier" :label="__('Supplier')" class="max-w-sm" autofocus />
        <flux:input wire:model="referenceNo" :label="__('DR or PO reference')" class="max-w-xs" />
        <flux:input wire:model="receivedOn" type="date" :label="__('Date received')" class="max-w-xs" />
        <flux:button wire:click="saveDelivery">{{ __('Save delivery') }}</flux:button>
    </div>
    <flux:error name="receivedOn" />

    @if ($this->duplicate)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.heading>{{ __('This reference is already recorded for this supplier.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Recorded as :number. Confirm only if this really is a second delivery.', ['number' => $this->duplicate->transaction_no]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->overMaximum !== [])
        <flux:callout variant="warning" icon="arrow-trending-up" class="mb-6">
            <flux:callout.heading>{{ __('This delivery takes spare parts above their maximum level.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ implode(', ', array_map(fn ($part) => $part->part_number, $this->overMaximum)) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input
            wire:model="partEntry"
            wire:keydown.enter.prevent="enterPart"
            :label="__('Part number')"
            :placeholder="__('Scan or type, then press Enter')"
            class="max-w-sm"
        />
        <flux:input wire:model="quantity" :label="__('Quantity received')" class="max-w-32" inputmode="decimal" />
        <flux:input wire:model="unitCost" :label="__('Unit cost')" class="max-w-32" inputmode="decimal" />
        <flux:button wire:click="enterPart">{{ __('Add') }}</flux:button>
        <flux:button variant="subtle" :href="route('parts.create')" wire:navigate>{{ __('New spare part') }}</flux:button>
    </div>
    <flux:error name="partEntry" />

    @if ($this->matches->isNotEmpty())
        <div class="mb-6 space-y-2">
            @foreach ($this->matches as $match)
                <flux:button size="sm" wire:click="addPart({{ $match->id }})" wire:key="rp-match-{{ $match->id }}">
                    {{ $match->part_number }} — {{ $match->description }}
                </flux:button>
            @endforeach
        </div>
    @endif

    <flux:table class="mb-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Unit cost') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->draft->lines()->with('part')->orderBy('line_no')->get() as $line)
                <flux:table.row wire:key="rp-line-{{ $line->id }}">
                    <flux:table.cell>{{ $line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->part->description }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($line->quantity) }} {{ $line->part->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $line->unit_cost }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button size="sm" variant="subtle" wire:click="removeLine({{ $line->id }})">
                            {{ __('Remove') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <div class="flex flex-wrap gap-4">
        <flux:button variant="primary" wire:click="confirm">{{ __('Confirm delivery') }}</flux:button>
        <flux:button variant="danger" wire:click="cancelDraft">{{ __('Cancel') }}</flux:button>
    </div>
</section>
```

Create `resources/views/livewire/station/part-lookup.blade.php`:

```blade
<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Part lookup') }}</flux:heading>

    <flux:input
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        :label="__('Part number or description')"
        class="mb-6 max-w-lg"
        autofocus
    />

    @if ($this->results->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Part number') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column>{{ __('Location') }}</flux:table.column>
                <flux:table.column align="end">{{ __('On hand') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->results as $part)
                    <flux:table.row wire:key="lookup-{{ $part->id }}">
                        <flux:table.cell>{{ $part->part_number }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $part->description }}</flux:table.cell>
                        <flux:table.cell>{{ $part->defaultLocation?->code }}</flux:table.cell>
                        <flux:table.cell align="end">
                            {{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$part->is_active ? 'green' : 'zinc'">
                                {{ $part->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
```

- [ ] **Step 6: Add the routes**

In `routes/spims.php`, after the two station routes Task 10 added:

```php
    Route::livewire('station/replenishment', Station\Replenishment::class)->name('station.replenishment');
    Route::livewire('station/part-lookup', Station\PartLookup::class)->name('station.part-lookup');
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
npm run build
php artisan test tests/Feature/Replenishments tests/Feature/Parts/PartLookupTest.php
```

Expected: PASS, 18 new tests (two role matrices of four).

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Livewire/Station/Replenishment.php app/Livewire/Station/PartLookup.php resources/views/livewire/station/replenishment.blade.php resources/views/livewire/station/part-lookup.blade.php routes/spims.php tests/Feature/Replenishments/ReplenishmentScreenTest.php tests/Feature/Parts/PartLookupTest.php
git commit -m "Build the replenishment screen and the counter part lookup"
```

---

## Task 12: The station in the menu and in the project's own checks

**Files:**
- Modify: `app/Support/Navigation.php`, `tests/Feature/Access/ScreenAccessMatrixTest.php`,
  `tests/Feature/Access/ActionReauthorizationTest.php`, `tests/Feature/Shell/AppShellTest.php`,
  `tests/Feature/ProvisioningWalkthroughTest.php`

**Interfaces:**
- Consumes: every route and component of Tasks 10 and 11.
- Produces: three sidebar items, and the four new screens covered by the project's standing matrices.

Plan 2 built three standing checks that every later plan has to extend or they quietly stop covering
the system:

- `ScreenAccessMatrixTest` — every screen against every role, from PRD §8.
- `ActionReauthorizationTest` — every data-changing action authorizes again on top of `mount()`.
  Without a row here, deleting an `authorize()` call from an action fails nothing.
- `AppShellTest` — the sidebar shows a user only what they may open.

The permission holders, copied from `Permission::roles()`:

| Screen | Permission | Holders |
|---|---|---|
| Withdrawal station | `WithdrawalsProcess` | `CS--` |
| Transaction record | `MovementsView` | `CSPA` |
| Record a delivery | `ReplenishmentsRecord` | `CS--` |
| Part lookup | `PartsView` | `CSPA` |

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Access/ScreenAccessMatrixTest.php`, add these four rows to the end of the `$screens`
array, and the `use App\Models\StockTransaction;` import:

```php
    'Withdrawal station' => ['station.withdrawal', null, 'CS--'],
    'Transaction record' => [
        'station.receipt',
        fn () => ['transaction' => StockTransaction::factory()->create()],
        'CSPA',
    ],
    'Record a delivery' => ['station.replenishment', null, 'CS--'],
    'Part lookup' => ['station.part-lookup', null, 'CSPA'],
```

In `tests/Feature/Access/ActionReauthorizationTest.php`, add these rows to the `reauthorized actions`
dataset, with the imports
`use App\Livewire\Station\Replenishment as StationReplenishment;` and
`use App\Livewire\Station\Withdrawal as StationWithdrawal;`.

Each row is `[Role, component, fn () => mountParams, action, fn () => actionArgs]`. The test mounts as
a user holding that role, then calls `syncRoles([])` and forgets the permission cache, then calls the
action and asserts 403 — so the role named is the one the action *is* allowed for. Every station
action calls `$this->authorize()` as its **first** statement, which is why a row can pass an argument
the action never reaches: `scan` with no badge and `confirm` on an empty draft are refused before
either is looked at. `Station\PartLookup` gets no rows: it changes no data and has no actions.

```php
    'Station\Withdrawal::enterPart' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'enterPart', fn () => [],
    ],
    'Station\Withdrawal::addPart' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'addPart', fn () => [Part::factory()->create()->id],
    ],
    // The line id is never reached: authorize() is the action's first statement.
    'Station\Withdrawal::removeLine' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'removeLine', fn () => [0],
    ],
    'Station\Withdrawal::saveContext' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'saveContext', fn () => [],
    ],
    'Station\Withdrawal::scan' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'scan', fn () => [],
    ],
    'Station\Withdrawal::clearIdentification' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'clearIdentification', fn () => [],
    ],
    'Station\Withdrawal::requestOverride' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'requestOverride', fn () => [Part::factory()->create()->id],
    ],
    'Station\Withdrawal::authoriseOverride' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'authoriseOverride', fn () => [],
    ],
    'Station\Withdrawal::identifyManually' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'identifyManually', fn () => [],
    ],
    'Station\Withdrawal::confirm' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'confirm', fn () => [],
    ],
    'Station\Withdrawal::cancelDraft' => [
        Role::Custodian, StationWithdrawal::class, fn () => [], 'cancelDraft', fn () => [],
    ],
    'Station\Replenishment::saveDelivery' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'saveDelivery', fn () => [],
    ],
    'Station\Replenishment::enterPart' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'enterPart', fn () => [],
    ],
    'Station\Replenishment::addPart' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'addPart', fn () => [Part::factory()->create()->id],
    ],
    'Station\Replenishment::removeLine' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'removeLine', fn () => [0],
    ],
    'Station\Replenishment::confirm' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'confirm', fn () => [],
    ],
    'Station\Replenishment::cancelDraft' => [
        Role::Custodian, StationReplenishment::class, fn () => [], 'cancelDraft', fn () => [],
    ],
```

`Part` is already imported in that file. If a station row fails with something other than a 403, the
action is doing work before it authorizes — move its `authorize()` call to the first line rather than
changing the row.

In `tests/Feature/Shell/AppShellTest.php`, replace the menu test's body with:

```php
    expect(menuLabels(User::factory()->create()))->toBe([])
        ->and(menuLabels(userWithRoles(Role::Custodian)))
        ->toBe(['Dashboard', 'Withdrawal station', 'Record a delivery', 'Part lookup', 'Spare parts'])
        ->and(menuLabels(userWithRoles(Role::Planner)))
        ->not->toContain('Withdrawal station');
```

Append to `tests/Feature/ProvisioningWalkthroughTest.php` a check that the install can now do the
thing it exists for, right after its final assertion:

```php
it('issues a spare part once the install is provisioned', function () {
    configureInstall();
    $custodian = userWithRoles(Role::Custodian);
    $part = partWithStock('10');
    $person = Personnel::factory()->create();
    app(PersonnelBadges::class)->enroll($person, 'A1234BCDE', $custodian);

    $draft = app(WithdrawalService::class)->openDraft($custodian);
    app(WithdrawalService::class)->addLine($draft, $part, '3');
    app(WithdrawalService::class)->setContext(
        $draft,
        Equipment::factory()->create(),
        WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail(),
        null,
    );
    app(WithdrawalIdentification::class)->identify($draft->fresh(), 'A1234BCDE', $custodian);
    app(WithdrawalService::class)->guardReadyToConfirm($draft->fresh());
    $completed = app(StockLedger::class)->completeWithOverrides($draft->fresh(), $custodian);

    expect($completed->transaction_no)->toStartWith('WD-')
        ->and(onHand($part))->toBe('7.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});
```

with the imports that file does not already have.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Access tests/Feature/Shell tests/Feature/ProvisioningWalkthroughTest.php
```

Expected: FAIL — the menu does not yet hold the station items.

- [ ] **Step 3: Add the menu items**

In `app/Support/Navigation.php`, insert three items into `ITEMS`, between the Dashboard row and the
Spare parts row, so the storeroom's own work sits above its reference data:

```php
        ['group' => 'Storeroom', 'label' => 'Withdrawal station', 'icon' => 'arrow-up-on-square', 'route' => 'station.withdrawal', 'active' => 'station.withdrawal', 'permission' => Permission::WithdrawalsProcess],
        ['group' => 'Storeroom', 'label' => 'Record a delivery', 'icon' => 'truck', 'route' => 'station.replenishment', 'active' => 'station.replenishment', 'permission' => Permission::ReplenishmentsRecord],
        ['group' => 'Storeroom', 'label' => 'Part lookup', 'icon' => 'magnifying-glass', 'route' => 'station.part-lookup', 'active' => 'station.part-lookup', 'permission' => Permission::PartsView],
```

The transaction record has no menu item on purpose: it is reached from a confirmation, and Plan 6's
movement history is where a transaction is looked up later.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Access tests/Feature/Shell tests/Feature/ProvisioningWalkthroughTest.php
```

Expected: PASS. The screen matrix grows by sixteen cases and the reauthorization dataset by sixteen.

- [ ] **Step 5: Run the full check and commit**

```bash
composer test
git add app/Support/Navigation.php tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/Access/ActionReauthorizationTest.php tests/Feature/Shell/AppShellTest.php tests/Feature/ProvisioningWalkthroughTest.php
git commit -m "Put the station in the menu and under the standing access checks"
```

---

## Plan 3 Is Done When

- [ ] All twelve tasks are committed, one commit each, with `composer test` green at every commit.
- [ ] The suite has grown by about **187** cases: Task 1 eleven, Task 2 twenty-four, Task 3 twelve,
      Task 4 ten, Task 5 seventeen, Task 6 fourteen, Task 7 twelve, Task 8 two, Task 9 eighteen,
      Task 10 sixteen, Task 11 eighteen, Task 12 one plus thirty-two matrix rows. A different number
      is fine when the task reports say why.
- [ ] `php artisan migrate:fresh --seed` runs clean on a scratch database with the new migration.
- [ ] `LedgerVerifier::verify()` returns `[]` after a withdrawal, after an override withdrawal, and
      after a replenishment.
- [ ] `tests/Committed` passes against a real second connection (`php artisan config:clear` first).

**Walked in a browser on a scratch demo install, using the keyboard only — no mouse for the whole
walk.** This is the mandatory replacement for the automated browser suite Decision 4 defers, and it is
where Plan 2b's `wire:submit="upload"` class of bug is caught. Signed in as an Inventory Custodian:

- [ ] Open the Withdrawal station from the sidebar. The part entry field already has focus.
- [ ] Type a part number and press Enter. The line appears with its description, location, on-hand and
      resulting balance.
- [ ] Type a description fragment and press Enter. The matches are offered and Tab reaches them.
- [ ] Add a line that takes the part to its minimum level: the line shows the level warning.
- [ ] Tab to Equipment and Purpose, choose both, Save.
- [ ] Choose a purpose that requires remarks and confirm without them: it refuses, naming the purpose.
- [ ] Tab to the scan field and type a badge UID, then Enter. The name, employee number and photo
      appear.
- [ ] Change a quantity. The identification disappears and the scan field is back.
- [ ] Scan again, then wait past the identification time limit and confirm: it refuses and asks for a
      new scan.
- [ ] Scan once more and confirm. The receipt shows the transaction number, the time, the person, the
      masked badge, each part with its quantity and its remaining balance, the equipment and the
      purpose.
- [ ] Add a line for more than is in stock. It shows "Not enough stock" and a Request override button.
- [ ] Request the override, enter the counted quantity, a reason, and a supervisor's email and
      password. It is authorised, **the sidebar still shows the custodian's name**, and the line now
      reads "Override authorised" with the corrected resulting balance.
- [ ] Confirm. Both the adjustment and the withdrawal are recorded, and the part's history shows the
      adjustment immediately before the withdrawal.
- [ ] Start a withdrawal, use "No badge?", pick a person, give a reason and a supervisor's
      credentials. The withdrawal completes and its record says it was identified manually.
- [ ] Record a delivery: supplier, reference, today's date, a part and a quantity. Stock goes up.
- [ ] Record a second delivery with the same supplier and reference: the duplicate warning appears and
      confirming still works.
- [ ] Record a delivery that takes a part above its maximum: the warning appears and confirming still
      works.
- [ ] Set the date received to yesterday: it refuses and names the backdated entry screen.
- [ ] Open the Part lookup and find a part by description; it shows the location and on-hand quantity.
- [ ] Signed in as a Planner: no Withdrawal station, no Record a delivery in the sidebar, and
      `/station/withdrawal` returns 403. Part lookup is there and works.
- [ ] Signed in as a System Administrator: same as the Planner. `OverridesAuthorise` and
      `IdentificationAuthorise` are Inventory Supervisor permissions only (PRD §8), so an
      administrator's credentials are refused at the counter — confirm that the refusal names an
      Inventory Supervisor rather than looking like a wrong password.

## Notes for Later Plans

Carry these to the plan named, in this plan's ledger under `.superpowers/sdd/`.

**Plan 4 (corrections and counting).**

- **A replenishment received on an earlier day is owed here.** Decision 10: Plan 3 refuses it and
  points at the backdated entry screen, which Plan 4 builds. Make sure the message Plan 3 shows
  (`ReplenishmentRefused::receivedEarlier()`) matches what the screen is actually called.
- **Override adjustments are already identifiable as non-reversible.** The roadmap's design gap "a
  transaction origin marker, so override and count adjustments are identifiable" needs no new column
  for the override half: `withdrawal_overrides.adjustment_transaction_id` points at exactly those
  adjustments, so `exists()` against it is the test. Count postings still need their own answer.
- **Reversing a withdrawal that carried an override reverses only the withdrawal** (design §5.5). The
  override adjustment stands, because the stock was physically there.
- `guardDirections()` must accept the opposite direction on reversing transactions; note that
  `postWithOverrides()` calls it too, so any change lands on both paths.

**Plan 5 (analytics, prediction, alerts and dashboard).**

- **`StockDiscrepancyReported` becomes the `inventory_discrepancy` alert.** It carries `partId`,
  `withdrawalOverrideId`, `withdrawalTransactionId` and `wasAdjusted`. Open the alert on either value
  of `wasAdjusted`: a discarded override means the balance had already been corrected by someone else,
  which is still a part that needs counting.
- **Alert evaluation after an override posting is not short of parts.** `completeWithOverrides()`
  dispatches `StockTransactionCompleted` for the withdrawal only, not for the adjustment transactions
  it writes. That is not a gap: every adjusted part is a line of the withdrawal, so evaluating the
  withdrawal's parts covers them. Do not "fix" it by dispatching per adjustment, which would evaluate
  the same parts two or three times per confirmation at the counter.
- Plan 3 leaves `/dashboard` ungated; gating it on `DashboardView` is still Plan 2's carried note.

**Plan 6 (reports and exports).**

- **The keyboard-only browser test the roadmap §4 lists is unmet and is owed here.** Decision 4
  defers it: Plan 3 covers the keyboard contract with Livewire assertions and a mandatory manual walk.
  Plan 6 is the first plan with enough screens to justify a browser driver in CI; choose between the
  Pest browser plugin and Playwright then, and write the station's scan-last flow as its first test.
- The **Exceptions report (J)** reads `stock_transactions.identification_method = 'supervisor_manual'`
  for manual identifications and the `withdrawal_overrides` table for overrides. An override's
  supervisor, reason, `recorded_on_hand` and `verified_qty` are all on that row.
- The transaction record of FR-SPIMS-007 is already rendered by `App\Livewire\Station\Receipt`; reuse
  its field list so the report and the receipt cannot disagree.

**Plan 7 (operations and go-live).**

- **Abandoned drafts are never cleaned up.** One open draft per custodian per type means they do not
  multiply, but a cancelled-then-forgotten cart stays as a `draft` row for good. Decide whether that
  needs a retention job or is simply accepted, and say so in the runbook.
- **`CounterAuthorisation` keys its lockout on `request()->ip()`**, which behind a reverse proxy is the
  proxy's address — so one station's failures would lock the supervisor out of every station. This is
  the same trusted-proxy note Plan 2 already carried for the sign-in lockout; settle both together.
- The identification time limit (`withdrawal.identification_timeout_seconds`, default 120) is worth a
  line in the provisioning checklist: a storeroom with a slow counter may want it longer, and it is a
  setting, not a constant.

**For Microtech.**

- **Q1, badge technology.** `KeyboardWedgeBadgeReader::normalise()` accepts 4 to 64 characters of
  `A–Z0–9` after stripping whitespace, colons and hyphens. Test the real reader against it before
  go-live; if it emits anything else, only that class changes (design §10).
- **Q11, supervisor coverage at night.** Both the override and the manual identification need an
  Inventory Supervisor's credentials at the counter. If no supervisor is reachable on nights, the
  custodian's only options are to reduce the quantity or remove the line, which is the paper-fallback
  scenario the whole design is trying to avoid.
- **Q12, manual identification.** Built, per Decision 3. If Microtech says no, the change is to stop
  granting `IdentificationAuthorise`, not to remove code.

## Self-Review

Run after the plan is written, before execution starts.

**Spec coverage.** Every requirement this plan owns, against the task that implements it:

| Requirement | Tasks |
|---|---|
| FR-SPIMS-006 prepare withdrawal | 2, 3, 10 |
| FR-SPIMS-007 confirm through badge scan | 4, 5, 7, 10 |
| FR-SPIMS-008 insufficient stock override | 1, 5, 6, 7, 8, 10 |
| FR-SPIMS-009 record replenishment | 9, 11 |
| FR-SPIMS-016 part lookup | 11 |
| NFR-SPIMS-005 accountability of both actors | 4, 5, 7 |
| NFR-SPIMS-006 confirmation and lookup latency | 7 (one lock acquisition, one balance write), 11 |
| NFR-SPIMS-007 drafts survive an interruption | 2, 9 (drafts are rows) |
| NFR-SPIMS-009 keyboard and reader only | 10, 11, and the Done When walk |
| Design §5.1 the eight steps | 2, 3, 4, 7, 10 |
| Design §5.2 lock ordering, short transactions | 7, 8 |
| Design §5.3 the override path, every rule | 6, 7, 8 |
| Design §5.4 replenishment | 9, 11 |
| Design §10 hardware boundary | reused unchanged from Plan 1 |

**Known gaps, stated rather than hidden:**

- The roadmap's keyboard-only **browser** test is not written (Decision 4), and is carried to Plan 6.
- A replenishment received on an earlier day is refused, not recorded (Decision 10), and is carried to
  Plan 4.

**Type consistency.** The names later tasks depend on, defined once and used unchanged:
`WithdrawalService::{openDraft, addLine, setLineQuantity, removeLine, setContext, setLineContext,
cancel, guardReadyToConfirm, invalidate, review, authoriseOverride}`;
`WithdrawalIdentification::{identify, identifyManually, clear, isCurrent, expiresAt, timeoutSeconds}`;
`CounterAuthorisation::verify`; `StockLedger::completeWithOverrides`;
`ReplenishmentService::{openDraft, setDelivery, addLine, setLineQuantity, removeLine, cancel,
guardReadyToConfirm, duplicateDelivery, overMaximum}`; `LineReview::{onHand, resulting, isShort,
override, level, needsOverride}`; `WithdrawalReview::{lines, isShort, needsOverride,
shortPartNumbers}`; `WithdrawalOverride::{REASONS, isPending, transaction, part, authorisedBy,
adjustment}`.
