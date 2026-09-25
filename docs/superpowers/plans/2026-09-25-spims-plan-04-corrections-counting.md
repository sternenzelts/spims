# SPIMS Plan 4: Corrections and Counting — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A supervisor corrects stock with an adjustment or by reversing a completed transaction. A
custodian enters a withdrawal or a delivery from the paper fallback form after an outage. The
storeroom runs physical counts that make every counted balance equal what is on the shelf.

**Architecture:**
- `StockLedger` gains two postings: `reverse()`, which writes equal and opposite movements that keep
  the original's movement type and time, and `postCount()`, which sets each counted part's balance to
  its counted quantity under the stock row lock. Adjustments and paper-form entries need no new
  posting: they create a transaction and post it through the existing `complete()`, inside one outer
  database transaction, so a refusal leaves nothing behind.
- Two new tables, `count_sessions` and `count_lines` (design §4.3), and two new columns on
  `stock_transactions`: `count_session_id` and `backdate_authorised_by_user_id`.
- New domain code in `app/Domain/Adjustments`, `app/Domain/Backdating` and `app/Domain/Counting`.
- Six new Livewire screens: stock adjustment, transactions (list and record, where a reversal
  starts), paper form entry, and stock counts (list and session).

**Tech Stack:** as Plans 2, 2b and 3, with no new dependency. PHP 8.4+, Laravel 13, Livewire 4 with
Flux 2 (free components), PostgreSQL 17, `brick/math`, Pest 5, Larastan (PHPStan level 7) and Pint.

**Spec:** `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`, §4.3 (count tables),
§4.5, §5.5, §5.6 to §5.6.3, §8.4 and §13.1.

**Related documents:**
- roadmap §5: `docs/superpowers/plans/2026-09-13-spims-microtech-roadmap.md`
- requirements FR-SPIMS-010 to 013, §7.3 to §7.5 and the §8 permission table:
  `docs/prd/Microtech_SPIMS_PRD_v1.0.md`
- Plan 3, for `StockLedger::completeWithOverrides()`, `CounterAuthorisation` and the station
  screens: `docs/superpowers/plans/2026-09-23-spims-plan-03-withdrawal-replenishment.md`

## Global Constraints

Plans 1, 2, 2b and 3 hold unchanged:

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
  - A migration from an earlier plan is never edited. This plan changes the ledger's constraints in a
    migration of its own.
- **Quantities are decimal strings in PHP**, with arithmetic in `Brick\Math\BigDecimal`. Never cast a
  quantity to `float` or `int`.
- **The ledger invariant:** for every part, `part_stock.on_hand = SUM(stock_movements.quantity)`. Every
  change to `on_hand` goes through `App\Domain\Ledger\StockLedger`, never a direct write.
  `stock_movements` and `audit_logs` are append-only, enforced by the
  `prevent_append_only_mutation()` trigger — nothing in this plan updates a row in either table.
- **Lock order (design §5.2):** a posting locks its own row first (the transaction, or the count
  session), then every affected `part_stock` row in one statement ordered by part id. Nothing in this
  plan locks stock rows any other way.
- **All timestamps are stored in UTC.** A date or time a person types is read in the install time zone
  (`InstallSettings::timezone()`), and "today" means today in that time zone.
- **No company-specific values in code.** Reasons, the backdating limit and the count tolerance are
  settings or enum values.
- **The morph map is enforced.** Every audited model is listed in
  `AppServiceProvider::configureMorphMap()`.
- **Every screen authorizes twice.** `mount()` and every data-changing action call
  `$this->authorize(Permission::…)`, and every screen has a role-by-action test built with
  `roleMatrix()`. The domain classes check the permission again where Plan 3's did.
- **Nothing is deleted** except a line of something not yet posted (a draft line, a count line), and
  that removal is audited. Completed transactions are corrected by reversal, never edited.
- **A supervisor at the counter never replaces the custodian's session.** `CounterAuthorisation`
  verifies a password in the request that uses it and returns a `User`. Never keep a verified
  supervisor in component state for a later request.
- **A Livewire action must never be named after Livewire's own JS API** (`upload`, `get`, `set`,
  `call`, `dispatch`, `commit`, `refresh`, …). `LivewireActionNamesTest` fails the suite if one is.
- **Livewire 4 names a component class called `Index` in a subfolder after the subfolder.**
  `App\Livewire\Transactions\Index` renders `resources/views/livewire/transactions.blade.php`, and
  `App\Livewire\Counts\Index` renders `resources/views/livewire/counts.blade.php`.
- **Tests:**
  - Pest function syntax.
  - A test that expects a database error wraps the failing call in `inSavepoint()`.
  - Audit `old_values` and `new_values` come back from `jsonb` with keys shorter first, equal lengths
    in byte order. A multi-key `toBe()` lists keys in that order.
  - Timestamps are written through Eloquent to the whole second, so tests that compare "before" and
    "after" travel at least one second (`$this->travel(1)->seconds()`).
  - Full-page screen tests need `npm install && npm run build` in the worktree, or every
    `get(route(...))` returns 500 with a missing Vite manifest.
- **Commits:** one per task, with the message given in the task, staged by path. Never `git add -A`.

Added by this plan:

- **Two postings are new, and only two.** `StockLedger::reverse()` and `StockLedger::postCount()`.
  Adjustments and paper-form entries create a transaction and hand it to `StockLedger::complete()`
  inside one `DB::transaction()`. `complete()`'s own transaction becomes a savepoint, so a refusal rolls
  the whole thing back and no draft row is ever left behind.
- **A reversal copies an explicit list of columns and nothing else** (Decision 3).
- **A count posts against the balance at posting, read under lock** (design §5.6.1):
  `posting_delta = counted_qty − on_hand_at_post`. The snapshot `system_qty` is never posted.
- **Bulk writes in a count posting are chunked at 500 rows.** Postgres refuses a statement with more
  than 65,535 bound parameters, and a movement row binds 12.

## Review Focus

The five inputs the spec implies but no requirement names, most likely to bite first. Each has its
test in the task named.

1. **Reversing something unusual.** A backdated paper-form withdrawal, a manually identified
   withdrawal, and a withdrawal whose line was issued to a different machine than the header must all
   reverse cleanly. The unique `paper_form_no` and the manual-identification CHECK must not fire, and
   the withdrawals booked to each machine and purpose must net to zero. — Task 3.
2. **A supervisor's password reused for a later entry.** An entry older than the backdating limit
   needs a supervisor. Their password is checked in the same request as the confirmation and is
   cleared afterwards, so it can never approve a different date or different lines on a later click.
   — Task 7.
3. **A refused paper-form entry leaving a draft behind.** `WithdrawalService::openDraft()` resumes any
   draft withdrawal the custodian owns. A backdated entry refused for lack of stock must leave no row,
   or it would turn up as the custodian's live cart. — Task 6.
4. **A big count.** A full count of a large storeroom posts hundreds or thousands of differences in
   one database transaction. Crossing the 500-row chunk boundary must post every line. — Task 9.
5. **The backdating limit around midnight.** The limit counts calendar days in the install time
   zone. An entry exactly `backdating.limit_days` old is within the limit; one day older is not, and
   that holds just after local midnight, when the date in UTC is still yesterday. — Task 6.

---

## Before You Start

Read these first:

- PRD FR-SPIMS-010, 011, 012 and 013 in full, including every Alternate Flow, and §7.3 to §7.5.
- Design §4.5 (what `balance_after` means under backdating), §5.5, §5.6, §5.6.1, §5.6.2, §5.6.3, §8.4
  and §13.1.
- `app/Domain/Ledger/StockLedger.php`, in full. Tasks 4 and 9 change it, and its private helpers are
  reused rather than copied.
- `database/migrations/2026_09_14_100006_create_ledger_tables.php` and
  `2026_09_23_100001_add_withdrawal_station_tables.php`, for the CHECK constraints every transaction
  must satisfy.
- `app/Livewire/Station/Replenishment.php` and `Withdrawal.php` with their views, for the screen
  pattern and `CounterAuthorisation` at the counter; `tests/Pest.php` for the helpers.

Work in a worktree off `master` (superpowers:using-git-worktrees). Copy the main checkout's `.env`
into it, then run `composer install` and `npm install && npm run build`. `npm install` rewrites
`package-lock.json`; restore it with `git restore package-lock.json` before committing.

**How the code in this plan was checked.** It was written against `master` at `e2bdc1a`, reading the
merged source of `StockLedger`, `TransactionNumberer`, `InstallSettings`, `SettingValues`,
`CounterAuthorisation`, `ReplenishmentService`, `WithdrawalService`, `AuditLogger`, `Navigation`, the
`Permission`, `TransactionType`, `MovementType` and `ReasonCode` enums, the ledger and station
migrations, `StockTransactionFactory`, `tests/Pest.php`, `LedgerInvariantTest`,
`LivewireActionNamesTest`, `ScreenAccessMatrixTest` and `AppShellTest`. Every existing class name,
method signature and constraint name below is copied from that source.

The code was **not** executed in a scratch copy first. Treat every code block as a careful draft:
write each task's tests, watch them fail, then write the code. If a result differs from the expected
output, fix the code, keep the test's intent, and record the deviation with its evidence in the task
report.

## Decisions Made in This Plan

These settle the three decisions the roadmap §5 left open, the two design gaps §10 assigns to this
plan, and the questions the spec leaves to the implementer.

1. **A count posting is an `adjustment` transaction with `count_variance` movements.** Design §5.6.3
   says a count session "emits exactly one adjustment transaction with one line per part", and PRD
   FR-SPIMS-018 defines four number prefixes (WD, RP, AJ, OP), not five. So `stock_transactions.type`
   gains no `count` value, and count postings are numbered AJ. `StockLedger::postCount()` writes the
   movements itself with `MovementType::CountVariance`, so §7.4's movement types still tell them
   apart. A count's reasons are per part and live on `count_lines`, so the `adjustment_has_reason`
   CHECK is relaxed for a count posting only.
2. **No general origin column.** An adjustment's origin is already recorded where it happened:
   `withdrawal_overrides.adjustment_transaction_id` points at every override adjustment (Plan 3), and
   the new `stock_transactions.count_session_id` marks every count adjustment. The reversal guard reads
   both. A manual adjustment is one with neither.
3. **A reversal is a transaction of the original's type, with every line in the opposite direction.**
   - Its movements keep the original's movement type and carry the original's `occurred_at`
     (design §5.5), so a reversed withdrawal nets to zero in the month it happened. Their
     `balance_after` is stamped in recording order (design §4.5).
   - It is numbered with the original's prefix: reversing WD-2026-000123 writes another WD number.
   - Who reversed and why are stored on the **original**, alongside its new `reversed` status
     (`reversed_by_user_id`, `reversal_reason`); a new CHECK requires both. The reversing
     transaction's `custodian_user_id` is the supervisor, and `reverses_transaction_id` links it back.
   - **It copies this list of columns and no other:** from the header `personnel_id`, `equipment_id`,
     `purpose_id`, `supplier`, `reference_no` and `reason_code`; from each line `part_id`,
     `quantity`, `equipment_id`, `purpose_id` and `unit_cost`. It never copies `paper_form_no`
     (unique), `is_backdated`, `backdate_reason`, `identified_at` or the identification columns. The
     attribution CHECK already exempts reversing withdrawals, and copying `supervisor_manual` without
     its supervisor and reason would break `manual_identification_is_authorised`.
   - Not reversible (PRD FR-SPIMS-011): anything not Completed, opening balances, reversing
     transactions, override adjustments and count adjustments. Reversing a withdrawal that carried an
     override reverses the withdrawal only.
4. **Returning unused parts, until Q13 is answered:** reverse the withdrawal, then record what was
   actually used as a new withdrawal at the station, with the person present to scan their badge.
   This is FR-SPIMS-011's own alternate flow ("reverse the whole transaction and record the correct
   one again"). It moves the consumption to the day of the new withdrawal. No code; the Done When walk
   runs it once.
5. **An adjustment corrects one spare part.** FR-SPIMS-010's main flow selects one part, shows its
   balance and the resulting balance, and confirms. `AdjustmentService::record()` takes one part.
6. **A paper-form entry has its own screen, and nothing is saved until it is confirmed.**
   - One screen, **Paper form entry**, covers both kinds, with a choice between a withdrawal and a
     delivery. The withdrawal station and the delivery screen link to it, which is FR-SPIMS-012
     step 1: "selects New Withdrawal or New Replenishment and selects Backdated Entry".
   - The paper form is in the custodian's hand, so there is no draft row: the screen holds the entry,
     and confirming creates and posts it in one database transaction. This keeps backdated entries
     away from the one-draft-per-custodian rule of the live screens.
   - The delivery screen's refusal of an earlier day now names this screen.
7. **An entry older than the limit is authorised by a supervisor at the counter, in the same request
   as the confirmation.**
   - The limit is `backdating.limit_days`, counted in calendar days in the install time zone. An entry
     exactly that many days old is within it.
   - A custodian's entry beyond the limit shows a supervisor email and password on the form.
     `CounterAuthorisation::verify()` checks them for `BackdatedRecordBeyondLimit` when Confirm is
     pressed, and the password field is cleared after every attempt, whatever the outcome.
   - A supervisor entering it themselves needs nothing more.
   - Either way, `backdate_authorised_by_user_id` names the supervisor, so every entry beyond the limit
     is marked and every entry within it is not.
8. **A backdated withdrawal that exceeds the recorded balance is refused, and the refusal tells the
   custodian to fetch a supervisor to correct the balance first** — by counting the part, or with an
   Unrecorded Receipt adjustment — **then enter the form again.** This departs from FR-SPIMS-012's
   alternate flow, which directs to the FR-SPIMS-008 override. The override asks a supervisor to count
   the shelf and verify at least the line's quantity, but the paper-form withdrawal already emptied
   that shelf, so the count can never reach it. **Carried to Microtech as a question.**
9. **A paper-form entry follows the live screens' Active rules.** Its parts, personnel, equipment and
   purpose must be Active, as at the station. A person or machine deactivated after the form was
   signed is reactivated long enough to enter it. The PRD does not carve out an exception, and making
   one would let a deactivated badge holder be booked parts. **Carried to Microtech as a question.**
10. **A paper form number is stored trimmed and in capitals**, so `pf-12` and `PF-12` are one form.
    The unique index is the backstop for two custodians entering the same form at once.
11. **A count's scope includes Inactive parts that still hold stock.** PRD §7.2 says Inactive parts
    "can still be adjusted and counted". A full, location or criticality session takes every Active
    part in scope plus every Inactive part with a balance other than zero. An Inactive part with no
    stock is left out. An `adhoc` session (design §4.3) counts a list of part numbers, Active or not.
12. **Counting is blind.** A custodian entering counts sees the part, its description and location,
    and never the recorded balance or the difference. Seeing the expected number biases a count
    towards it. Supervisors see both when they review.
13. **A count is stale when stock of its part was recorded moving at or after the count** — keyed on
    `recorded_at`, compared with `>=`, as Plan 3's override guard is. PRD FR-SPIMS-013 says
    "recorded"; design §5.6.1 says "occurred", but a backdated entry or a reversal carries a past
    `occurred_at` and would slip past an occurred-time check although the count never saw it.
    Timestamps are stored to the second, so a movement in the same second as the count counts as
    after it. A supervisor's acknowledgement covers the movements recorded before it; a later one
    makes the line stale again. A recount replaces the acknowledgement.
14. **A count session number is the year and a sequence, such as `2026-0007`.** It comes from
    `transaction_number_sequences` under the key `count-session`, which can never equal a transaction
    prefix (those are letters only), so it never disturbs the gap-free transaction numbers. It is not
    a setting: it is not a transaction number, and FR-SPIMS-018 lists no prefix for it.
15. **Counts are entered while a session is Open or Counted.** Counted means counting is finished and
    under review; a supervisor who finds a stale line has it recounted then. Only posting and
    cancelling end a session.
16. **A supervisor may remove a line from a session that is not posted** (FR-SPIMS-013 alternate
    flow). The line is deleted, like a draft line, and the removal is audited. The last line cannot be
    removed: cancel the session instead.
17. **Plan 5 gets two events.** `CountSessionCounted` when a session is marked Counted (FR-SPIMS-013
    step 6 opens discrepancy alerts) and `CountSessionPosted` after posting (step 10 resolves them).
    Both dispatch after commit. Nothing listens to them yet.
18. **Completed transactions get a list and a record screen**, because FR-SPIMS-011 starts with "the
    supervisor opens the Completed transaction" and nothing lists them yet. The station receipt's
    layout moves into a Blade component that both screens render, so the two cannot disagree.

## File Map

**Created**

| Path | Responsibility |
|---|---|
| `database/migrations/2026_09_25_100001_add_corrections_and_counting_tables.php` | `count_sessions`, `count_lines`; `stock_transactions.count_session_id` and `backdate_authorised_by_user_id`; the changed and new CHECKs |
| `app/Enums/CountStatus.php`, `app/Enums/CountScope.php` | Session status (§7.5) and scope |
| `app/Models/CountSession.php`, `app/Models/CountLine.php` | The count tables |
| `database/factories/CountSessionFactory.php` | Sessions for tests |
| `app/Domain/Adjustments/AdjustmentService.php`, `Exceptions/AdjustmentRefused.php` | FR-SPIMS-010 |
| `app/Domain/Ledger/Exceptions/ReversalRefused.php` | Why a transaction cannot be reversed |
| `app/Domain/Backdating/PaperForm.php`, `PaperFormLine.php` | What a paper form says |
| `app/Domain/Backdating/BackdatedEntryService.php`, `Exceptions/BackdatedEntryRefused.php` | FR-SPIMS-012 |
| `app/Domain/Counting/CountSessions.php` | Open, count, review, remove, acknowledge, cancel |
| `app/Domain/Counting/CountReview.php`, `CountLineReview.php` | What the review screen shows for each line |
| `app/Domain/Counting/ReasonRule.php` | Design §5.6.2 |
| `app/Domain/Counting/CountStaleness.php` | Decision 13, shared by review and posting |
| `app/Domain/Counting/Exceptions/CountRefused.php` | Every count refusal |
| `app/Domain/Counting/Events/CountSessionCounted.php`, `CountSessionPosted.php` | Decision 17 |
| `app/Livewire/Corrections/Adjustment.php` + `resources/views/livewire/corrections/adjustment.blade.php` | Stock adjustment screen |
| `resources/views/components/transaction-record.blade.php` | The transaction record, shared |
| `app/Livewire/Transactions/Index.php` + `resources/views/livewire/transactions.blade.php` | Find a transaction |
| `app/Livewire/Transactions/Show.php` + `resources/views/livewire/transactions/show.blade.php` | Transaction record and reversal |
| `app/Livewire/Station/PaperFormEntry.php` + `resources/views/livewire/station/paper-form-entry.blade.php` | Paper form entry screen |
| `app/Livewire/Counts/Index.php` + `resources/views/livewire/counts.blade.php` | Count sessions list, open a session |
| `app/Livewire/Counts/Show.php` + `resources/views/livewire/counts/show.blade.php` | Count, review, post, cancel |
| Tests under `tests/Feature/{Counting,Adjustments,Backdating,Transactions,Ledger}` and `tests/Unit/ReasonRuleTest.php` | |

**Modified**

| Path | Change |
|---|---|
| `app/Models/StockTransaction.php` | New columns and relations |
| `app/Providers/AppServiceProvider.php` | Morph map entries for `CountSession` and `CountLine` |
| `app/Domain/Ledger/StockLedger.php` | `reverse()`, `guardReversible()`, `postCount()`; `guardDirections()` accepts a reversal; `lockBalances()` takes part ids |
| `app/Domain/Ledger/TransactionNumberer.php` | `nextCountSession()` |
| `app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php` | `receivedEarlier()` names the Paper form entry screen |
| `app/Domain/Access/Exceptions/AuthorisationRefused.php` | `credentialsRequired()` for a form older than the limit |
| `app/Livewire/Station/Receipt.php`, `resources/views/livewire/station/receipt.blade.php` | Render the shared component |
| `resources/views/livewire/station/withdrawal.blade.php`, `replenishment.blade.php` | A Backdated entry link |
| `app/Support/Navigation.php` | Four menu items |
| `routes/spims.php` | Six routes |
| `tests/Feature/Shell/AppShellTest.php`, `tests/Feature/Access/ScreenAccessMatrixTest.php`, `tests/Feature/Access/ActionReauthorizationTest.php` | New menu items, screens and actions |
| `tests/Feature/Ledger/LedgerInvariantTest.php` | Reversals and count postings in the generator |

---

## Task 1: The count tables and the ledger's new columns

**Files:**
- Create: `database/migrations/2026_09_25_100001_add_corrections_and_counting_tables.php`,
  `app/Enums/CountStatus.php`, `app/Enums/CountScope.php`, `app/Models/CountSession.php`,
  `app/Models/CountLine.php`, `database/factories/CountSessionFactory.php`
- Modify: `app/Models/StockTransaction.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Counting/CountSchemaTest.php`

**Interfaces:**
- Consumes: `stock_transactions`, `parts`, `users` (Plan 1); `ReasonCode`.
- Produces:
  - `App\Enums\CountStatus` (`Open`, `Counted`, `Posted`, `Cancelled`) and `App\Enums\CountScope`
    (`Full`, `Location`, `Criticality`, `Adhoc`, each with `label(): string`).
  - `App\Models\CountSession` with `lines(): HasMany<CountLine>`, `openedBy(): BelongsTo<User>`,
    `adjustment(): HasOne<StockTransaction>`. Status and the attribution columns are **not**
    fillable.
  - `App\Models\CountLine` with `session(): BelongsTo<CountSession>`, `part(): BelongsTo<Part>`,
    `countedBy(): BelongsTo<User>`. Only `part_id` and `system_qty` are fillable.
  - `StockTransaction` properties `count_session_id` (not fillable) and
    `backdate_authorised_by_user_id` (fillable), and relations `reverses()`, `reversal()`,
    `reversedBy()`, `backdateAuthorisedBy()`, `countSession()`.
  - Morph aliases `count_session` and `count_line`.
  - `CountSession::factory()`: an Open, full-scope session with no lines.

Design §4.3 defines both count tables. This task keeps their columns and adds what the PRD §7.5
lifecycle needs to record who did what: who marked the session counted, who cancelled it, who counted
each line and who acknowledged a stale one. `count_lines` has no `on delete cascade`: nothing deletes a session.
`snapshot_variance` is a stored generated column, written in raw SQL because design §12.2 notes that
Postgres has stored generated columns only.

Two columns join `stock_transactions`. `count_session_id` marks the adjustment a count posting writes
(Decisions 1 and 2), and a count posting is exempt from `adjustment_has_reason` because its reasons
are per part. `backdate_authorised_by_user_id` names the supervisor who authorised a backdated entry
beyond the limit (Decision 7).

`reversed_is_explained` is also new. Plan 1 created `reversed_by_user_id` and `reversal_reason`, but
nothing required either of them.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Counting/CountSchemaTest.php`:

```php
<?php

use App\Enums\CountScope;
use App\Enums\CountStatus;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('opens a factory session as an open, full-scope count with no lines', function () {
    $session = CountSession::factory()->create()->refresh();

    expect($session->status)->toBe(CountStatus::Open)
        ->and($session->scope)->toBe(CountScope::Full)
        ->and($session->variance_tolerance_pct)->toBe('0.000')
        ->and($session->lines)->toBeEmpty()
        ->and($session->getMorphClass())->toBe('count_session')
        ->and((new CountLine)->getMorphClass())->toBe('count_line');
});

it('stores the snapshot variance as the counted quantity minus the system quantity', function () {
    $line = CountSession::factory()->create()->lines()->create([
        'part_id' => Part::factory()->create()->id,
        'system_qty' => '5',
    ]);

    $line->forceFill([
        'counted_qty' => '3.5',
        'counted_at' => now(),
        'counted_by_user_id' => User::factory()->create()->id,
    ])->save();

    expect($line->refresh()->snapshot_variance)->toBe('-1.500');
});

it('refuses a negative counted quantity', function () {
    $line = CountSession::factory()->create()->lines()->create([
        'part_id' => Part::factory()->create()->id,
        'system_qty' => '5',
    ]);

    expect(inSavepoint(fn () => $line->forceFill([
        'counted_qty' => '-1',
        'counted_at' => now(),
        'counted_by_user_id' => User::factory()->create()->id,
    ])->save()))->toThrow(QueryException::class, 'count_lines_counted_qty_non_negative');
});

it('refuses a count that does not say when it was taken and by whom', function () {
    $line = CountSession::factory()->create()->lines()->create([
        'part_id' => Part::factory()->create()->id,
        'system_qty' => '5',
    ]);

    expect(inSavepoint(fn () => $line->forceFill(['counted_qty' => '4'])->save()))
        ->toThrow(QueryException::class, 'count_lines_counted_is_attributed');
});

it('refuses the same spare part twice in one session', function () {
    $session = CountSession::factory()->create();
    $part = Part::factory()->create();
    $session->lines()->create(['part_id' => $part->id, 'system_qty' => '5']);

    expect(inSavepoint(fn () => $session->lines()->create(['part_id' => $part->id, 'system_qty' => '5'])))
        ->toThrow(QueryException::class, 'count_lines_session_part_unique');
});

it('refuses a location or criticality session that does not say which', function (CountScope $scope) {
    expect(inSavepoint(fn () => CountSession::factory()->create(['scope' => $scope, 'scope_value' => null])))
        ->toThrow(QueryException::class, 'count_sessions_scope_value_matches');
})->with([CountScope::Location, CountScope::Criticality]);

it('refuses a posted session that does not say who posted it', function () {
    $session = CountSession::factory()->create();

    expect(inSavepoint(fn () => DB::table('count_sessions')->where('id', $session->id)->update([
        'status' => 'posted',
        'marked_counted_by_user_id' => $session->opened_by_user_id,
        'marked_counted_at' => now(),
    ])))->toThrow(QueryException::class, 'count_sessions_posted_is_attributed');
});

it('lets a count posting complete an adjustment with no reason on the transaction', function () {
    $session = CountSession::factory()->create();
    $adjustment = StockTransaction::factory()->adjustment()->create(['reason_code' => null]);

    DB::table('stock_transactions')->where('id', $adjustment->id)->update([
        'status' => 'completed',
        'transaction_no' => 'AJ-2026-900001',
        'recorded_at' => now(),
        'count_session_id' => $session->id,
    ]);

    expect($adjustment->refresh()->count_session_id)->toBe($session->id)
        ->and($session->adjustment?->is($adjustment))->toBeTrue();
});

it('still refuses any other completed adjustment without a reason', function () {
    $adjustment = StockTransaction::factory()->adjustment()->create(['reason_code' => null]);

    expect(inSavepoint(fn () => DB::table('stock_transactions')->where('id', $adjustment->id)->update([
        'status' => 'completed',
        'transaction_no' => 'AJ-2026-900002',
        'recorded_at' => now(),
    ])))->toThrow(QueryException::class, 'adjustment_has_reason');
});

it('refuses a count session on anything but an adjustment', function () {
    $session = CountSession::factory()->create();
    $withdrawal = StockTransaction::factory()->create();

    expect(inSavepoint(fn () => DB::table('stock_transactions')
        ->where('id', $withdrawal->id)
        ->update(['count_session_id' => $session->id])))
        ->toThrow(QueryException::class, 'count_posting_is_adjustment');
});

it('refuses a second adjustment for the same count session', function () {
    $session = CountSession::factory()->create();
    StockTransaction::factory()->adjustment()->create()->forceFill(['count_session_id' => $session->id])->save();
    $second = StockTransaction::factory()->adjustment()->create();

    expect(inSavepoint(fn () => $second->forceFill(['count_session_id' => $session->id])->save()))
        ->toThrow(QueryException::class, 'stock_transactions_count_session_key');
});

it('refuses a reversed transaction that does not say who reversed it and why', function () {
    $withdrawal = StockTransaction::factory()->create();

    expect(inSavepoint(fn () => DB::table('stock_transactions')->where('id', $withdrawal->id)->update([
        'status' => 'reversed',
        'transaction_no' => 'WD-2026-900001',
        'recorded_at' => now(),
    ])))->toThrow(QueryException::class, 'reversed_is_explained');
});

it('refuses a supervisor authorisation on an entry that is not backdated', function () {
    expect(inSavepoint(fn () => StockTransaction::factory()->create([
        'backdate_authorised_by_user_id' => User::factory()->create()->id,
    ])))->toThrow(QueryException::class, 'backdate_authorisation_is_backdated');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Counting/CountSchemaTest.php
```

Expected: FAIL — `Class "App\Models\CountSession" not found`.

- [ ] **Step 3: Write the enums**

Create `app/Enums/CountStatus.php`:

```php
<?php

namespace App\Enums;

/**
 * A physical count session's lifecycle (PRD §7.5): Open → Counted → Posted, and Open or Counted →
 * Cancelled.
 */
enum CountStatus: string
{
    case Open = 'open';
    case Counted = 'counted';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
```

Create `app/Enums/CountScope.php`:

```php
<?php

namespace App\Enums;

/**
 * Which spare parts a count session covers (PRD FR-SPIMS-013 step 1, design §4.3).
 */
enum CountScope: string
{
    case Full = 'full';
    case Location = 'location';
    case Criticality = 'criticality';
    case Adhoc = 'adhoc';

    public function label(): string
    {
        return match ($this) {
            self::Full => __('All spare parts'),
            self::Location => __('One storage location'),
            self::Criticality => __('One criticality'),
            self::Adhoc => __('Chosen spare parts'),
        };
    }
}
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_09_25_100001_add_corrections_and_counting_tables.php`:

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
        // Physical count sessions (PRD FR-SPIMS-013, design §4.3 and §5.6).
        Schema::create('count_sessions', function (Blueprint $table) {
            $table->id();
            $table->text('session_no')->unique();
            $table->text('scope');
            // The storage location id or the criticality the session covers. Null for full and adhoc.
            $table->text('scope_value')->nullable();
            $table->text('status')->default('open');
            $table->decimal('variance_tolerance_pct', 6, 3)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('opened_by_user_id')->constrained('users');
            $table->timestampTz('opened_at');
            $table->foreignId('marked_counted_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('marked_counted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index('status', 'count_sessions_status_idx');
        });

        DB::unprepared(<<<'SQL'
            alter table count_sessions
              add constraint count_sessions_scope_check
                check (scope in ('full', 'location', 'criticality', 'adhoc')),
              add constraint count_sessions_status_check
                check (status in ('open', 'counted', 'posted', 'cancelled')),
              add constraint count_sessions_tolerance_range
                check (variance_tolerance_pct between 0 and 100),
              -- A location or criticality session names what it covers; a full or adhoc one does not.
              add constraint count_sessions_scope_value_matches check (
                (scope in ('location', 'criticality')) = (scope_value is not null)
              ),
              add constraint count_sessions_marked_counted check (
                status not in ('counted', 'posted')
                or (marked_counted_by_user_id is not null and marked_counted_at is not null)
              ),
              add constraint count_sessions_posted_is_attributed check (
                status <> 'posted' or (posted_by_user_id is not null and posted_at is not null)
              ),
              add constraint count_sessions_cancelled_is_attributed check (
                status <> 'cancelled' or (cancelled_by_user_id is not null and cancelled_at is not null)
              );
            SQL);

        Schema::create('count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_id')->constrained();
            $table->foreignId('part_id')->constrained();
            // The balance when the session opened. For analysis only: it is never the posted
            // quantity (design §5.6.1).
            $table->decimal('system_qty', 14, 3);
            $table->decimal('counted_qty', 14, 3)->nullable();
            // When the shelf was counted. The staleness guard compares recorded movements with it.
            $table->timestampTz('counted_at')->nullable();
            $table->foreignId('counted_by_user_id')->nullable()->constrained('users');
            // Written at posting, from the balance read under the stock row lock.
            $table->decimal('on_hand_at_post', 14, 3)->nullable();
            $table->decimal('posting_delta', 14, 3)->nullable();
            $table->text('reason_code')->nullable();
            $table->text('notes')->nullable();
            // A supervisor's acknowledgement that the count stands although stock moved after it.
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users');
            $table->unique(['count_session_id', 'part_id'], 'count_lines_session_part_unique');
            $table->index('part_id', 'count_lines_part_idx');
        });

        DB::unprepared(<<<'SQL'
            alter table count_lines
              -- How far the system had drifted by the time of the count. FOR ANALYSIS ONLY: the
              -- posted quantity is posting_delta (design §5.6.1).
              add column snapshot_variance numeric(14, 3)
                generated always as (counted_qty - system_qty) stored,
              add constraint count_lines_system_qty_non_negative check (system_qty >= 0),
              add constraint count_lines_counted_qty_non_negative
                check (counted_qty is null or counted_qty >= 0),
              add constraint count_lines_counted_is_attributed check (
                (counted_qty is null) = (counted_at is null)
                and (counted_at is null) = (counted_by_user_id is null)
              ),
              add constraint count_lines_acknowledged_is_attributed check (
                (acknowledged_at is null) = (acknowledged_by_user_id is null)
              ),
              add constraint count_lines_posted_together check (
                (on_hand_at_post is null) = (posting_delta is null)
              ),
              add constraint count_lines_reason_code_check
                check (reason_code in ('miscount', 'damage', 'unrecorded_issue', 'unrecorded_receipt',
                                       'found_stock', 'shrinkage', 'other'));
            SQL);

        Schema::table('stock_transactions', function (Blueprint $table) {
            // The one adjustment a count posting writes (Plan 4 Decisions 1 and 2). It is also what
            // makes that adjustment impossible to reverse (PRD FR-SPIMS-011).
            $table->foreignId('count_session_id')->nullable()->constrained('count_sessions');
            // The supervisor who authorised a backdated entry older than backdating.limit_days
            // (PRD FR-SPIMS-012). Set on every entry beyond the limit, and on no other.
            $table->foreignId('backdate_authorised_by_user_id')->nullable()->constrained('users');
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transactions
              drop constraint adjustment_has_reason,
              -- A count posting's reasons are per part, on count_lines (design §5.6.2).
              add constraint adjustment_has_reason check (
                type <> 'adjustment' or status <> 'completed' or reason_code is not null
                or count_session_id is not null
              ),
              add constraint count_posting_is_adjustment check (
                count_session_id is null or type = 'adjustment'
              ),
              add constraint reversed_is_explained check (
                status <> 'reversed' or (reversed_by_user_id is not null and reversal_reason is not null)
              ),
              add constraint backdate_authorisation_is_backdated check (
                backdate_authorised_by_user_id is null or is_backdated
              );

            -- One adjustment per count session.
            create unique index stock_transactions_count_session_key
              on stock_transactions (count_session_id)
              where count_session_id is not null;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index stock_transactions_count_session_key;

            alter table stock_transactions
              drop constraint backdate_authorisation_is_backdated,
              drop constraint reversed_is_explained,
              drop constraint count_posting_is_adjustment,
              drop constraint adjustment_has_reason,
              add constraint adjustment_has_reason check (
                type <> 'adjustment' or status <> 'completed' or reason_code is not null
              );
            SQL);

        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backdate_authorised_by_user_id');
            $table->dropConstrainedForeignId('count_session_id');
        });

        Schema::dropIfExists('count_lines');
        Schema::dropIfExists('count_sessions');
    }
};
```

- [ ] **Step 5: Write the models**

Create `app/Models/CountSession.php`:

```php
<?php

namespace App\Models;

use App\Enums\CountScope;
use App\Enums\CountStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CountSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A physical count (PRD FR-SPIMS-013). Status and the columns that say who moved it along are
 * lifecycle fields, written only by App\Domain\Counting\CountSessions and StockLedger::postCount(), so
 * they are not fillable.
 *
 * @property int $id
 * @property string $session_no
 * @property CountScope $scope
 * @property string|null $scope_value
 * @property CountStatus $status
 * @property string $variance_tolerance_pct
 * @property string|null $notes
 * @property int $opened_by_user_id
 * @property CarbonImmutable $opened_at
 * @property int|null $marked_counted_by_user_id
 * @property CarbonImmutable|null $marked_counted_at
 * @property int|null $posted_by_user_id
 * @property CarbonImmutable|null $posted_at
 * @property int|null $cancelled_by_user_id
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $openedBy
 * @property-read Collection<int, CountLine> $lines
 * @property-read StockTransaction|null $adjustment
 */
#[Fillable(['session_no', 'scope', 'scope_value', 'variance_tolerance_pct', 'notes', 'opened_by_user_id', 'opened_at'])]
class CountSession extends Model
{
    /** @use HasFactory<CountSessionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CountScope::class,
            'status' => CountStatus::class,
            'variance_tolerance_pct' => 'decimal:3',
            'opened_at' => 'datetime',
            'marked_counted_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CountLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * The adjustment the posting wrote. None when every count matched the balance.
     *
     * @return HasOne<StockTransaction, $this>
     */
    public function adjustment(): HasOne
    {
        return $this->hasOne(StockTransaction::class, 'count_session_id');
    }
}
```

Create `app/Models/CountLine.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReasonCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One spare part in a count session. snapshot_variance is a generated column and is never written.
 *
 * @property int $id
 * @property int $count_session_id
 * @property int $part_id
 * @property string $system_qty
 * @property string|null $counted_qty
 * @property CarbonImmutable|null $counted_at
 * @property int|null $counted_by_user_id
 * @property string|null $snapshot_variance
 * @property string|null $on_hand_at_post
 * @property string|null $posting_delta
 * @property ReasonCode|null $reason_code
 * @property string|null $notes
 * @property CarbonImmutable|null $acknowledged_at
 * @property int|null $acknowledged_by_user_id
 * @property-read CountSession $session
 * @property-read Part $part
 * @property-read User|null $countedBy
 */
#[WithoutTimestamps]
#[Fillable(['part_id', 'system_qty'])]
class CountLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:3',
            'counted_qty' => 'decimal:3',
            'snapshot_variance' => 'decimal:3',
            'on_hand_at_post' => 'decimal:3',
            'posting_delta' => 'decimal:3',
            'reason_code' => ReasonCode::class,
            'counted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CountSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
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
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_user_id');
    }
}
```

- [ ] **Step 6: Write the factory**

Create `database/factories/CountSessionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CountScope;
use App\Models\CountSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An Open, full-scope count session with no lines. Tests that need lines open a session through
 * App\Domain\Counting\CountSessions instead.
 *
 * @extends Factory<CountSession>
 */
class CountSessionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_no' => fake()->unique()->numerify('2026-9###'),
            'scope' => CountScope::Full,
            'scope_value' => null,
            'variance_tolerance_pct' => '0',
            'opened_by_user_id' => User::factory(),
            'opened_at' => CarbonImmutable::now(),
        ];
    }
}
```

- [ ] **Step 7: Wire the new columns into `StockTransaction` and the morph map**

In `app/Models/StockTransaction.php`:

1. Add to the class docblock, after `@property CarbonImmutable|null $identified_at`:

```php
 * @property int|null $count_session_id
 * @property int|null $backdate_authorised_by_user_id
```

   and after `@property-read Collection<int, WithdrawalOverride> $overrides`:

```php
 * @property-read StockTransaction|null $reverses
 * @property-read StockTransaction|null $reversal
 * @property-read User|null $reversedBy
 * @property-read User|null $backdateAuthorisedBy
 * @property-read CountSession|null $countSession
```

2. Add `'backdate_authorised_by_user_id'` to the end of the `#[Fillable([...])]` list. Do **not** add
   `count_session_id`: only `StockLedger::postCount()` writes it.

3. Add these relations after `overrides()`, and `use Illuminate\Database\Eloquent\Relations\HasOne;`
   to the imports:

```php
    /**
     * The completed transaction this one reverses (PRD FR-SPIMS-011).
     *
     * @return BelongsTo<StockTransaction, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'reverses_transaction_id');
    }

    /**
     * The transaction that reversed this one, once it is Reversed.
     *
     * @return HasOne<StockTransaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(StockTransaction::class, 'reverses_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    /**
     * The supervisor who authorised a backdated entry older than the backdating limit.
     *
     * @return BelongsTo<User, $this>
     */
    public function backdateAuthorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backdate_authorised_by_user_id');
    }

    /**
     * The count session whose posting wrote this adjustment.
     *
     * @return BelongsTo<CountSession, $this>
     */
    public function countSession(): BelongsTo
    {
        return $this->belongsTo(CountSession::class);
    }
```

In `app/Providers/AppServiceProvider.php`, add to the morph map after `'withdrawal_override'`, with
`use App\Models\CountLine;` and `use App\Models\CountSession;` among the imports:

```php
            'count_session' => CountSession::class,
            'count_line' => CountLine::class,
```

- [ ] **Step 8: Run the test to verify it passes**

```bash
php artisan migrate
php artisan test tests/Feature/Counting/CountSchemaTest.php tests/Feature/SchemaConventionsTest.php tests/Feature/Ledger
```

Expected: PASS, 14 new tests (the scope test is a dataset of two). The ledger tests stay green: the
relaxed `adjustment_has_reason` still refuses every adjustment that is not a count posting.

- [ ] **Step 9: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_25_100001_add_corrections_and_counting_tables.php app/Enums/CountStatus.php app/Enums/CountScope.php app/Models/CountSession.php app/Models/CountLine.php database/factories/CountSessionFactory.php app/Models/StockTransaction.php app/Providers/AppServiceProvider.php tests/Feature/Counting/CountSchemaTest.php
git commit -m "Add the count session tables and the columns corrections need"
```

---

## Task 2: `AdjustmentService` — a supervisor corrects one part's balance

**Files:**
- Create: `app/Domain/Adjustments/AdjustmentService.php`,
  `app/Domain/Adjustments/Exceptions/AdjustmentRefused.php`
- Test: `tests/Feature/Adjustments/AdjustmentServiceTest.php`

**Interfaces:**
- Consumes: `StockLedger::complete(StockTransaction, User): StockTransaction` (Plan 1), which throws
  `InsufficientStock`, `InstallNotConfigured` and `StockBusy`; `Permission::AdjustmentsRecord`
  (Supervisor only); `App\Support\Text::nullIfBlank(?string): ?string`.
- Produces:
  - `AdjustmentService::record(User $supervisor, Part $part, int $direction, string $quantity,
    ?ReasonCode $reason, ?string $remarks): StockTransaction` — the completed adjustment. `$direction`
    is `1` (increase) or `-1` (decrease); anything else is an `InvalidArgumentException`.
  - `App\Domain\Adjustments\Exceptions\AdjustmentRefused` (a `RuntimeException`) with
    `notPermitted(User)`, `quantityNotPositive(string)`, `reasonRequired()`, `remarksRequired()`.

FR-SPIMS-010 in one method (Decision 5). The rules it enforces:

- The quantity is a number greater than zero, with at most three decimals. The direction says which
  way.
- The reason is required, and remarks are required when the reason is Other.
- Inactive parts can be adjusted, so stock left on a retired part can be written off.
- No balance goes below zero. `StockLedger` already refuses that under lock and names the part.

The adjustment is created and posted inside one `DB::transaction()`. `complete()`'s own transaction
nests inside it as a savepoint, so when the ledger refuses a decrease, the outer rollback takes the
draft row with it. A refused adjustment leaves nothing behind.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Adjustments/AdjustmentServiceTest.php`:

```php
<?php

use App\Domain\Adjustments\AdjustmentService;
use App\Domain\Adjustments\Exceptions\AdjustmentRefused;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Enums\MovementType;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\StockMovement;
use App\Models\StockTransaction;

beforeEach(function () {
    configureInstall();
    $this->supervisor = userWithRoles(Role::Supervisor);
});

it('increases a balance with an adjustment movement that records the resulting balance', function () {
    $part = partWithStock('10');

    $adjustment = app(AdjustmentService::class)
        ->record($this->supervisor, $part, 1, '2.5', ReasonCode::FoundStock, 'Found behind the rack');

    $movement = StockMovement::query()->where('stock_transaction_id', $adjustment->id)->sole();

    expect($adjustment->status)->toBe(TransactionStatus::Completed)
        ->and($adjustment->type)->toBe(TransactionType::Adjustment)
        ->and($adjustment->transaction_no)->toStartWith('AJ-')
        ->and($adjustment->custodian_user_id)->toBe($this->supervisor->id)
        ->and($adjustment->reason_code)->toBe(ReasonCode::FoundStock)
        ->and($adjustment->remarks)->toBe('Found behind the rack')
        ->and($movement->movement_type)->toBe(MovementType::Adjustment)
        ->and($movement->quantity)->toBe('2.500')
        ->and($movement->balance_after)->toBe('12.500')
        ->and(onHand($part))->toBe('12.500');
});

it('decreases a balance', function () {
    $part = partWithStock('10');

    app(AdjustmentService::class)->record($this->supervisor, $part, -1, '4', ReasonCode::Damage, null);

    expect(onHand($part))->toBe('6.000')
        ->and(StockMovement::query()->where('movement_type', MovementType::Adjustment->value)->sole()->quantity)
        ->toBe('-4.000');
});

it('refuses a decrease below zero, names the part, and leaves nothing behind', function () {
    $part = partWithStock('3', ['part_number' => 'BRG-6204']);

    expect(fn () => app(AdjustmentService::class)->record($this->supervisor, $part, -1, '5', ReasonCode::Shrinkage, null))
        ->toThrow(InsufficientStock::class, 'BRG-6204');

    expect(StockTransaction::query()->where('type', TransactionType::Adjustment)->exists())->toBeFalse()
        ->and(onHand($part))->toBe('3.000');
});

it('refuses a quantity that is not a number greater than zero', function (string $quantity) {
    $part = partWithStock('5');

    expect(fn () => app(AdjustmentService::class)->record($this->supervisor, $part, 1, $quantity, ReasonCode::Miscount, null))
        ->toThrow(AdjustmentRefused::class, 'is not a number greater than zero');
})->with(['0', '-2', 'abc', '', '1.2345']);

it('refuses an adjustment without a reason', function () {
    $part = partWithStock('5');

    expect(fn () => app(AdjustmentService::class)->record($this->supervisor, $part, 1, '1', null, 'Counted again'))
        ->toThrow(AdjustmentRefused::class, 'Choose the reason');
});

it('requires remarks when the reason is Other', function () {
    $part = partWithStock('5');
    $service = app(AdjustmentService::class);

    expect(fn () => $service->record($this->supervisor, $part, 1, '1', ReasonCode::Other, '   '))
        ->toThrow(AdjustmentRefused::class, 'Remarks are required');

    expect($service->record($this->supervisor, $part, 1, '1', ReasonCode::Other, 'Label misprinted')->remarks)
        ->toBe('Label misprinted');
});

it('adjusts an Inactive spare part', function () {
    $part = partWithStock('4', ['is_active' => false]);

    app(AdjustmentService::class)->record($this->supervisor, $part, -1, '4', ReasonCode::Damage, null);

    expect(onHand($part))->toBe('0.000');
});

it('lets only an Inventory Supervisor record an adjustment', function (Role $role, bool $allowed) {
    $part = partWithStock('5');
    $record = fn () => app(AdjustmentService::class)->record(userWithRoles($role), $part, 1, '1', ReasonCode::Miscount, null);

    if ($allowed) {
        expect($record()->status)->toBe(TransactionStatus::Completed);
    } else {
        expect($record)->toThrow(AdjustmentRefused::class, 'not allowed to record stock adjustments');
    }
})->with(roleMatrix('-S--'));

it('audits the adjustment', function () {
    $adjustment = app(AdjustmentService::class)->record($this->supervisor, partWithStock('5'), 1, '1', ReasonCode::Miscount, null);

    expect(AuditLog::query()
        ->where('auditable_type', 'stock_transaction')
        ->where('auditable_id', (string) $adjustment->id)
        ->where('event', 'completed')
        ->where('user_id', $this->supervisor->id)
        ->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Adjustments/AdjustmentServiceTest.php
```

Expected: FAIL — `Class "App\Domain\Adjustments\AdjustmentService" not found`.

- [ ] **Step 3: Write the refusal**

Create `app/Domain/Adjustments/Exceptions/AdjustmentRefused.php`:

```php
<?php

namespace App\Domain\Adjustments\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-010 stopped a stock adjustment. A decrease below zero is refused by the
 * ledger instead, as InsufficientStock, because only the locked balance can decide it.
 */
final class AdjustmentRefused extends RuntimeException
{
    public static function notPermitted(User $user): self
    {
        return new self(__(':name is not allowed to record stock adjustments. Ask an Inventory Supervisor.', [
            'name' => $user->name,
        ]));
    }

    public static function quantityNotPositive(string $quantity): self
    {
        return new self(__('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($quantity)]));
    }

    public static function reasonRequired(): self
    {
        return new self(__('Choose the reason for this adjustment.'));
    }

    public static function remarksRequired(): self
    {
        return new self(__('Remarks are required when the reason is Other.'));
    }
}
```

- [ ] **Step 4: Write the service**

Create `app/Domain/Adjustments/AdjustmentService.php`:

```php
<?php

namespace App\Domain\Adjustments;

use App\Domain\Adjustments\Exceptions\AdjustmentRefused;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\StockLedger;
use App\Enums\Permission;
use App\Enums\ReasonCode;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use App\Support\Text;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A supervisor's correction of one spare part's balance when stock changed without a withdrawal or a
 * delivery (PRD FR-SPIMS-010). Nothing here changes a balance: StockLedger::complete() does that.
 */
final readonly class AdjustmentService
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * Create the adjustment and post it, in one database transaction. StockLedger's own transaction
     * becomes a savepoint inside this one, so a refusal from the ledger rolls the draft back too and
     * a refused adjustment leaves no row behind.
     *
     * @param  int  $direction  1 increases the balance, -1 decreases it
     *
     * @throws AdjustmentRefused
     * @throws InsufficientStock when a decrease would take the balance below zero
     * @throws InstallNotConfigured
     * @throws StockBusy
     */
    public function record(
        User $supervisor,
        Part $part,
        int $direction,
        string $quantity,
        ?ReasonCode $reason,
        ?string $remarks,
    ): StockTransaction {
        if (! in_array($direction, [-1, 1], true)) {
            throw new InvalidArgumentException("An adjustment's direction is 1 or -1, not {$direction}.");
        }

        if (! $supervisor->can(Permission::AdjustmentsRecord)) {
            throw AdjustmentRefused::notPermitted($supervisor);
        }

        $amount = $this->quantity($quantity);

        if ($reason === null) {
            throw AdjustmentRefused::reasonRequired();
        }

        $remarks = Text::nullIfBlank($remarks);

        if ($reason === ReasonCode::Other && $remarks === null) {
            throw AdjustmentRefused::remarksRequired();
        }

        return DB::transaction(function () use ($supervisor, $part, $direction, $amount, $reason, $remarks): StockTransaction {
            $adjustment = StockTransaction::query()->create([
                'type' => TransactionType::Adjustment,
                // The supervisor is the attributed actor: an adjustment has no personnel.
                'custodian_user_id' => $supervisor->id,
                'reason_code' => $reason,
                'remarks' => $remarks,
                // StockLedger stamps the commit time over it, because an adjustment is not backdated.
                'occurred_at' => CarbonImmutable::now(),
            ]);

            $adjustment->lines()->create([
                'line_no' => 1,
                'part_id' => $part->id,
                'quantity' => (string) $amount,
                'direction' => $direction,
            ]);

            return $this->ledger->complete($adjustment, $supervisor);
        });
    }

    /**
     * @throws AdjustmentRefused
     */
    private function quantity(string $quantity): BigDecimal
    {
        try {
            $amount = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw AdjustmentRefused::quantityNotPositive($quantity);
        }

        if (! $amount->isPositive()) {
            throw AdjustmentRefused::quantityNotPositive($quantity);
        }

        return $amount;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Adjustments/AdjustmentServiceTest.php
```

Expected: PASS, 16 tests (the quantity dataset is five, the role matrix four).

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Adjustments/AdjustmentService.php app/Domain/Adjustments/Exceptions/AdjustmentRefused.php tests/Feature/Adjustments/AdjustmentServiceTest.php
git commit -m "Record a supervisor's stock adjustment for one spare part"
```

---

## Task 3: `StockLedger::reverse()` — the reversal posting

**Files:**
- Create: `app/Domain/Ledger/Exceptions/ReversalRefused.php`
- Modify: `app/Domain/Ledger/StockLedger.php`
- Test: `tests/Feature/Ledger/ReversalTest.php`

**Interfaces:**
- Consumes: `StockLedger`'s private `applyLockTimeout()`, `lockBalances()`, `guardDirections()`,
  `guardSufficientStock()`, `writeMovements()` and `writeBalances()`; `TransactionNumberer::next()`;
  `WithdrawalOverride.adjustment_transaction_id` (Plan 3); `stock_transactions.count_session_id` and
  the `reversed_is_explained` CHECK (Task 1).
- Produces:
  - `StockLedger::reverse(StockTransaction $original, string $reason, User $supervisor): StockTransaction`
    — the completed reversing transaction, refreshed from the database. It throws
    `InstallNotConfigured`, `ReversalRefused`, `InsufficientStock` and `StockBusy`. The caller
    authorizes `Permission::TransactionsReverse`, as callers of `complete()` authorize theirs.
  - `StockLedger::guardReversible(StockTransaction $transaction): void` — throws `ReversalRefused`
    when the transaction cannot be reversed. Task 4's screen calls it to decide whether to offer
    Reverse.
  - `App\Domain\Ledger\Exceptions\ReversalRefused` (a `DomainException`) with `reasonRequired()`,
    `alreadyReversed()`, `notCompleted()`, `isAReversal()`, `openingBalance()`, `countAdjustment()`
    and `overrideAdjustment()`, each but the first taking the `StockTransaction`.
  - `guardDirections()` accepts the opposite of the type's direction on a transaction whose
    `reverses_transaction_id` is set. `postWithOverrides()` calls it too, on drafts that never have
    that column set, so its behaviour is unchanged.

Design §5.5 and Decision 3. The rules come from FR-SPIMS-011:

- **Equal and opposite movements of the same movement type.** A reversed withdrawal writes `+qty`
  movements of type `withdrawal`, so the withdrawals-only consumption sum nets to zero with no special
  case in any report.
- **The original's `occurred_at`** on every reversing movement, so the correction lands in the period
  the mistake did. `balance_after` is still stamped in recording order (design §4.5).
- **Refused, naming the part, when a balance would go negative** — reversing a delivery whose parts
  were issued since. The ledger's existing `InsufficientStock` says exactly that.
- **Once only, and never a reversal, an opening balance, an override adjustment or a count
  adjustment.** `guardReversible()` checks all of them, first on the screen's copy and then again on
  the locked row. The unique index `stock_transactions_reverses_key` is the backstop.
- **Reversing a withdrawal that carried an override reverses the withdrawal only.** The override
  adjustment is a separate transaction, so nothing special is needed. The test pins it.

The lock order is the one every posting uses: the original's row first, so a second supervisor
reversing the same transaction waits and then finds it Reversed; then the stock rows, in part order,
through `lockBalances()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ledger/ReversalTest.php`:

```php
<?php

use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\ReversalRefused;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\IdentificationMethod;
use App\Enums\MovementType;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\CountSession;
use App\Models\Equipment;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->supervisor = User::factory()->create();
});

it('reverses a withdrawal with equal and opposite movements of the same type', function () {
    $part = partWithStock('10');
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[$part, '4']])->create(), $this->supervisor);
    $this->travel(5)->minutes();

    $reversing = $this->ledger->reverse($withdrawal, 'Issued to the wrong technician', $this->supervisor);

    $movement = StockMovement::query()->where('stock_transaction_id', $reversing->id)->sole();
    $withdrawal->refresh();

    expect(onHand($part))->toBe('10.000')
        ->and($reversing->type)->toBe(TransactionType::Withdrawal)
        ->and($reversing->status)->toBe(TransactionStatus::Completed)
        ->and($reversing->transaction_no)->toStartWith('WD-')
        ->and($reversing->reverses_transaction_id)->toBe($withdrawal->id)
        ->and($reversing->custodian_user_id)->toBe($this->supervisor->id)
        ->and($reversing->personnel_id)->toBe($withdrawal->personnel_id)
        ->and($movement->movement_type)->toBe(MovementType::Withdrawal)
        ->and($movement->quantity)->toBe('4.000')
        ->and($movement->balance_after)->toBe('10.000')
        ->and($movement->occurred_at->equalTo($withdrawal->occurred_at))->toBeTrue()
        ->and($movement->recorded_at->greaterThan($withdrawal->recorded_at))->toBeTrue()
        ->and($withdrawal->status)->toBe(TransactionStatus::Reversed)
        ->and($withdrawal->reversed_by_user_id)->toBe($this->supervisor->id)
        ->and($withdrawal->reversal_reason)->toBe('Issued to the wrong technician')
        ->and($withdrawal->reversal?->is($reversing))->toBeTrue()
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it("leaves the consumption of the withdrawal's own month at zero", function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-30 10:00', 'Asia/Manila'));
    $part = partWithStock('20');
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[$part, '15']])->create(), $this->supervisor);

    $this->travelTo(CarbonImmutable::parse('2026-02-02 09:00', 'Asia/Manila'));
    $this->ledger->reverse($withdrawal, 'The parts never left the storeroom', $this->supervisor);

    $withdrawals = fn (string $from, string $to) => StockMovement::query()
        ->where('part_id', $part->id)
        ->where('movement_type', MovementType::Withdrawal->value)
        ->where('occurred_at', '>=', CarbonImmutable::parse($from, 'Asia/Manila'))
        ->where('occurred_at', '<', CarbonImmutable::parse($to, 'Asia/Manila'));

    expect($withdrawals('2026-01-01', '2026-02-01')->count())->toBe(2)
        ->and(BigDecimal::of((string) $withdrawals('2026-01-01', '2026-02-01')->sum('quantity'))->isZero())->toBeTrue()
        ->and($withdrawals('2026-02-01', '2026-03-01')->count())->toBe(0);
});

it('refuses to reverse a delivery whose parts have since been issued, naming the part', function () {
    $part = partWithStock('0', ['part_number' => 'BLT-A42']);
    $delivery = $this->ledger->complete(StockTransaction::factory()->replenishment()->withLines([[$part, '10']])->create(), $this->supervisor);
    $this->ledger->complete(StockTransaction::factory()->withLines([[$part, '7']])->create(), $this->supervisor);

    expect(fn () => $this->ledger->reverse($delivery, 'Wrong delivery', $this->supervisor))
        ->toThrow(InsufficientStock::class, 'BLT-A42');

    expect($delivery->refresh()->status)->toBe(TransactionStatus::Completed)
        ->and(StockTransaction::query()->whereNotNull('reverses_transaction_id')->exists())->toBeFalse()
        ->and(onHand($part))->toBe('3.000');
});

it('refuses to reverse a transaction twice', function () {
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[partWithStock('5'), '1']])->create(), $this->supervisor);
    $this->ledger->reverse($withdrawal, 'Wrong part', $this->supervisor);

    expect(fn () => $this->ledger->reverse($withdrawal->refresh(), 'Again', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'already been reversed');
});

it('refuses to reverse a reversal', function () {
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[partWithStock('5'), '1']])->create(), $this->supervisor);
    $reversing = $this->ledger->reverse($withdrawal, 'Wrong part', $this->supervisor);

    expect(fn () => $this->ledger->reverse($reversing, 'Undo the undo', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'cannot itself be reversed');
});

it('refuses to reverse an opening balance', function () {
    partWithStock('5');
    $opening = StockTransaction::query()->where('type', TransactionType::Opening)->sole();

    expect(fn () => $this->ledger->reverse($opening, 'Wrong opening', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'opening balance');
});

it('refuses to reverse a draft or a cancelled transaction', function (TransactionStatus $status) {
    $transaction = StockTransaction::factory()->withLines([[partWithStock('5'), '1']])->create();
    $transaction->forceFill(['status' => $status])->save();

    expect(fn () => $this->ledger->reverse($transaction, 'Because', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'Only a completed transaction can be reversed');
})->with([TransactionStatus::Draft, TransactionStatus::Cancelled]);

it('refuses to reverse an adjustment a count posting wrote', function () {
    $adjustment = StockTransaction::factory()->adjustment()->create();
    $adjustment->forceFill([
        'status' => TransactionStatus::Completed,
        'transaction_no' => 'AJ-2026-900010',
        'recorded_at' => now(),
        'count_session_id' => CountSession::factory()->create()->id,
    ])->save();

    expect(fn () => $this->ledger->reverse($adjustment, 'Undo the count', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'stock count');
});

it('requires a reason', function () {
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[partWithStock('5'), '1']])->create(), $this->supervisor);

    expect(fn () => $this->ledger->reverse($withdrawal, '   ', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'Enter the reason');
});

it('reverses only the withdrawal when it carried an override, and refuses to reverse the override', function () {
    $part = partWithStock('2');
    $withdrawal = StockTransaction::factory()->withLines([[$part, '5']])->create();
    // The supervisor counted after the opening balance was recorded (Plan 3's staleness guard).
    $this->travel(1)->seconds();
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $withdrawal->id,
        'part_id' => $part->id,
        'verified_qty' => '5',
        'recorded_on_hand' => '2',
        'authorised_by_user_id' => $this->supervisor->id,
    ]);
    $this->ledger->completeWithOverrides($withdrawal, $this->supervisor);
    $adjustment = StockTransaction::query()->where('type', TransactionType::Adjustment)->sole();

    $this->ledger->reverse($withdrawal->refresh(), 'Returned unused', $this->supervisor);

    expect(onHand($part))->toBe('5.000')
        ->and($adjustment->refresh()->status)->toBe(TransactionStatus::Completed);

    expect(fn () => $this->ledger->reverse($adjustment, 'Undo the override', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'supervisor override');
});

it('reverses a backdated paper-form withdrawal without copying its form number', function () {
    $part = partWithStock('10');
    $occurred = CarbonImmutable::now()->subDays(3)->startOfSecond();
    $withdrawal = $this->ledger->complete(
        StockTransaction::factory()->backdated($occurred)->withLines([[$part, '4']])->create(),
        $this->supervisor,
    );

    $reversing = $this->ledger->reverse($withdrawal, 'Form entered twice', $this->supervisor);

    expect($reversing->paper_form_no)->toBeNull()
        ->and($reversing->is_backdated)->toBeFalse()
        ->and($reversing->identification_method)->toBeNull()
        ->and(StockMovement::query()->where('stock_transaction_id', $reversing->id)->sole()->occurred_at->equalTo($occurred))->toBeTrue()
        ->and(onHand($part))->toBe('10.000');
});

it('reverses a manually identified withdrawal', function () {
    $part = partWithStock('10');
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[$part, '4']])->create([
        'identification_method' => IdentificationMethod::SupervisorManual,
        'identification_authorised_by_user_id' => $this->supervisor->id,
        'identification_reason' => 'Badge left at home',
    ]), $this->supervisor);

    $this->ledger->reverse($withdrawal, 'Wrong part', $this->supervisor);

    expect(onHand($part))->toBe('10.000');
});

it('reverses a line issued to another machine against that machine', function () {
    $bearing = partWithStock('10');
    $belt = partWithStock('10');
    $press = Equipment::factory()->create();
    $withdrawal = StockTransaction::factory()->withLines([[$bearing, '2'], [$belt, '3']])->create();
    $withdrawal->lines()->where('part_id', $belt->id)->update(['equipment_id' => $press->id]);
    $this->ledger->complete($withdrawal, $this->supervisor);

    $this->ledger->reverse($withdrawal->refresh(), 'Booked to the wrong machines', $this->supervisor);

    $byEquipment = StockMovement::query()
        ->where('movement_type', MovementType::Withdrawal->value)
        ->selectRaw('equipment_id, sum(quantity) as total')
        ->groupBy('equipment_id')
        ->pluck('total', 'equipment_id');

    expect($byEquipment)->toHaveCount(2)
        ->and($byEquipment->every(fn ($total): bool => BigDecimal::of((string) $total)->isZero()))->toBeTrue()
        ->and(StockMovement::query()->where('equipment_id', $press->id)->count())->toBe(2);
});

it('reverses an adjustment line by line, each the other way, keeping its reason', function () {
    $up = partWithStock('5');
    $down = partWithStock('5');
    $adjustment = $this->ledger->complete(
        StockTransaction::factory()->adjustment(ReasonCode::Miscount)->withLines([[$up, '2', 1], [$down, '1', -1]])->create(),
        $this->supervisor,
    );

    $reversing = $this->ledger->reverse($adjustment, 'Counted the wrong bin', $this->supervisor);

    expect(onHand($up))->toBe('5.000')
        ->and(onHand($down))->toBe('5.000')
        ->and($reversing->reason_code)->toBe(ReasonCode::Miscount)
        ->and($reversing->lines()->orderBy('line_no')->pluck('direction')->all())->toBe([-1, 1]);
});

it('audits the reversal on the original, with its reason', function () {
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[partWithStock('5'), '1']])->create(), $this->supervisor);

    $reversing = $this->ledger->reverse($withdrawal, 'Wrong part', $this->supervisor);

    $entry = AuditLog::query()
        ->where('auditable_type', 'stock_transaction')
        ->where('auditable_id', (string) $withdrawal->id)
        ->where('event', 'reversed')
        ->sole();

    expect($entry->user_id)->toBe($this->supervisor->id)
        ->and($entry->old_values)->toBe(['status' => 'completed'])
        ->and($entry->new_values)->toBe([
            'reason' => 'Wrong part',
            'status' => 'reversed',
            'reversed_by_transaction_no' => $reversing->transaction_no,
        ]);
});

it('announces the reversing transaction once it commits', function () {
    Event::fake([StockTransactionCompleted::class]);
    $withdrawal = $this->ledger->complete(StockTransaction::factory()->withLines([[partWithStock('5'), '1']])->create(), $this->supervisor);

    $reversing = $this->ledger->reverse($withdrawal, 'Wrong part', $this->supervisor);

    Event::assertDispatched(
        StockTransactionCompleted::class,
        fn (StockTransactionCompleted $event): bool => $event->transaction->is($reversing),
    );
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Ledger/ReversalTest.php
```

Expected: FAIL — `Class "App\Domain\Ledger\Exceptions\ReversalRefused" not found`.

- [ ] **Step 3: Write the refusal**

Create `app/Domain/Ledger/Exceptions/ReversalRefused.php`:

```php
<?php

namespace App\Domain\Ledger\Exceptions;

use App\Models\StockTransaction;
use DomainException;

/**
 * A rule of PRD FR-SPIMS-011 stops this reversal. A balance that would go negative is refused as
 * InsufficientStock instead, because only the locked balance can decide it.
 */
final class ReversalRefused extends DomainException
{
    public static function reasonRequired(): self
    {
        return new self(__('Enter the reason for the reversal.'));
    }

    public static function alreadyReversed(StockTransaction $transaction): self
    {
        return new self(__(':number has already been reversed.', ['number' => $transaction->transaction_no]));
    }

    public static function notCompleted(StockTransaction $transaction): self
    {
        return new self(__('Only a completed transaction can be reversed. This one is :status.', [
            'status' => $transaction->status->value,
        ]));
    }

    public static function isAReversal(StockTransaction $transaction): self
    {
        return new self(__(':number reverses another transaction and cannot itself be reversed.', [
            'number' => $transaction->transaction_no,
        ]));
    }

    public static function openingBalance(StockTransaction $transaction): self
    {
        return new self(__(':number is an opening balance and cannot be reversed. Correct the balance with a stock adjustment or a count.', [
            'number' => $transaction->transaction_no,
        ]));
    }

    public static function countAdjustment(StockTransaction $transaction): self
    {
        return new self(__(':number was posted by a stock count and cannot be reversed. Correct the balance with a stock adjustment or a new count.', [
            'number' => $transaction->transaction_no,
        ]));
    }

    public static function overrideAdjustment(StockTransaction $transaction): self
    {
        return new self(__(':number was recorded by a supervisor override and cannot be reversed. Correct the balance with a stock adjustment or a count.', [
            'number' => $transaction->transaction_no,
        ]));
    }
}
```

- [ ] **Step 4: Accept a reversal's direction in `guardDirections()`**

In `app/Domain/Ledger/StockLedger.php`, replace `guardDirections()` with:

```php
    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     */
    private function guardDirections(StockTransaction $transaction, Collection $lines): void
    {
        $expected = $transaction->type->direction();

        // A reversing transaction runs every line the other way (PRD FR-SPIMS-011).
        if ($expected !== null && $transaction->reverses_transaction_id !== null) {
            $expected = -$expected;
        }

        foreach ($lines as $line) {
            if ($expected !== null && $line->direction !== $expected) {
                throw new LogicException("Line {$line->line_no} of a {$transaction->type->value} must have direction {$expected}.");
            }
        }
    }
```

- [ ] **Step 5: Add `reverse()` and `guardReversible()`**

In `app/Domain/Ledger/StockLedger.php`, add `use App\Domain\Ledger\Exceptions\ReversalRefused;` to
the imports, and add these three methods after `postWithOverrides()`:

```php
    /**
     * Reverse a completed transaction (PRD FR-SPIMS-011, design §5.5): a new transaction of the same
     * type with every line in the opposite direction, posted under the same locks as any other. Its
     * movements keep the original's movement type and carry its occurred_at, so a reversed withdrawal
     * nets to zero in the period it happened. The original becomes Reversed and records who reversed
     * it and why. The caller authorizes Permission::TransactionsReverse.
     *
     * @throws InstallNotConfigured
     * @throws ReversalRefused
     * @throws InsufficientStock when reversing would take a balance below zero
     * @throws StockBusy
     */
    public function reverse(StockTransaction $original, string $reason, User $supervisor): StockTransaction
    {
        if (! $this->settings->isReadyForStockTransactions()) {
            throw new InstallNotConfigured;
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ReversalRefused::reasonRequired();
        }

        try {
            $reversing = DB::transaction(fn (): StockTransaction => $this->postReversal($original->id, $reason, $supervisor), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new StockBusy($exception);
            }

            throw $exception;
        }

        event(new StockTransactionCompleted($reversing));

        return $reversing;
    }

    /**
     * Refuse, with the reason, a transaction that cannot be reversed. The screen asks before it
     * offers Reverse; postReversal() asks again on the locked row.
     *
     * @throws ReversalRefused
     */
    public function guardReversible(StockTransaction $transaction): void
    {
        if ($transaction->status === TransactionStatus::Reversed) {
            throw ReversalRefused::alreadyReversed($transaction);
        }

        if ($transaction->status !== TransactionStatus::Completed) {
            throw ReversalRefused::notCompleted($transaction);
        }

        if ($transaction->reverses_transaction_id !== null) {
            throw ReversalRefused::isAReversal($transaction);
        }

        if ($transaction->type === TransactionType::Opening) {
            throw ReversalRefused::openingBalance($transaction);
        }

        if ($transaction->count_session_id !== null) {
            throw ReversalRefused::countAdjustment($transaction);
        }

        // Decision 2: Plan 3 already marks every override adjustment through this column.
        if (WithdrawalOverride::query()->where('adjustment_transaction_id', $transaction->id)->exists()) {
            throw ReversalRefused::overrideAdjustment($transaction);
        }
    }

    private function postReversal(int $originalId, string $reason, User $supervisor): StockTransaction
    {
        $this->applyLockTimeout();

        // The original's row first, as every posting locks its own transaction before stock (design
        // §5.2). A second supervisor reversing the same transaction waits here, then finds it Reversed.
        $original = StockTransaction::query()->whereKey($originalId)->lockForUpdate()->firstOrFail();

        $this->guardReversible($original);

        // Decision 3: these columns and no other. paper_form_no is unique, is_backdated demands a
        // paper form, and the identification columns describe a scan that never happened here. The
        // attribution CHECK exempts a reversing withdrawal from all of them.
        $reversing = StockTransaction::query()->create([
            'type' => $original->type,
            'custodian_user_id' => $supervisor->id,
            'personnel_id' => $original->personnel_id,
            'equipment_id' => $original->equipment_id,
            'purpose_id' => $original->purpose_id,
            'supplier' => $original->supplier,
            'reference_no' => $original->reference_no,
            'reason_code' => $original->reason_code,
            'occurred_at' => $original->occurred_at,
        ]);

        $reversing->forceFill(['reverses_transaction_id' => $original->id])->save();

        foreach ($original->lines()->orderBy('line_no')->get() as $line) {
            $reversing->lines()->create([
                'line_no' => $line->line_no,
                'part_id' => $line->part_id,
                'quantity' => $line->quantity,
                'direction' => -$line->direction,
                // A line issued to another machine or purpose reverses against that one, so
                // consumption by equipment and by purpose nets to zero too.
                'equipment_id' => $line->equipment_id,
                'purpose_id' => $line->purpose_id,
                'unit_cost' => $line->unit_cost,
            ]);
        }

        $lines = $reversing->lines()->with('part')->orderBy('line_no')->get();

        $balances = $this->lockBalances($lines);
        $this->guardDirections($reversing, $lines);

        $resulting = [];

        foreach ($lines as $line) {
            $resulting[$line->part_id] = $balances[$line->part_id]->plus($line->signedQuantity());
        }

        // FR-SPIMS-011: reversing a delivery whose parts have been issued since is refused, naming the
        // part. The exception rolls back the reversing transaction created above.
        $this->guardSufficientStock($lines, $balances, $resulting);

        $recordedAt = CarbonImmutable::now();

        // Design §5.5: the original's occurred_at, with balance_after in recording order (§4.5).
        $this->writeMovements($reversing, $lines, $resulting, $original->occurred_at, $recordedAt);
        $this->writeBalances($resulting, $recordedAt);

        $reversing->forceFill([
            'status' => TransactionStatus::Completed,
            'transaction_no' => $this->numberer->next($reversing->type, $recordedAt),
            'recorded_at' => $recordedAt,
        ])->save();

        $original->forceFill([
            'status' => TransactionStatus::Reversed,
            'reversed_by_user_id' => $supervisor->id,
            'reversal_reason' => $reason,
        ])->save();

        $this->audit->record(
            'reversed',
            $original,
            ['status' => TransactionStatus::Completed->value],
            [
                'reason' => $reason,
                'status' => TransactionStatus::Reversed->value,
                'reversed_by_transaction_no' => $reversing->transaction_no,
            ],
            $supervisor,
        );

        $this->audit->record(
            'completed',
            $reversing,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Completed->value, 'transaction_no' => $reversing->transaction_no],
            $supervisor,
        );

        // A model made by create() holds only the attributes it was created with (Plan 2b lesson):
        // is_backdated, paper_form_no and the rest would read null until refreshed.
        return $reversing->refresh();
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Ledger
```

Expected: PASS, 17 new tests (the draft-or-cancelled test is a dataset of two), and every other
ledger test still green — in particular `OverridePostingTest`, which goes through the changed
`guardDirections()`.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Ledger/Exceptions/ReversalRefused.php app/Domain/Ledger/StockLedger.php tests/Feature/Ledger/ReversalTest.php
git commit -m "Reverse a completed transaction with equal and opposite movements"
```

---

## Task 4: Transactions — the list, the record, and Reverse

**Files:**
- Create: `resources/views/components/transaction-record.blade.php`,
  `app/Livewire/Transactions/Index.php`, `resources/views/livewire/transactions.blade.php`,
  `app/Livewire/Transactions/Show.php`, `resources/views/livewire/transactions/show.blade.php`
- Modify: `app/Models/StockTransaction.php`, `app/Livewire/Station/Receipt.php`,
  `resources/views/livewire/station/receipt.blade.php`, `app/Support/Navigation.php`,
  `routes/spims.php`, `tests/Feature/Shell/AppShellTest.php`,
  `tests/Feature/Access/ScreenAccessMatrixTest.php`, `tests/Feature/Access/ActionReauthorizationTest.php`
- Test: `tests/Feature/Transactions/TransactionScreensTest.php`

**Interfaces:**
- Consumes: `StockLedger::reverse()` and `StockLedger::guardReversible()` (Task 3); the relations
  `reverses`, `reversal`, `reversedBy`, `backdateAuthorisedBy`, `countSession` (Task 1) and
  `overrides.adjustment` (Plan 3); `Permission::MovementsView` (every role) and
  `Permission::TransactionsReverse` (Supervisor only).
- Produces:
  - `StockTransaction::RECORD_RELATIONS` — the relations the record shows, for eager loading.
  - `<x-transaction-record :transaction="$transaction" />` — the transaction record, rendered by the
    station receipt and by the record screen.
  - Routes `transactions.index` (`/transactions`, query `?q=`) and `transactions.show`
    (`/transactions/{transaction}`). Tasks 5 and 7 link to `transactions.show`.
  - The Livewire action `confirmReversal()` and property `reversalReason` on
    `App\Livewire\Transactions\Show`.

FR-SPIMS-011 begins "the supervisor opens the Completed transaction", and until now no screen lists
completed transactions (Decision 18). **Transactions** lists the latest fifty, completed or reversed,
newest first, and finds one by transaction number or paper form number. Every role holding
`MovementsView` may look. The **record** is the station receipt's layout, moved into a Blade
component so the two can never disagree (Plan 3's note for Plan 6). It gains what this plan adds:

- both dates of a backdated entry, its paper form number, the reason and the supervisor who
  authorised it
- a Reversed marker naming who reversed it, why, and the reversing transaction
- a Reverses link on the reversing transaction
- the override adjustments a withdrawal carried
- the count that posted an adjustment

A supervisor sees **Reverse transaction** when `guardReversible()` allows it, and otherwise the reason
it does not. The dialog is FR-SPIMS-011 steps 3 to 5. It lists each line with the balance now, the
change and the balance after the reversal, then asks for the reason and confirms. The preview is read
without a lock and is only for the supervisor to read. The ledger decides again under lock, so a
reversal the preview says would go negative is still refused by `InsufficientStock` if it is
confirmed.

A draft is somebody's cart, not a record, so the record screen answers 404 for one.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Transactions/TransactionScreensTest.php`:

```php
<?php

use App\Domain\Ledger\StockLedger;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Livewire\Transactions\Index;
use App\Livewire\Transactions\Show;
use App\Models\StockTransaction;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->part = partWithStock('10', ['part_number' => 'BRG-6204']);
    $this->withdrawal = $this->ledger->complete(
        StockTransaction::factory()->withLines([[$this->part, '4']])->create(['custodian_user_id' => $this->custodian->id]),
        $this->custodian,
    );
});

it('opens the transaction list and a record for every role', function (Role $role) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('transactions.index'))->assertOk()->assertSee($this->withdrawal->transaction_no);
    $this->actingAs($user)->get(route('transactions.show', $this->withdrawal))->assertOk()->assertSee('BRG-6204');

    expect(menuLabels($user))->toContain('Transactions');
})->with([Role::Custodian, Role::Supervisor, Role::Planner, Role::Admin]);

it('does not open a draft as a record', function () {
    $draft = StockTransaction::factory()->create();

    $this->actingAs($this->supervisor)->get(route('transactions.show', $draft))->assertNotFound();
});

it('finds a transaction by its number or its paper form number, ignoring case', function () {
    $backdated = $this->ledger->complete(
        StockTransaction::factory()->backdated(CarbonImmutable::now()->subDay())->withLines([[$this->part, '1']])
            ->create(['paper_form_no' => 'PF-00812']),
        $this->custodian,
    );

    Livewire::actingAs($this->custodian)->test(Index::class)
        ->set('search', 'pf-008')
        ->assertSee($backdated->transaction_no)
        ->assertDontSee($this->withdrawal->transaction_no)
        ->set('search', strtolower((string) $this->withdrawal->transaction_no))
        ->assertSee($this->withdrawal->transaction_no)
        ->assertDontSee($backdated->transaction_no);
});

it('shows both dates, the form and the reason of a backdated entry', function () {
    $occurred = CarbonImmutable::parse('2026-09-20 14:30', 'Asia/Manila');
    $backdated = $this->ledger->complete(
        StockTransaction::factory()->backdated($occurred)->withLines([[$this->part, '1']])
            ->create(['paper_form_no' => 'PF-00812', 'backdate_reason' => 'Network outage on night shift']),
        $this->custodian,
    );

    $this->actingAs($this->custodian)->get(route('transactions.show', $backdated))
        ->assertOk()
        ->assertSee(__('Took place'))
        ->assertSee('2026-09-20 14:30')
        ->assertSee(__('Entered'))
        ->assertSee('PF-00812')
        ->assertSee('Network outage on night shift')
        ->assertSee(__('From the signed paper form'));
});

it('previews each balance a reversal would leave', function () {
    Livewire::actingAs($this->supervisor)->test(Show::class, ['transaction' => $this->withdrawal])
        ->assertSee(__('Reverse transaction'))
        ->assertSeeInOrder(['BRG-6204', '6', '+4', '10'])
        ->assertDontSee(__('would take a balance below zero'));
});

it('warns in the preview when a reversal would take a balance below zero', function () {
    $delivery = $this->ledger->complete(
        StockTransaction::factory()->replenishment()->withLines([[$this->part, '10']])->create(),
        $this->custodian,
    );
    $this->ledger->complete(StockTransaction::factory()->withLines([[$this->part, '14']])->create(), $this->custodian);

    Livewire::actingAs($this->supervisor)->test(Show::class, ['transaction' => $delivery])
        ->assertSee(__('would take a balance below zero'));
});

it('reverses from the record, with a reason, and shows the result', function () {
    $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['transaction' => $this->withdrawal])
        ->set('reversalReason', 'Issued to the wrong technician')
        ->call('confirmReversal')
        ->assertHasNoErrors();

    $reversing = StockTransaction::query()->where('reverses_transaction_id', $this->withdrawal->id)->sole();

    expect($this->withdrawal->refresh()->status)->toBe(TransactionStatus::Reversed)
        ->and(onHand($this->part))->toBe('10.000');

    $component->assertSee($reversing->transaction_no)
        ->assertSee("Reversed by {$this->supervisor->name}, reason: Issued to the wrong technician")
        ->assertDontSee(__('Reverse transaction'));

    $this->actingAs($this->custodian)->get(route('transactions.show', $reversing))
        ->assertSee(__('Reverses'))
        ->assertSee($this->withdrawal->transaction_no);
});

it('keeps the transaction completed and says why when the reason is blank', function () {
    Livewire::actingAs($this->supervisor)->test(Show::class, ['transaction' => $this->withdrawal])
        ->set('reversalReason', ' ')
        ->call('confirmReversal')
        ->assertHasErrors('reversalReason');

    expect($this->withdrawal->refresh()->status)->toBe(TransactionStatus::Completed);
});

it('shows the refusal when a delivery can no longer be reversed', function () {
    $delivery = $this->ledger->complete(
        StockTransaction::factory()->replenishment()->withLines([[$this->part, '10']])->create(),
        $this->custodian,
    );
    $this->ledger->complete(StockTransaction::factory()->withLines([[$this->part, '14']])->create(), $this->custodian);

    Livewire::actingAs($this->supervisor)->test(Show::class, ['transaction' => $delivery])
        ->set('reversalReason', 'Wrong delivery')
        ->call('confirmReversal')
        ->assertHasErrors('reversalReason')
        ->assertSee('Insufficient stock for BRG-6204');

    expect($delivery->refresh()->status)->toBe(TransactionStatus::Completed);
});

it('lets only an Inventory Supervisor reverse', function (Role $role, bool $allowed) {
    $component = Livewire::actingAs(userWithRoles($role))->test(Show::class, ['transaction' => $this->withdrawal])
        ->set('reversalReason', 'Wrong part')
        ->call('confirmReversal');

    if ($allowed) {
        $component->assertHasNoErrors();
    } else {
        $component->assertForbidden();
    }

    expect($this->withdrawal->refresh()->status)
        ->toBe($allowed ? TransactionStatus::Reversed : TransactionStatus::Completed);
})->with(roleMatrix('-S--'));

it('offers Reverse only to a supervisor, and says why an opening balance cannot be reversed', function () {
    $opening = StockTransaction::query()->where('type', TransactionType::Opening)->sole();

    Livewire::actingAs($this->custodian)->test(Show::class, ['transaction' => $this->withdrawal])
        ->assertDontSee(__('Reverse transaction'));

    Livewire::actingAs($this->supervisor)->test(Show::class, ['transaction' => $opening])
        ->assertDontSee(__('Reverse transaction'))
        ->assertSee(__('is an opening balance and cannot be reversed'), false);
});

it('marks a reversed withdrawal as reversed on its station receipt too', function () {
    $this->ledger->reverse($this->withdrawal, 'Wrong part', $this->supervisor);

    $this->actingAs($this->custodian)->get(route('station.receipt', $this->withdrawal))
        ->assertOk()
        ->assertSee("Reversed by {$this->supervisor->name}, reason: Wrong part");
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Transactions/TransactionScreensTest.php
```

Expected: FAIL — `Route [transactions.index] not defined`.

- [ ] **Step 3: Name the record's relations on the model**

In `app/Models/StockTransaction.php`, add this constant at the top of the class body, before
`casts()`:

```php
    /**
     * Everything the transaction record shows (resources/views/components/transaction-record.blade.php),
     * for the screens that render it to eager load.
     */
    public const array RECORD_RELATIONS = [
        'custodian', 'personnel', 'equipment', 'purpose', 'identificationAuthorisedBy',
        'lines.part', 'movements', 'reverses', 'reversal', 'reversedBy', 'backdateAuthorisedBy',
        'countSession', 'overrides.adjustment', 'overrides.part',
    ];
```

- [ ] **Step 4: Move the record into a component**

Create `resources/views/components/transaction-record.blade.php`. The fields it shows for a
withdrawal are the ones `receipt.blade.php` shows today, in the same order and with the same wording,
because `WithdrawalStationTest` asserts them:

```blade
@props(['transaction'])

@php
    $timezone = app(\App\Domain\Settings\InstallSettings::class)->timezone();
    $isReversal = $transaction->reverses_transaction_id !== null;
    // An adjustment's lines and a reversal's run either way, so their quantities carry a sign.
    $signed = $transaction->type === \App\Enums\TransactionType::Adjustment || $isReversal;
    $postedOverrides = $transaction->overrides->whereNotNull('adjustment_transaction_id');
@endphp

<dl class="mb-6 grid grid-cols-2 gap-4">
    @if ($transaction->is_backdated)
        {{-- FR-SPIMS-012: both the time it took place and the time it was entered. --}}
        <div>
            <flux:text size="sm">{{ __('Took place') }}</flux:text>
            <flux:text>{{ $transaction->occurred_at->setTimezone($timezone)->format('Y-m-d H:i') }}</flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Entered') }}</flux:text>
            <flux:text>{{ $transaction->recorded_at?->setTimezone($timezone) }}</flux:text>
        </div>
    @else
        <div>
            <flux:text size="sm">{{ __('Date and time') }}</flux:text>
            <flux:text>{{ $transaction->recorded_at?->setTimezone($timezone) }}</flux:text>
        </div>
    @endif
    <div>
        <flux:text size="sm">{{ __('Custodian') }}</flux:text>
        <flux:text>{{ $transaction->custodian->name }}</flux:text>
    </div>
    @if ($transaction->personnel)
        <div>
            <flux:text size="sm">{{ __('Received by') }}</flux:text>
            <flux:text>{{ $transaction->personnel->full_name }} ({{ $transaction->personnel->employee_no }})</flux:text>
        </div>
        {{-- A manual identification or a paper form never prints a badge line: no badge was scanned. --}}
        @if ($transaction->identification_method === \App\Enums\IdentificationMethod::BadgeScan)
            <div>
                <flux:text size="sm">{{ __('Badge') }}</flux:text>
                <flux:text>{{ $transaction->personnel->badge_uid_masked }}</flux:text>
            </div>
        @elseif ($transaction->identification_method === \App\Enums\IdentificationMethod::SupervisorManual)
            <div class="col-span-2">
                <flux:text size="sm">{{ __('Identification') }}</flux:text>
                <flux:text>
                    {{ __('Identified manually by :name, reason: :reason', [
                        'name' => $transaction->identificationAuthorisedBy?->name,
                        'reason' => $transaction->identification_reason,
                    ]) }}
                </flux:text>
            </div>
        @elseif ($transaction->identification_method === \App\Enums\IdentificationMethod::PaperForm)
            <div class="col-span-2">
                <flux:text size="sm">{{ __('Identification') }}</flux:text>
                <flux:text>{{ __('From the signed paper form') }}</flux:text>
            </div>
        @endif
    @endif
    @if ($transaction->type === \App\Enums\TransactionType::Withdrawal)
        <div>
            <flux:text size="sm">{{ __('Equipment') }}</flux:text>
            <flux:text>
                @if ($transaction->equipment)
                    {{ $transaction->equipment->code }} — {{ $transaction->equipment->name }}
                @endif
            </flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Purpose') }}</flux:text>
            <flux:text>{{ $transaction->purpose?->name }}</flux:text>
        </div>
    @endif
    @if ($transaction->type === \App\Enums\TransactionType::Replenishment)
        <div>
            <flux:text size="sm">{{ __('Supplier') }}</flux:text>
            <flux:text>{{ $transaction->supplier }}</flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('DR or PO reference') }}</flux:text>
            <flux:text>{{ $transaction->reference_no }}</flux:text>
        </div>
    @endif
    @if ($transaction->reason_code)
        <div>
            <flux:text size="sm">{{ __('Reason') }}</flux:text>
            <flux:text>{{ \Illuminate\Support\Str::headline($transaction->reason_code->value) }}</flux:text>
        </div>
    @endif
    @if ($transaction->remarks)
        <div class="col-span-2">
            <flux:text size="sm">{{ __('Remarks') }}</flux:text>
            <flux:text>{{ $transaction->remarks }}</flux:text>
        </div>
    @endif
    @if ($transaction->is_backdated)
        <div>
            <flux:text size="sm">{{ __('Paper form') }}</flux:text>
            <flux:text>{{ $transaction->paper_form_no }}</flux:text>
        </div>
        <div>
            <flux:text size="sm">{{ __('Reason for backdating') }}</flux:text>
            <flux:text>{{ $transaction->backdate_reason }}</flux:text>
        </div>
        @if ($transaction->backdateAuthorisedBy)
            <div class="col-span-2">
                <flux:text size="sm">{{ __('Beyond the backdating limit') }}</flux:text>
                <flux:text>{{ __('Authorised by :name', ['name' => $transaction->backdateAuthorisedBy->name]) }}</flux:text>
            </div>
        @endif
    @endif
    @if ($transaction->status === \App\Enums\TransactionStatus::Reversed)
        <div class="col-span-2">
            <flux:text size="sm">{{ __('Reversed') }}</flux:text>
            <flux:text>
                {{ __('Reversed by :name, reason: :reason', [
                    'name' => $transaction->reversedBy?->name,
                    'reason' => $transaction->reversal_reason,
                ]) }}
                @if ($transaction->reversal)
                    —
                    <flux:link :href="route('transactions.show', $transaction->reversal)" wire:navigate>
                        {{ $transaction->reversal->transaction_no }}
                    </flux:link>
                @endif
            </flux:text>
        </div>
    @endif
    @if ($isReversal && $transaction->reverses)
        <div class="col-span-2">
            <flux:text size="sm">{{ __('Reverses') }}</flux:text>
            <flux:text>
                <flux:link :href="route('transactions.show', $transaction->reverses)" wire:navigate>
                    {{ $transaction->reverses->transaction_no }}
                </flux:link>
            </flux:text>
        </div>
    @endif
    @if ($postedOverrides->isNotEmpty())
        <div class="col-span-2">
            <flux:text size="sm">{{ __('Supervisor override') }}</flux:text>
            @foreach ($postedOverrides as $override)
                <flux:text>
                    {{ __(':part raised by :number before this withdrawal', [
                        'part' => $override->part->part_number,
                        'number' => $override->adjustment?->transaction_no,
                    ]) }}
                </flux:text>
            @endforeach
        </div>
    @endif
    @if ($transaction->countSession)
        <div class="col-span-2">
            <flux:text size="sm">{{ __('Stock count') }}</flux:text>
            <flux:text>{{ __('Posted by count :number', ['number' => $transaction->countSession->session_no]) }}</flux:text>
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
            <flux:table.row wire:key="record-line-{{ $line->id }}">
                <flux:table.cell>{{ $line->part->part_number }}</flux:table.cell>
                <flux:table.cell class="whitespace-normal">{{ $line->part->description }}</flux:table.cell>
                <flux:table.cell align="end">
                    {{ $signed ? ($line->direction < 0 ? '−' : '+') : '' }}{{ \App\Support\Quantity::display($line->quantity) }} {{ $line->part->unit_of_measure }}
                </flux:table.cell>
                <flux:table.cell align="end">
                    {{ \App\Support\Quantity::display($transaction->movements->firstWhere('stock_transaction_line_id', $line->id)?->balance_after) }} {{ $line->part->unit_of_measure }}
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
```

Replace the whole of `resources/views/livewire/station/receipt.blade.php` with:

```blade
@php($transaction = $this->transaction)

<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $transaction->transaction_no }}</flux:heading>
        <flux:button :href="route('station.withdrawal')" wire:navigate>{{ __('New withdrawal') }}</flux:button>
    </div>

    <x-transaction-record :transaction="$transaction" />
</section>
```

In `app/Livewire/Station/Receipt.php`, replace the body of `transaction()` with:

```php
        return StockTransaction::query()
            ->with(StockTransaction::RECORD_RELATIONS)
            ->findOrFail($this->transactionId);
```

- [ ] **Step 5: Write the list**

Create `app/Livewire/Transactions/Index.php`:

```php
<?php

namespace App\Livewire\Transactions;

use App\Enums\Permission;
use App\Enums\TransactionStatus;
use App\Models\StockTransaction;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Completed and reversed transactions, newest first, so a supervisor can open the one to reverse
 * (PRD FR-SPIMS-011 step 1). Plan 6 builds the full movement history and reports; this finds one
 * transaction by its number or its paper form number.
 */
#[Title('Transactions')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize(Permission::MovementsView);
    }

    /**
     * @return Collection<int, StockTransaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        $term = Str::lower(trim($this->search));

        return StockTransaction::query()
            ->whereIn('status', [TransactionStatus::Completed, TransactionStatus::Reversed])
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(transaction_no) like ?', [SearchTerm::startsWith($term)])
                ->orWhereRaw('lower(paper_form_no) like ?', [SearchTerm::startsWith($term)])))
            ->withCount('lines')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }
}
```

Create `resources/views/livewire/transactions.blade.php` (Livewire 4 names an `Index` component after
its folder):

```blade
<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Transactions') }}</flux:heading>

    <flux:input
        wire:model.live.debounce.300ms="search"
        :label="__('Find a transaction')"
        :placeholder="__('Transaction or paper form number')"
        icon="magnifying-glass"
        class="mb-6 max-w-sm"
        autofocus
    />

    @if ($this->transactions->isEmpty())
        <flux:text>{{ __('No completed transaction matches.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Recorded') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Lines') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->transactions as $transaction)
                    <flux:table.row wire:key="transaction-{{ $transaction->id }}">
                        <flux:table.cell>
                            <flux:link :href="route('transactions.show', $transaction)" wire:navigate>{{ $transaction->transaction_no }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ \Illuminate\Support\Str::headline($transaction->type->value) }}</flux:table.cell>
                        <flux:table.cell>{{ $transaction->recorded_at?->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone())->format('Y-m-d H:i') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $transaction->lines_count }}</flux:table.cell>
                        <flux:table.cell class="space-x-1">
                            <flux:badge size="sm">{{ \Illuminate\Support\Str::headline($transaction->status->value) }}</flux:badge>
                            @if ($transaction->is_backdated)
                                <flux:badge size="sm" color="amber">{{ __('Backdated') }}</flux:badge>
                            @endif
                            @if ($transaction->reverses_transaction_id !== null)
                                <flux:badge size="sm" color="zinc">{{ __('Reversal') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
```

- [ ] **Step 6: Write the record screen**

Create `app/Livewire/Transactions/Show.php`:

```php
<?php

namespace App\Livewire\Transactions;

use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\ReversalRefused;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\StockLedger;
use App\Enums\Permission;
use App\Enums\TransactionStatus;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use Brick\Math\BigDecimal;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One transaction's record, and where a supervisor reverses it (PRD FR-SPIMS-011).
 */
#[Title('Transaction record')]
class Show extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $transactionId;

    public string $reversalReason = '';

    public function mount(StockTransaction $transaction): void
    {
        $this->authorize(Permission::MovementsView);

        // A draft is somebody's cart, not a record yet.
        abort_if($transaction->status === TransactionStatus::Draft, 404);

        $this->transactionId = $transaction->id;
    }

    #[Computed]
    public function transaction(): StockTransaction
    {
        return StockTransaction::query()
            ->with([...StockTransaction::RECORD_RELATIONS, 'lines.part.stock'])
            ->findOrFail($this->transactionId);
    }

    /**
     * Why this transaction cannot be reversed, or null when it can.
     */
    #[Computed]
    public function reversalRefusal(): ?string
    {
        try {
            app(StockLedger::class)->guardReversible($this->transaction());
        } catch (ReversalRefused $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * FR-SPIMS-011 step 3: each line, the balance now, the change and the balance after. Read without
     * a lock, for the supervisor to look at. The ledger decides again under lock.
     *
     * @return list<array{part: Part, onHand: string, change: string, resulting: string, negative: bool}>
     */
    #[Computed]
    public function reversalPreview(): array
    {
        $preview = [];

        foreach ($this->transaction()->lines as $line) {
            $stock = $line->part->stock;
            $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');
            $change = $line->signedQuantity()->negated();
            $resulting = $onHand->plus($change);

            $preview[] = [
                'part' => $line->part,
                'onHand' => (string) $onHand,
                'change' => (string) $change,
                'resulting' => (string) $resulting,
                'negative' => $resulting->isNegative(),
            ];
        }

        return $preview;
    }

    public function confirmReversal(): void
    {
        $this->authorize(Permission::TransactionsReverse);
        $this->resetErrorBag();

        try {
            $reversing = app(StockLedger::class)->reverse($this->transaction(), $this->reversalReason, $this->actor());
        } catch (ReversalRefused|InsufficientStock|InstallNotConfigured|StockBusy $refused) {
            $this->addError('reversalReason', $refused->getMessage());

            return;
        }

        $this->reversalReason = '';
        unset($this->transaction, $this->reversalRefusal, $this->reversalPreview);

        Flux::modal('reverse-transaction')->close();
        Flux::toast(variant: 'success', text: __('Reversed by :number.', ['number' => $reversing->transaction_no]));
    }
}
```

Create `resources/views/livewire/transactions/show.blade.php`:

```blade
@php($transaction = $this->transaction)

<section class="w-full max-w-3xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $transaction->transaction_no ?? __('Cancelled draft') }}</flux:heading>
            <flux:text>
                {{ \Illuminate\Support\Str::headline($transaction->type->value) }} ·
                {{ \Illuminate\Support\Str::headline($transaction->status->value) }}
            </flux:text>
        </div>

        @can(\App\Enums\Permission::TransactionsReverse)
            @if ($this->reversalRefusal === null)
                <flux:modal.trigger name="reverse-transaction">
                    <flux:button variant="danger">{{ __('Reverse transaction') }}</flux:button>
                </flux:modal.trigger>
            @endif
        @endcan
    </div>

    @can(\App\Enums\Permission::TransactionsReverse)
        @if ($this->reversalRefusal !== null && $transaction->status === \App\Enums\TransactionStatus::Completed)
            <flux:callout icon="information-circle" class="mb-6">
                <flux:callout.text>{{ $this->reversalRefusal }}</flux:callout.text>
            </flux:callout>
        @endif
    @endcan

    <x-transaction-record :transaction="$transaction" />

    @can(\App\Enums\Permission::TransactionsReverse)
        @if ($this->reversalRefusal === null)
            <flux:modal name="reverse-transaction" class="md:w-[36rem]">
                <div class="space-y-6">
                    <flux:heading size="lg">{{ __('Reverse :number', ['number' => $transaction->transaction_no]) }}</flux:heading>
                    <flux:text>
                        {{ __('Equal and opposite movements are recorded, and both transactions stay in the history. If only part of it was wrong, reverse all of it and record the correct transaction again.') }}
                    </flux:text>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Part number') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('On hand now') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Change') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('After reversal') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->reversalPreview as $row)
                                <flux:table.row wire:key="reverse-{{ $row['part']->id }}">
                                    <flux:table.cell>{{ $row['part']->part_number }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ \App\Support\Quantity::display($row['onHand']) }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        {{ str_starts_with($row['change'], '-') ? '−' : '+' }}{{ \App\Support\Quantity::display(ltrim($row['change'], '-')) }}
                                    </flux:table.cell>
                                    <flux:table.cell align="end" :class="$row['negative'] ? 'text-red-600' : ''">
                                        {{ \App\Support\Quantity::display($row['resulting']) }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    @if (collect($this->reversalPreview)->contains('negative', true))
                        <flux:callout variant="danger" icon="exclamation-triangle">
                            <flux:callout.text>{{ __('Reversing this now would take a balance below zero, so it will be refused.') }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    <flux:textarea wire:model="reversalReason" :label="__('Reason for the reversal')" rows="2" autofocus />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" wire:click="confirmReversal">{{ __('Reverse') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    @endcan
</section>
```

- [ ] **Step 7: Add the routes and the menu item**

In `routes/spims.php`, add `use App\Livewire\Transactions;` to the imports and these routes after the
station routes:

```php
    Route::livewire('transactions', Transactions\Index::class)->name('transactions.index');
    Route::livewire('transactions/{transaction}', Transactions\Show::class)
        ->whereNumber('transaction')
        ->name('transactions.show');
```

In `app/Support/Navigation.php`, add this item after `Spare parts`:

```php
        ['group' => 'Storeroom', 'label' => 'Transactions', 'icon' => 'queue-list', 'route' => 'transactions.index', 'active' => 'transactions.*', 'permission' => Permission::MovementsView],
```

In `tests/Feature/Shell/AppShellTest.php`, the Custodian's menu becomes:

```php
        ->toBe(['Dashboard', 'Withdrawal station', 'Record a delivery', 'Part lookup', 'Spare parts', 'Transactions'])
```

In `tests/Feature/Access/ScreenAccessMatrixTest.php`, add `use App\Enums\TransactionStatus;` and these
rows after `'Part lookup'`. A cancelled draft stands in for a record, because the record screen
answers 404 for a draft:

```php
    'Transactions' => ['transactions.index', null, 'CSPA'],
    'Transaction' => [
        'transactions.show',
        fn () => ['transaction' => StockTransaction::factory()->create(['status' => TransactionStatus::Cancelled])],
        'CSPA',
    ],
```

In `tests/Feature/Access/ActionReauthorizationTest.php`, add `use App\Enums\TransactionStatus;` and
`use App\Livewire\Transactions\Show as TransactionRecord;` to the imports, and this row at the end
of the `reauthorized actions` dataset. The action authorizes before it reads anything, so the
cancelled draft it is given is never looked at:

```php
    'Transactions\Show::confirmReversal' => [
        Role::Supervisor, TransactionRecord::class,
        fn () => ['transaction' => StockTransaction::factory()->create(['status' => TransactionStatus::Cancelled])],
        'confirmReversal', fn () => [],
    ],
```

with `use App\Models\StockTransaction;` among the imports if it is not there yet.

- [ ] **Step 8: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Transactions tests/Feature/Withdrawals/WithdrawalStationTest.php tests/Feature/Shell tests/Feature/Access
```

Expected: PASS, 18 new tests in `TransactionScreensTest` (the role datasets are four and four), 12
new rows in `ScreenAccessMatrixTest` and 1 in `ActionReauthorizationTest`. The station receipt tests stay green, because the component
renders every field they assert in the same words.

- [ ] **Step 9: Run the full check and commit**

```bash
composer test
git add resources/views/components/transaction-record.blade.php app/Livewire/Transactions/Index.php resources/views/livewire/transactions.blade.php app/Livewire/Transactions/Show.php resources/views/livewire/transactions/show.blade.php app/Models/StockTransaction.php app/Livewire/Station/Receipt.php resources/views/livewire/station/receipt.blade.php app/Support/Navigation.php routes/spims.php tests/Feature/Shell/AppShellTest.php tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/Access/ActionReauthorizationTest.php tests/Feature/Transactions/TransactionScreensTest.php
git commit -m "List completed transactions, show their record, and reverse one"
```

---

## Task 5: The stock adjustment screen

**Files:**
- Create: `app/Livewire/Corrections/Adjustment.php`,
  `resources/views/livewire/corrections/adjustment.blade.php`
- Modify: `app/Support/Navigation.php`, `routes/spims.php`,
  `tests/Feature/Access/ScreenAccessMatrixTest.php`, `tests/Feature/Access/ActionReauthorizationTest.php`
- Test: `tests/Feature/Adjustments/AdjustmentScreenTest.php`

**Interfaces:**
- Consumes: `AdjustmentService::record()` (Task 2); `PartSearch::matching(string): Builder<Part>`
  (Plan 2); route `transactions.show` (Task 4); `Permission::AdjustmentsRecord` (Supervisor only).
- Produces: route `corrections.adjustment` (`/corrections/adjustment`); the menu item
  **Stock adjustment**; the Livewire actions `enterPart()`, `choosePart(int)`, `clearPart()` and
  `confirmAdjustment()`.

FR-SPIMS-010's main flow, one part at a time:

1. Choose the part by number, or pick it from the matches. Inactive parts are offered too, because
   they can be adjusted.
2. See its balance.
3. Choose Decrease or Increase and enter the quantity, and see the resulting balance.
4. Choose the reason, and give remarks when the reason is Other.
5. Confirm.

The screen's opening text covers the two alternate flows that point elsewhere. A mistake in a
completed withdrawal or delivery is reversed from its record, and a difference found in a count is
posted from the count. After confirming, the screen links to the new adjustment's record.

The resulting balance on screen is read without a lock and is only a preview. The ledger checks it
again under lock, so a decrease that goes negative is refused on Confirm even if the preview was
stale.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Adjustments/AdjustmentScreenTest.php`:

```php
<?php

use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Livewire\Corrections\Adjustment;
use App\Models\StockTransaction;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->part = partWithStock('10', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing']);
});

it('opens the adjustment screen only for an Inventory Supervisor', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $response = $this->actingAs($user)->get(route('corrections.adjustment'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
    expect(in_array('Stock adjustment', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-S--'));

it('records an adjustment from end to end and links to its record', function () {
    $component = Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->set('partEntry', 'brg-6204')
        ->call('enterPart')
        ->assertHasNoErrors()
        ->assertSee('Deep groove ball bearing')
        ->set('direction', 'decrease')
        ->set('quantity', '2')
        ->assertSee(__('Resulting balance'))
        ->set('reason', ReasonCode::Damage->value)
        ->call('confirmAdjustment')
        ->assertHasNoErrors();

    $adjustment = StockTransaction::query()->where('type', TransactionType::Adjustment)->sole();

    expect(onHand($this->part))->toBe('8.000')
        ->and($adjustment->reason_code)->toBe(ReasonCode::Damage);

    $component->assertSee($adjustment->transaction_no)
        ->assertSee(route('transactions.show', $adjustment), false)
        ->assertSet('partId', null)
        ->assertSet('quantity', '');
});

it('shows the resulting balance before confirming', function () {
    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->call('choosePart', $this->part->id)
        ->set('direction', 'increase')
        ->set('quantity', '2.5')
        ->assertSeeInOrder([__('Resulting balance'), '12.5']);
});

it('warns before confirming a decrease that would go below zero, and the ledger refuses it', function () {
    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->call('choosePart', $this->part->id)
        ->set('direction', 'decrease')
        ->set('quantity', '12')
        ->assertSee(__('below zero'))
        ->set('reason', ReasonCode::Shrinkage->value)
        ->call('confirmAdjustment')
        ->assertHasErrors('adjustment')
        ->assertSee('Insufficient stock for BRG-6204');

    expect(onHand($this->part))->toBe('10.000');
});

it('shows a refusal and records nothing when remarks are missing for Other', function () {
    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->call('choosePart', $this->part->id)
        ->set('quantity', '1')
        ->set('reason', ReasonCode::Other->value)
        ->call('confirmAdjustment')
        ->assertHasErrors('adjustment')
        ->assertSee(__('Remarks are required when the reason is Other.'));

    expect(StockTransaction::query()->where('type', TransactionType::Adjustment)->exists())->toBeFalse();
});

it('asks for a part before anything else', function () {
    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->set('quantity', '1')
        ->set('reason', ReasonCode::Miscount->value)
        ->call('confirmAdjustment')
        ->assertHasErrors('partEntry');
});

it('refuses a direction that is neither increase nor decrease', function () {
    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->call('choosePart', $this->part->id)
        ->set('direction', 'sideways')
        ->set('quantity', '1')
        ->set('reason', ReasonCode::Miscount->value)
        ->call('confirmAdjustment')
        ->assertHasErrors('direction');

    expect(onHand($this->part))->toBe('10.000');
});

it('offers an Inactive part and adjusts it', function () {
    $retired = partWithStock('3', ['part_number' => 'OLD-100', 'is_active' => false]);

    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->set('partEntry', 'OLD')
        ->call('enterPart')
        ->assertSee('OLD-100')
        ->call('choosePart', $retired->id)
        ->assertSee(__('Inactive'))
        ->set('quantity', '3')
        ->set('reason', ReasonCode::Damage->value)
        ->call('confirmAdjustment')
        ->assertHasNoErrors();

    expect(onHand($retired))->toBe('0.000');
});

it('points corrections of a completed transaction and count differences elsewhere', function () {
    Livewire::actingAs($this->supervisor)->test(Adjustment::class)
        ->assertSee(__('reverse it from its transaction record'))
        ->assertSee(__('posted from the count'));
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Adjustments/AdjustmentScreenTest.php
```

Expected: FAIL — `Route [corrections.adjustment] not defined`.

- [ ] **Step 3: Write the component**

Create `app/Livewire/Corrections/Adjustment.php`:

```php
<?php

namespace App\Livewire\Corrections;

use App\Domain\Adjustments\AdjustmentService;
use App\Domain\Adjustments\Exceptions\AdjustmentRefused;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Parts\PartSearch;
use App\Enums\Permission;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A supervisor corrects one spare part's balance (PRD FR-SPIMS-010): choose the part, see its
 * balance, enter the change and the reason, see the resulting balance, and confirm.
 */
#[Title('Stock adjustment')]
class Adjustment extends Component
{
    use ActsAsSignedInUser;

    public string $partEntry = '';

    public ?int $partId = null;

    /** 'decrease' or 'increase'. */
    public string $direction = 'decrease';

    public string $quantity = '';

    public string $reason = '';

    public string $remarks = '';

    /** The adjustment just recorded here, linked to its record. */
    public ?int $recordedId = null;

    public function mount(): void
    {
        $this->authorize(Permission::AdjustmentsRecord);
    }

    public function enterPart(): void
    {
        $this->authorize(Permission::AdjustmentsRecord);
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

        $this->choosePart($part->id);
    }

    public function choosePart(int $partId): void
    {
        $this->authorize(Permission::AdjustmentsRecord);
        $this->resetErrorBag();

        $this->partId = Part::query()->findOrFail($partId)->id;
        $this->partEntry = '';
        $this->recordedId = null;
        $this->forget();
    }

    public function clearPart(): void
    {
        $this->authorize(Permission::AdjustmentsRecord);

        $this->partId = null;
        $this->forget();
    }

    public function confirmAdjustment(): void
    {
        $this->authorize(Permission::AdjustmentsRecord);
        $this->resetErrorBag();

        $part = $this->part();

        if (! $part instanceof Part) {
            $this->addError('partEntry', __('Choose the spare part to adjust.'));

            return;
        }

        $direction = match ($this->direction) {
            'increase' => 1,
            'decrease' => -1,
            default => null,
        };

        if ($direction === null) {
            $this->addError('direction', __('Choose Increase or Decrease.'));

            return;
        }

        try {
            $adjustment = app(AdjustmentService::class)->record(
                $this->actor(),
                $part,
                $direction,
                $this->quantity,
                ReasonCode::tryFrom($this->reason),
                $this->remarks,
            );
        } catch (AdjustmentRefused|InsufficientStock|InstallNotConfigured|StockBusy $refused) {
            $this->addError('adjustment', $refused->getMessage());

            return;
        }

        $this->reset(['partEntry', 'partId', 'direction', 'quantity', 'reason', 'remarks']);
        $this->recordedId = $adjustment->id;
        $this->forget();

        Flux::toast(variant: 'success', text: __('Adjustment :number recorded.', ['number' => $adjustment->transaction_no]));
    }

    #[Computed]
    public function part(): ?Part
    {
        return $this->partId === null ? null : Part::query()->with('stock')->find($this->partId);
    }

    #[Computed]
    public function recorded(): ?StockTransaction
    {
        return $this->recordedId === null ? null : StockTransaction::query()->find($this->recordedId);
    }

    /**
     * FR-SPIMS-010 step 6: the balance this adjustment would leave, or null until there is a part and
     * a number. A preview only — the ledger decides again under lock.
     */
    #[Computed]
    public function resulting(): ?string
    {
        $part = $this->part();

        if (! $part instanceof Part) {
            return null;
        }

        try {
            $quantity = BigDecimal::of(trim($this->quantity));
        } catch (MathException) {
            return null;
        }

        $stock = $part->stock;
        $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');

        return (string) ($this->direction === 'increase' ? $onHand->plus($quantity) : $onHand->minus($quantity));
    }

    /**
     * Inactive parts are offered too: they can be adjusted (PRD §7.2).
     *
     * @return Collection<int, Part>
     */
    #[Computed]
    public function matches(): Collection
    {
        if ($this->partId !== null || trim($this->partEntry) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->partEntry)->with('stock')->limit(8)->get();
    }

    private function forget(): void
    {
        unset($this->part, $this->recorded, $this->resulting, $this->matches);
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/livewire/corrections/adjustment.blade.php`:

```blade
<section class="w-full max-w-3xl">
    <flux:heading size="xl" level="1" class="mb-2">{{ __('Stock adjustment') }}</flux:heading>
    <flux:text class="mb-6">
        {{ __('For stock that changed without a withdrawal or a delivery. To correct a completed withdrawal or delivery, reverse it from its transaction record instead. A difference found in a stock count is posted from the count.') }}
    </flux:text>

    @if ($this->recorded)
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            <flux:callout.heading>
                {{ __('Adjustment recorded:') }}
                <flux:link :href="route('transactions.show', $this->recorded)" wire:navigate>{{ $this->recorded->transaction_no }}</flux:link>
            </flux:callout.heading>
        </flux:callout>
    @endif

    <flux:error name="adjustment" class="mb-4" />

    @if ($this->part === null)
        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input
                wire:model="partEntry"
                wire:keydown.enter.prevent="enterPart"
                :label="__('Part number')"
                :placeholder="__('Scan or type, then press Enter')"
                class="max-w-sm"
                autofocus
            />
            <flux:button wire:click="enterPart">{{ __('Choose') }}</flux:button>
        </div>

        @if ($this->matches->isNotEmpty())
            <div class="mb-6 space-y-2">
                @foreach ($this->matches as $match)
                    <flux:button size="sm" wire:click="choosePart({{ $match->id }})" wire:key="adjust-match-{{ $match->id }}">
                        {{ $match->part_number }} — {{ $match->description }}
                        @unless ($match->is_active)
                            ({{ __('Inactive') }})
                        @endunless
                    </flux:button>
                @endforeach
            </div>
        @endif
    @else
        @php($part = $this->part)

        <dl class="mb-6 grid grid-cols-2 gap-4">
            <div>
                <flux:text size="sm">{{ __('Part number') }}</flux:text>
                <flux:text>
                    {{ $part->part_number }}
                    @unless ($part->is_active)
                        <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                    @endunless
                </flux:text>
            </div>
            <div>
                <flux:text size="sm">{{ __('Balance now') }}</flux:text>
                <flux:text>{{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}</flux:text>
            </div>
            <div class="col-span-2">
                <flux:text size="sm">{{ __('Description') }}</flux:text>
                <flux:text>{{ $part->description }}</flux:text>
            </div>
        </dl>

        <flux:button size="sm" variant="subtle" wire:click="clearPart" class="mb-6">{{ __('Choose another part') }}</flux:button>

        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:select wire:model.live="direction" :label="__('Change')" class="max-w-40">
                <flux:select.option value="decrease">{{ __('Decrease') }}</flux:select.option>
                <flux:select.option value="increase">{{ __('Increase') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model.live.debounce.300ms="quantity" :label="__('Quantity')" class="max-w-32" inputmode="decimal" autofocus />
            <flux:select wire:model="reason" :label="__('Reason')" class="max-w-xs">
                <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
                @foreach (\App\Enums\ReasonCode::cases() as $reasonCode)
                    <flux:select.option :value="$reasonCode->value">{{ \Illuminate\Support\Str::headline($reasonCode->value) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:textarea wire:model="remarks" :label="__('Remarks')" :description="__('Required when the reason is Other.')" rows="2" class="mb-6" />

        @if ($this->resulting !== null)
            <flux:text class="mb-2">
                {{ __('Resulting balance') }}: {{ \App\Support\Quantity::display($this->resulting) }} {{ $part->unit_of_measure }}
            </flux:text>
            @if (str_starts_with($this->resulting, '-'))
                <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                    <flux:callout.text>{{ __('This would take the balance below zero, so it will be refused.') }}</flux:callout.text>
                </flux:callout>
            @endif
        @endif

        <flux:button variant="primary" wire:click="confirmAdjustment">{{ __('Confirm adjustment') }}</flux:button>
    @endif
</section>
```

- [ ] **Step 5: Add the route and the menu item**

In `routes/spims.php`, add `use App\Livewire\Corrections;` to the imports and, after the transaction
routes:

```php
    Route::livewire('corrections/adjustment', Corrections\Adjustment::class)->name('corrections.adjustment');
```

In `app/Support/Navigation.php`, add after `Transactions`:

```php
        ['group' => 'Storeroom', 'label' => 'Stock adjustment', 'icon' => 'adjustments-horizontal', 'route' => 'corrections.adjustment', 'active' => 'corrections.adjustment', 'permission' => Permission::AdjustmentsRecord],
```

The Custodian's menu does not change, so `AppShellTest` needs no edit. In
`tests/Feature/Access/ScreenAccessMatrixTest.php`, add after `'Transaction'`:

```php
    'Stock adjustment' => ['corrections.adjustment', null, '-S--'],
```

In `tests/Feature/Access/ActionReauthorizationTest.php`, add
`use App\Livewire\Corrections\Adjustment as StockAdjustment;` to the imports and these rows at the
end of the `reauthorized actions` dataset:

```php
    'Corrections\Adjustment::enterPart' => [
        Role::Supervisor, StockAdjustment::class, fn () => [], 'enterPart', fn () => [],
    ],
    'Corrections\Adjustment::choosePart' => [
        Role::Supervisor, StockAdjustment::class, fn () => [], 'choosePart', fn () => [Part::factory()->create()->id],
    ],
    'Corrections\Adjustment::clearPart' => [
        Role::Supervisor, StockAdjustment::class, fn () => [], 'clearPart', fn () => [],
    ],
    'Corrections\Adjustment::confirmAdjustment' => [
        Role::Supervisor, StockAdjustment::class, fn () => [], 'confirmAdjustment', fn () => [],
    ],
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Adjustments tests/Feature/Shell tests/Feature/Access
```

Expected: PASS, 12 new tests in `AdjustmentScreenTest` (the role matrix is four), 6 new rows in
`ScreenAccessMatrixTest` and 4 in `ActionReauthorizationTest`. `LivewireActionNamesTest` passes: no action is named after the `$wire` API.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Livewire/Corrections/Adjustment.php resources/views/livewire/corrections/adjustment.blade.php app/Support/Navigation.php routes/spims.php tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/Access/ActionReauthorizationTest.php tests/Feature/Adjustments/AdjustmentScreenTest.php
git commit -m "Add the stock adjustment screen"
```

---

## Task 6: `BackdatedEntryService` — a paper form entered after an outage

**Files:**
- Create: `app/Domain/Backdating/PaperForm.php`, `app/Domain/Backdating/PaperFormLine.php`,
  `app/Domain/Backdating/BackdatedEntryService.php`,
  `app/Domain/Backdating/Exceptions/BackdatedEntryRefused.php`
- Test: `tests/Feature/Backdating/BackdatedEntryServiceTest.php`

**Interfaces:**
- Consumes: `StockLedger::complete()`, whose `post()` already writes a backdated transaction's own
  `occurred_at` onto its movements (Plan 1); `InstallSettings::timezone()` and the
  `backdating.limit_days` setting (default 7); `Permission::BackdatedRecordWithinLimit` (Custodian,
  Supervisor) and `Permission::BackdatedRecordBeyondLimit` (Supervisor); the
  `backdate_authorised_by_user_id` column (Task 1).
- Produces:
  - `App\Domain\Backdating\PaperForm` — a readonly value: `type` (`TransactionType::Withdrawal` or
    `Replenishment`), `formNo`, `occurredAt` (the `Y-m-d\TH:i` string a `datetime-local` input sends,
    read in the install time zone), `reason`, `lines` (`list<PaperFormLine>`), and the optional
    `personnelId`, `equipmentId`, `purposeId`, `remarks`, `supplier`, `referenceNo`.
  - `App\Domain\Backdating\PaperFormLine` — `partId`, `quantity`, `unitCost` (nullable).
  - `BackdatedEntryService::record(PaperForm $form, User $custodian, ?User $authorisedBy = null): StockTransaction`
    — the completed, backdated transaction.
  - `BackdatedEntryService::occurredAt(string $local): CarbonImmutable` (UTC),
    `isBeyondLimit(CarbonImmutable $occurredAt): bool` and `limitDays(): int`. Task 7's screen uses
    them to decide whether to ask for a supervisor.
  - `App\Domain\Backdating\Exceptions\BackdatedEntryRefused` (a `RuntimeException`).

FR-SPIMS-012 and Decisions 6 to 10. The service takes the whole form at once and either records it or
refuses it. There is no draft (Decision 6).

- **The time it took place** is typed in the install time zone and stored in UTC. It may not be in the
  future.
- **The paper form number** is stored trimmed and in capitals, and may be entered once. The check
  before writing names the transaction that already has it. The unique index is the backstop for two
  custodians entering the same form at once.
- **The backdating limit** counts calendar days in the install time zone. With the default of 7, on 25
  September a form from 18 September is within the limit and one from 17 September is not, even at
  00:30 local time, when it is still 24 September in UTC.
  - Beyond the limit, a supervisor entering the form authorises it themselves.
  - A custodian needs `$authorisedBy`: a supervisor that Task 7's screen has just verified through
    `CounterAuthorisation`, in the same request.
  - Either way, `backdate_authorised_by_user_id` names the supervisor. Within the limit it is null.
- **A withdrawal** needs Active personnel, equipment and purpose, and remarks when the purpose asks for
  them, exactly as the station does (Decision 9). Its `identification_method` is `paper_form`, because
  the person is identified from the signed form (FR-SPIMS-012 step 4).
- **A delivery** needs a supplier and a reference, stored in capitals as the delivery screen stores
  them.
- **Lines** need Active parts and positive quantities. Two lines for the same part become one, as on
  the live screens.
- **A withdrawal the recorded balance cannot cover** is refused (Decision 8). The refusal names the
  parts and tells the custodian what a supervisor has to do first.

The transaction is created and posted inside one `DB::transaction()`. A refusal from the ledger rolls
the whole of it back, so no row is left behind. That matters here: `WithdrawalService::openDraft()`
resumes any draft withdrawal the custodian owns, and a leftover backdated row would turn up as their
live cart (Review Focus 3).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Backdating/BackdatedEntryServiceTest.php`:

```php
<?php

use App\Domain\Backdating\BackdatedEntryService;
use App\Domain\Backdating\Exceptions\BackdatedEntryRefused;
use App\Domain\Backdating\PaperForm;
use App\Domain\Backdating\PaperFormLine;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\IdentificationMethod;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Personnel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;

beforeEach(function () {
    configureInstall();
    // 10:00 on 25 September 2026 in Manila, the install's default time zone.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Manila'));

    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->part = partWithStock('10', ['part_number' => 'BRG-6204']);
    $this->person = Personnel::factory()->create();
    $this->equipment = Equipment::factory()->create();
    $this->purpose = WithdrawalPurpose::query()->where('requires_remarks', false)->orderBy('sort_order')->firstOrFail();
    $this->service = app(BackdatedEntryService::class);

    // A withdrawal form as the night shift filled it in, with any field replaced.
    $this->withdrawalForm = fn (array $overrides = []): PaperForm => new PaperForm(...array_merge([
        'type' => TransactionType::Withdrawal,
        'formNo' => 'PF-00812',
        'occurredAt' => '2026-09-23T02:15',
        'reason' => 'Network outage on night shift',
        'lines' => [new PaperFormLine($this->part->id, '3')],
        'personnelId' => $this->person->id,
        'equipmentId' => $this->equipment->id,
        'purposeId' => $this->purpose->id,
    ], $overrides));
});

it('records a backdated withdrawal from a paper form', function () {
    $entry = $this->service->record(($this->withdrawalForm)(['formNo' => ' pf-00812 ']), $this->custodian);

    $movement = StockMovement::query()->where('stock_transaction_id', $entry->id)->sole();
    $tookPlace = CarbonImmutable::parse('2026-09-23 02:15', 'Asia/Manila');

    expect($entry->status)->toBe(TransactionStatus::Completed)
        ->and($entry->transaction_no)->toStartWith('WD-')
        ->and($entry->is_backdated)->toBeTrue()
        ->and($entry->paper_form_no)->toBe('PF-00812')
        ->and($entry->backdate_reason)->toBe('Network outage on night shift')
        ->and($entry->identification_method)->toBe(IdentificationMethod::PaperForm)
        ->and($entry->identified_at)->toBeNull()
        ->and($entry->backdate_authorised_by_user_id)->toBeNull()
        ->and($entry->personnel_id)->toBe($this->person->id)
        ->and($entry->occurred_at->equalTo($tookPlace))->toBeTrue()
        ->and($entry->recorded_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        // Consumption counts it on the day it took place; the balance is the one at entry (design §4.5).
        ->and($movement->occurred_at->equalTo($tookPlace))->toBeTrue()
        ->and($movement->balance_after)->toBe('7.000')
        ->and(onHand($this->part))->toBe('7.000')
        ->and(AuditLog::query()->where('auditable_id', (string) $entry->id)->where('event', 'completed')->exists())->toBeTrue();
});

it('records a backdated delivery', function () {
    $entry = $this->service->record(new PaperForm(
        type: TransactionType::Replenishment,
        formNo: 'PF-00813',
        occurredAt: '2026-09-24T21:40',
        reason: 'Received during the outage',
        lines: [new PaperFormLine($this->part->id, '6', '145.50')],
        supplier: 'Pacific Bearings Inc.',
        referenceNo: ' dr-00921 ',
    ), $this->custodian);

    expect($entry->type)->toBe(TransactionType::Replenishment)
        ->and($entry->transaction_no)->toStartWith('RP-')
        ->and($entry->supplier)->toBe('Pacific Bearings Inc.')
        ->and($entry->reference_no)->toBe('DR-00921')
        ->and($entry->identification_method)->toBeNull()
        ->and($entry->personnel_id)->toBeNull()
        ->and($entry->lines()->sole()->unit_cost)->toBe('145.5000')
        ->and(onHand($this->part))->toBe('16.000');
});

it('refuses a time that has not happened yet', function () {
    expect(fn () => $this->service->record(($this->withdrawalForm)(['occurredAt' => '2026-09-25T10:01']), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, 'cannot be in the future');
});

it('refuses a date and time that is missing or does not exist', function (string $occurredAt) {
    expect(fn () => $this->service->record(($this->withdrawalForm)(['occurredAt' => $occurredAt]), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, 'Enter the date and time');
})->with(['', '2026-02-30T10:00', 'yesterday', '2026-09-23']);

it('requires the paper form number and the reason for backdating', function (string $field, string $message) {
    expect(fn () => $this->service->record(($this->withdrawalForm)([$field => '  ']), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, $message);
})->with([
    ['formNo', 'Enter the paper form number'],
    ['reason', 'Enter the reason'],
]);

it('refuses a paper form that was already entered, whatever its case', function () {
    $first = $this->service->record(($this->withdrawalForm)(), $this->custodian);

    expect(fn () => $this->service->record(($this->withdrawalForm)(['formNo' => 'pf-00812']), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, "Paper form PF-00812 has already been entered as {$first->transaction_no}");
});

it('lets a custodian enter a form exactly as old as the limit without a supervisor', function () {
    // 18 September is seven days before 25 September.
    $entry = $this->service->record(($this->withdrawalForm)(['occurredAt' => '2026-09-18T08:00']), $this->custodian);

    expect($entry->backdate_authorised_by_user_id)->toBeNull();
});

it("refuses a custodian's form older than the limit unless a supervisor authorises it", function () {
    $form = ($this->withdrawalForm)(['occurredAt' => '2026-09-17T23:59']);

    expect(fn () => $this->service->record($form, $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, 'more than 7 days old');

    $entry = $this->service->record($form, $this->custodian, $this->supervisor);

    expect($entry->backdate_authorised_by_user_id)->toBe($this->supervisor->id);
});

it('refuses an authorisation from someone who may not give it', function () {
    $form = ($this->withdrawalForm)(['occurredAt' => '2026-09-10T08:00']);

    expect(fn () => $this->service->record($form, $this->custodian, userWithRoles(Role::Custodian)))
        ->toThrow(BackdatedEntryRefused::class, 'more than 7 days old');
});

it('lets a supervisor enter a form older than the limit, as authorised by themselves', function () {
    $entry = $this->service->record(($this->withdrawalForm)(['occurredAt' => '2026-09-10T08:00']), $this->supervisor);

    expect($entry->backdate_authorised_by_user_id)->toBe($this->supervisor->id);
});

it('follows the limit the install sets', function () {
    app(InstallSettings::class)->set('backdating.limit_days', 3, User::factory()->create());

    expect(fn () => $this->service->record(($this->withdrawalForm)(['occurredAt' => '2026-09-21T08:00']), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, 'more than 3 days old');
});

it('counts the limit in calendar days in the install time zone', function () {
    // 00:30 on 25 September in Manila is still 24 September in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Asia/Manila'));

    // 18 September in Manila is seven days back: within the limit.
    $within = $this->service->record(($this->withdrawalForm)(['occurredAt' => '2026-09-18T00:10']), $this->custodian);
    expect($within->backdate_authorised_by_user_id)->toBeNull();

    // 17 September at 23:00 in Manila is eight days back. Counted in UTC — 17 September against
    // 24 September — it would wrongly look like seven.
    expect(fn () => $this->service->record(
        ($this->withdrawalForm)(['formNo' => 'PF-00900', 'occurredAt' => '2026-09-17T23:00']),
        $this->custodian,
    ))->toThrow(BackdatedEntryRefused::class, 'more than 7 days old');
});

it('requires the person, the equipment and the purpose of a withdrawal', function (string $field, string $message) {
    expect(fn () => $this->service->record(($this->withdrawalForm)([$field => null]), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, $message);
})->with([
    ['personnelId', 'Choose the person'],
    ['equipmentId', 'Choose the equipment'],
    ['purposeId', 'Choose the purpose'],
]);

it('requires remarks when the purpose asks for them', function () {
    $other = WithdrawalPurpose::query()->where('requires_remarks', true)->orderBy('sort_order')->firstOrFail();

    expect(fn () => $this->service->record(($this->withdrawalForm)(['purposeId' => $other->id]), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, 'Remarks are required');

    expect($this->service->record(
        ($this->withdrawalForm)(['purposeId' => $other->id, 'remarks' => 'Trial fit on the spare press']),
        $this->custodian,
    )->remarks)->toBe('Trial fit on the spare press');
});

it('refuses personnel, equipment and parts that are no longer Active', function (Closure $retire, string $message) {
    $retire->call($this);

    expect(fn () => $this->service->record(($this->withdrawalForm)(), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, $message);
})->with([
    'personnel' => [function () { $this->person->forceFill(['is_active' => false])->save(); }, 'is Inactive'],
    'equipment' => [function () { $this->equipment->forceFill(['is_active' => false])->save(); }, 'is Inactive'],
    'part' => [function () { $this->part->forceFill(['is_active' => false])->save(); }, 'Spare part BRG-6204 is Inactive'],
]);

it('requires the supplier and the reference of a delivery', function (string $field, string $message) {
    $form = new PaperForm(...array_merge([
        'type' => TransactionType::Replenishment,
        'formNo' => 'PF-00813',
        'occurredAt' => '2026-09-24T21:40',
        'reason' => 'Received during the outage',
        'lines' => [new PaperFormLine($this->part->id, '6')],
        'supplier' => 'Pacific Bearings Inc.',
        'referenceNo' => 'DR-00921',
    ], [$field => ' ']));

    expect(fn () => $this->service->record($form, $this->custodian))->toThrow(BackdatedEntryRefused::class, $message);
})->with([
    ['supplier', 'Supplier is required'],
    ['referenceNo', 'reference is required'],
]);

it('requires at least one line, each a positive quantity', function () {
    expect(fn () => $this->service->record(($this->withdrawalForm)(['lines' => []]), $this->custodian))
        ->toThrow(BackdatedEntryRefused::class, 'Add at least one spare part');

    expect(fn () => $this->service->record(
        ($this->withdrawalForm)(['lines' => [new PaperFormLine($this->part->id, '0')]]),
        $this->custodian,
    ))->toThrow(BackdatedEntryRefused::class, 'is not a number greater than zero');
});

it('merges two lines for the same part into one', function () {
    $entry = $this->service->record(($this->withdrawalForm)(['lines' => [
        new PaperFormLine($this->part->id, '1'),
        new PaperFormLine($this->part->id, '2.5'),
    ]]), $this->custodian);

    expect($entry->lines()->sole()->quantity)->toBe('3.500')
        ->and(onHand($this->part))->toBe('6.500');
});

it('refuses a withdrawal the recorded balance cannot cover, says what to do, and leaves nothing behind', function () {
    $form = ($this->withdrawalForm)(['lines' => [new PaperFormLine($this->part->id, '12')]]);

    expect(fn () => $this->service->record($form, $this->custodian))
        ->toThrow(function (BackdatedEntryRefused $refused) {
            expect($refused->getMessage())
                ->toContain('Insufficient stock for BRG-6204')
                ->toContain('Unrecorded Receipt');
        });

    expect(StockTransaction::query()->where('is_backdated', true)->exists())->toBeFalse()
        ->and(onHand($this->part))->toBe('10.000');

    // The custodian's live withdrawal cart is a fresh one, not the refused form (Review Focus 3).
    $cart = app(WithdrawalService::class)->openDraft($this->custodian);

    expect($cart->is_backdated)->toBeFalse()
        ->and($cart->lines()->exists())->toBeFalse();
});

it('lets custodians and supervisors enter paper forms, and nobody else', function (Role $role, bool $allowed) {
    $record = fn () => $this->service->record(($this->withdrawalForm)(), userWithRoles($role));

    if ($allowed) {
        expect($record()->status)->toBe(TransactionStatus::Completed);
    } else {
        expect($record)->toThrow(BackdatedEntryRefused::class, 'not allowed to enter paper forms');
    }
})->with(roleMatrix('CS--'));
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Backdating/BackdatedEntryServiceTest.php
```

Expected: FAIL — `Class "App\Domain\Backdating\PaperForm" not found`.

- [ ] **Step 3: Write the form values**

Create `app/Domain/Backdating/PaperFormLine.php`:

```php
<?php

namespace App\Domain\Backdating;

/**
 * One line of a paper fallback form, as typed.
 */
final readonly class PaperFormLine
{
    public function __construct(
        public int $partId,
        public string $quantity,
        public ?string $unitCost = null,
    ) {}
}
```

Create `app/Domain/Backdating/PaperForm.php`:

```php
<?php

namespace App\Domain\Backdating;

use App\Enums\TransactionType;

/**
 * What a paper fallback form says (PRD FR-SPIMS-012, design §8.4), as the custodian typed it. Nothing
 * here is checked; BackdatedEntryService::record() checks all of it.
 */
final readonly class PaperForm
{
    /**
     * @param  string  $occurredAt  when it took place, as a datetime-local input sends it (Y-m-d\TH:i),
     *                              in the install time zone
     * @param  list<PaperFormLine>  $lines
     */
    public function __construct(
        public TransactionType $type,
        public string $formNo,
        public string $occurredAt,
        public string $reason,
        public array $lines,
        public ?int $personnelId = null,
        public ?int $equipmentId = null,
        public ?int $purposeId = null,
        public ?string $remarks = null,
        public ?string $supplier = null,
        public ?string $referenceNo = null,
    ) {}
}
```

- [ ] **Step 4: Write the refusal**

Create `app/Domain/Backdating/Exceptions/BackdatedEntryRefused.php`:

```php
<?php

namespace App\Domain\Backdating\Exceptions;

use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-012 stopped a paper form from being entered. Nothing was recorded.
 */
final class BackdatedEntryRefused extends RuntimeException
{
    public static function notPermitted(User $user): self
    {
        return new self(__(':name is not allowed to enter paper forms.', ['name' => $user->name]));
    }

    public static function formNoRequired(): self
    {
        return new self(__('Enter the paper form number.'));
    }

    public static function reasonRequired(): self
    {
        return new self(__('Enter the reason this transaction is entered late.'));
    }

    public static function occurredAtInvalid(): self
    {
        return new self(__('Enter the date and time the transaction took place.'));
    }

    public static function inFuture(): self
    {
        return new self(__('The date and time it took place cannot be in the future.'));
    }

    public static function beyondLimit(int $days): self
    {
        return new self(__('This form is more than :days days old. An Inventory Supervisor must authorise it.', ['days' => $days]));
    }

    public static function duplicateForm(string $formNo, ?string $transactionNo): self
    {
        return new self($transactionNo === null
            ? __('Paper form :form has already been entered.', ['form' => $formNo])
            : __('Paper form :form has already been entered as :number.', ['form' => $formNo, 'number' => $transactionNo]));
    }

    public static function personnelRequired(): self
    {
        return new self(__('Choose the person who signed the paper form.'));
    }

    public static function inactivePersonnel(Personnel $personnel): self
    {
        return new self(__(':name is Inactive. Reactivate them before entering this form.', ['name' => $personnel->full_name]));
    }

    public static function equipmentRequired(): self
    {
        return new self(__('Choose the equipment the parts were for.'));
    }

    public static function inactiveEquipment(Equipment $equipment): self
    {
        return new self(__('Equipment :code is Inactive. Reactivate it before entering this form.', ['code' => $equipment->code]));
    }

    public static function purposeRequired(): self
    {
        return new self(__('Choose the purpose of the withdrawal.'));
    }

    public static function inactivePurpose(WithdrawalPurpose $purpose): self
    {
        return new self(__('The purpose :purpose is Inactive. Choose another.', ['purpose' => $purpose->name]));
    }

    public static function remarksRequired(WithdrawalPurpose $purpose): self
    {
        return new self(__('Remarks are required for the purpose :purpose.', ['purpose' => $purpose->name]));
    }

    public static function supplierRequired(): self
    {
        return new self(__('Supplier is required.'));
    }

    public static function referenceRequired(): self
    {
        return new self(__('A delivery receipt or purchase order reference is required.'));
    }

    public static function noLines(): self
    {
        return new self(__('Add at least one spare part from the form.'));
    }

    public static function unknownPart(): self
    {
        return new self(__('One of the spare parts on this form no longer exists. Remove it and add it again.'));
    }

    public static function inactivePart(Part $part): self
    {
        return new self(__('Spare part :part is Inactive. Reactivate it before entering this form.', ['part' => $part->part_number]));
    }

    public static function quantityNotPositive(string $quantity): self
    {
        return new self(__('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($quantity)]));
    }

    public static function unitCostNegative(string $unitCost): self
    {
        return new self(__('A unit cost of :cost is not a number of zero or more.', ['cost' => trim($unitCost)]));
    }

    /**
     * Decision 8: the shelf had these parts when they were issued, so it is the recorded balance that
     * is short. An override cannot help — its count of the shelf comes after the parts left.
     */
    public static function exceedsRecordedBalance(InsufficientStock $shortage): self
    {
        return new self($shortage->getMessage().' '.__('The parts were on the shelf when they were issued, so the recorded balance is short. Ask an Inventory Supervisor to correct it first — by counting the part, or with an Unrecorded Receipt adjustment — then enter this form again.'));
    }
}
```

- [ ] **Step 5: Write the service**

Create `app/Domain/Backdating/BackdatedEntryService.php`:

```php
<?php

namespace App\Domain\Backdating;

use App\Domain\Backdating\Exceptions\BackdatedEntryRefused;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\IdentificationMethod;
use App\Enums\Permission;
use App\Enums\TransactionType;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use App\Support\Text;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A withdrawal or delivery that took place while SPIMS was unavailable, entered afterwards from the
 * paper fallback form (PRD FR-SPIMS-012, design §8.4). Nothing here changes a balance:
 * StockLedger::complete() does, writing the form's own time onto every movement.
 */
final readonly class BackdatedEntryService
{
    public function __construct(
        private InstallSettings $settings,
        private StockLedger $ledger,
    ) {}

    /**
     * Check the whole form, then create the transaction and post it in one database transaction.
     * StockLedger's own transaction nests inside as a savepoint, so a refusal from the ledger rolls
     * everything back and no draft is left for WithdrawalService::openDraft() to resume.
     *
     * @param  User|null  $authorisedBy  a supervisor verified at the counter in this same request, for
     *                                   a form older than the backdating limit (Plan 4 Decision 7)
     *
     * @throws BackdatedEntryRefused
     * @throws InstallNotConfigured
     * @throws StockBusy
     */
    public function record(PaperForm $form, User $custodian, ?User $authorisedBy = null): StockTransaction
    {
        if (! in_array($form->type, [TransactionType::Withdrawal, TransactionType::Replenishment], true)) {
            throw new InvalidArgumentException("A paper form records a withdrawal or a delivery, not {$form->type->value}.");
        }

        if (! $custodian->can(Permission::BackdatedRecordWithinLimit)) {
            throw BackdatedEntryRefused::notPermitted($custodian);
        }

        $formNo = Str::upper(trim($form->formNo));

        if ($formNo === '') {
            throw BackdatedEntryRefused::formNoRequired();
        }

        $reason = Text::nullIfBlank($form->reason) ?? throw BackdatedEntryRefused::reasonRequired();

        $occurredAt = $this->occurredAt($form->occurredAt);

        if ($occurredAt->isFuture()) {
            throw BackdatedEntryRefused::inFuture();
        }

        $authoriser = $this->authoriser($occurredAt, $custodian, $authorisedBy);

        $attributes = $form->type === TransactionType::Withdrawal
            ? $this->withdrawalAttributes($form)
            : $this->deliveryAttributes($form);

        $lines = $this->lines($form);

        $existing = StockTransaction::query()->where('paper_form_no', $formNo)->first();

        if ($existing instanceof StockTransaction) {
            throw BackdatedEntryRefused::duplicateForm($formNo, $existing->transaction_no);
        }

        try {
            return DB::transaction(function () use ($form, $custodian, $formNo, $reason, $occurredAt, $authoriser, $attributes, $lines): StockTransaction {
                $entry = StockTransaction::query()->create([
                    ...$attributes,
                    'type' => $form->type,
                    'custodian_user_id' => $custodian->id,
                    'occurred_at' => $occurredAt,
                    'is_backdated' => true,
                    'backdate_reason' => $reason,
                    'paper_form_no' => $formNo,
                    'backdate_authorised_by_user_id' => $authoriser?->id,
                ]);

                foreach ($lines as $index => [$part, $quantity, $unitCost]) {
                    $entry->lines()->create([
                        'line_no' => $index + 1,
                        'part_id' => $part->id,
                        'quantity' => (string) $quantity,
                        'direction' => $form->type->direction(),
                        'unit_cost' => $unitCost,
                    ]);
                }

                return $this->ledger->complete($entry, $custodian);
            });
        } catch (InsufficientStock $shortage) {
            throw BackdatedEntryRefused::exceedsRecordedBalance($shortage);
        } catch (UniqueConstraintViolationException $exception) {
            // Two custodians entering the same form at the same moment: the unique index decides.
            if (str_contains($exception->getMessage(), 'paper_form_no')) {
                throw BackdatedEntryRefused::duplicateForm($formNo, null);
            }

            throw $exception;
        }
    }

    /**
     * The instant a form's date and time stand for, in UTC. The form is read in the install time zone.
     *
     * @throws BackdatedEntryRefused
     */
    public function occurredAt(string $local): CarbonImmutable
    {
        $local = trim($local);

        // '!' zeroes every field the format does not name, so the seconds are 0 and not the clock's.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone($this->settings->timezone()));

        // A date that does not exist, such as 30 February, rolls over instead of failing, so the
        // result must read back as exactly what was typed.
        if ($parsed === false || $parsed->format('Y-m-d\TH:i') !== $local) {
            throw BackdatedEntryRefused::occurredAtInvalid();
        }

        return CarbonImmutable::instance($parsed)->utc();
    }

    /**
     * Whether the day it took place is more than backdating.limit_days calendar days before today,
     * both read in the install time zone. With a limit of 7, on the 25th the 18th is within it.
     */
    public function isBeyondLimit(CarbonImmutable $occurredAt): bool
    {
        $timezone = $this->settings->timezone();
        $earliest = CarbonImmutable::now()->setTimezone($timezone)->subDays($this->limitDays())->toDateString();

        return $occurredAt->setTimezone($timezone)->toDateString() < $earliest;
    }

    public function limitDays(): int
    {
        $days = $this->settings->get('backdating.limit_days');

        if (! is_int($days)) {
            throw new UnexpectedValueException('Install setting [backdating.limit_days] must be an integer.');
        }

        return $days;
    }

    /**
     * Decision 7: nobody within the limit. Beyond it, a supervisor entering the form authorises it
     * themselves, and a custodian needs one verified at the counter.
     *
     * @throws BackdatedEntryRefused
     */
    private function authoriser(CarbonImmutable $occurredAt, User $custodian, ?User $authorisedBy): ?User
    {
        if (! $this->isBeyondLimit($occurredAt)) {
            return null;
        }

        if ($custodian->can(Permission::BackdatedRecordBeyondLimit)) {
            return $custodian;
        }

        if ($authorisedBy instanceof User && $authorisedBy->can(Permission::BackdatedRecordBeyondLimit)) {
            return $authorisedBy;
        }

        throw BackdatedEntryRefused::beyondLimit($this->limitDays());
    }

    /**
     * Decision 9: the same Active rules as the withdrawal station.
     *
     * @return array<string, mixed>
     *
     * @throws BackdatedEntryRefused
     */
    private function withdrawalAttributes(PaperForm $form): array
    {
        $personnel = $form->personnelId === null ? null : Personnel::query()->find($form->personnelId);

        if (! $personnel instanceof Personnel) {
            throw BackdatedEntryRefused::personnelRequired();
        }

        if (! $personnel->is_active) {
            throw BackdatedEntryRefused::inactivePersonnel($personnel);
        }

        $equipment = $form->equipmentId === null ? null : Equipment::query()->find($form->equipmentId);

        if (! $equipment instanceof Equipment) {
            throw BackdatedEntryRefused::equipmentRequired();
        }

        if (! $equipment->is_active) {
            throw BackdatedEntryRefused::inactiveEquipment($equipment);
        }

        $purpose = $form->purposeId === null ? null : WithdrawalPurpose::query()->find($form->purposeId);

        if (! $purpose instanceof WithdrawalPurpose) {
            throw BackdatedEntryRefused::purposeRequired();
        }

        if (! $purpose->is_active) {
            throw BackdatedEntryRefused::inactivePurpose($purpose);
        }

        $remarks = Text::nullIfBlank($form->remarks);

        if ($purpose->requires_remarks && $remarks === null) {
            throw BackdatedEntryRefused::remarksRequired($purpose);
        }

        return [
            'personnel_id' => $personnel->id,
            'equipment_id' => $equipment->id,
            'purpose_id' => $purpose->id,
            'remarks' => $remarks,
            // FR-SPIMS-012 step 4: the person is identified from the signed form, not a badge.
            'identification_method' => IdentificationMethod::PaperForm,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BackdatedEntryRefused
     */
    private function deliveryAttributes(PaperForm $form): array
    {
        $supplier = Text::nullIfBlank($form->supplier) ?? throw BackdatedEntryRefused::supplierRequired();
        $reference = Text::nullIfBlank($form->referenceNo) ?? throw BackdatedEntryRefused::referenceRequired();

        return [
            'supplier' => $supplier,
            // Stored as ReplenishmentService stores it, so the duplicate-delivery warning sees it.
            'reference_no' => Str::upper($reference),
            'remarks' => Text::nullIfBlank($form->remarks),
        ];
    }

    /**
     * The form's lines, with two lines for the same part merged into one as the live screens merge
     * them (unique (stock_transaction_id, part_id)).
     *
     * @return list<array{0: Part, 1: BigDecimal, 2: string|null}>
     *
     * @throws BackdatedEntryRefused
     */
    private function lines(PaperForm $form): array
    {
        if ($form->lines === []) {
            throw BackdatedEntryRefused::noLines();
        }

        /** @var array<int, array{0: Part, 1: BigDecimal, 2: string|null}> $merged */
        $merged = [];

        foreach ($form->lines as $line) {
            $part = Part::query()->find($line->partId) ?? throw BackdatedEntryRefused::unknownPart();

            if (! $part->is_active) {
                throw BackdatedEntryRefused::inactivePart($part);
            }

            $quantity = $this->quantity($line->quantity);
            $unitCost = $form->type === TransactionType::Replenishment ? $this->unitCost($line->unitCost) : null;

            if (isset($merged[$part->id])) {
                $merged[$part->id] = [$part, $merged[$part->id][1]->plus($quantity), $unitCost ?? $merged[$part->id][2]];
            } else {
                $merged[$part->id] = [$part, $quantity, $unitCost];
            }
        }

        return array_values($merged);
    }

    /**
     * @throws BackdatedEntryRefused
     */
    private function quantity(string $quantity): BigDecimal
    {
        try {
            $amount = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw BackdatedEntryRefused::quantityNotPositive($quantity);
        }

        if (! $amount->isPositive()) {
            throw BackdatedEntryRefused::quantityNotPositive($quantity);
        }

        return $amount;
    }

    /**
     * @throws BackdatedEntryRefused
     */
    private function unitCost(?string $unitCost): ?string
    {
        if ($unitCost === null || trim($unitCost) === '') {
            return null;
        }

        try {
            $cost = BigDecimal::of(trim($unitCost))->toScale(4);
        } catch (MathException) {
            throw BackdatedEntryRefused::unitCostNegative($unitCost);
        }

        if ($cost->isNegative()) {
            throw BackdatedEntryRefused::unitCostNegative($unitCost);
        }

        return (string) $cost;
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Backdating/BackdatedEntryServiceTest.php
```

Expected: PASS, 32 tests. The datasets are four dates, two required fields, three withdrawal fields,
three Inactive records, two delivery fields and a role matrix of four.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Backdating/PaperForm.php app/Domain/Backdating/PaperFormLine.php app/Domain/Backdating/BackdatedEntryService.php app/Domain/Backdating/Exceptions/BackdatedEntryRefused.php tests/Feature/Backdating/BackdatedEntryServiceTest.php
git commit -m "Enter a withdrawal or delivery from a paper fallback form"
```

---

## Task 7: The paper form entry screen

**Files:**
- Create: `app/Livewire/Station/PaperFormEntry.php`,
  `resources/views/livewire/station/paper-form-entry.blade.php`
- Modify: `app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php`,
  `app/Domain/Access/Exceptions/AuthorisationRefused.php`,
  `resources/views/livewire/station/withdrawal.blade.php`,
  `resources/views/livewire/station/replenishment.blade.php`, `app/Support/Navigation.php`,
  `routes/spims.php`, `tests/Feature/Shell/AppShellTest.php`,
  `tests/Feature/Access/ScreenAccessMatrixTest.php`,
  `tests/Feature/Access/ActionReauthorizationTest.php`
- Test: `tests/Feature/Backdating/PaperFormScreenTest.php`

**Interfaces:**
- Consumes: `BackdatedEntryService::record()`, `occurredAt()`, `isBeyondLimit()`, `limitDays()`,
  `PaperForm`, `PaperFormLine` and `BackdatedEntryRefused` (Task 6);
  `CounterAuthorisation::verify(string $email, string $password, Permission $permission, string $fromAddress): User`
  and `AuthorisationRefused` (Plan 3); route `transactions.show` (Task 4).
- Produces: route `station.paper-form` (`/station/paper-form`, query `?kind=withdrawal|delivery`); the
  menu item **Paper form entry**; the Livewire actions `enterPart()`, `addPart(int)`,
  `removeLine(int)` and `confirmEntry()`.

FR-SPIMS-012's main flow on one screen (Decision 6):

1. Choose what the form records, a withdrawal or a delivery. The withdrawal station and the delivery
   screen each link here with the choice already made, which is FR-SPIMS-012 step 1.
2. Enter the form number and the date and time it took place, then the reason it is entered late.
3. For a withdrawal, the person who signed the form, the equipment, the purpose and any remarks. For a
   delivery, the supplier and the reference.
4. Add the lines.
5. Confirm.

The screen holds all of it until Confirm. Nothing is written before then, and a refusal writes
nothing either. A successful entry opens its record.

When a custodian's date is older than the limit, the form shows a supervisor email and password
(Decision 7). They are verified **inside `confirmEntry()`**, in the request that records the form, and
the password is cleared when that request ends, whatever happened. A password typed for one attempt
can never approve a different date or different lines on a later click (Review Focus 2). Blank
credentials are refused before `CounterAuthorisation` is called, so a Confirm pressed too early does
not use up one of the supervisor's five attempts.

This task also changes the delivery screen's refusal of an earlier day to name this screen, which was
Plan 3's carried note.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Backdating/PaperFormScreenTest.php`:

```php
<?php

use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Enums\Role;
use App\Livewire\Station\PaperFormEntry;
use App\Models\Equipment;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Manila'));

    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->supervisor->forceFill(['email' => 'rita@microtech.test', 'password' => Hash::make('counter-secret')])->save();
    $this->part = partWithStock('10', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing']);
    $this->person = Personnel::factory()->create(['full_name' => 'Rosalie Mendoza']);
    $this->equipment = Equipment::factory()->create();
    $this->purpose = WithdrawalPurpose::query()->where('requires_remarks', false)->orderBy('sort_order')->firstOrFail();

    // The night shift's withdrawal form, typed in.
    $this->fillWithdrawal = fn (Testable $screen): Testable => $screen
        ->set('formNo', 'PF-00812')
        ->set('occurredAt', '2026-09-23T02:15')
        ->set('reason', 'Network outage on night shift')
        ->set('personnelId', (string) $this->person->id)
        ->set('equipmentId', (string) $this->equipment->id)
        ->set('purposeId', (string) $this->purpose->id)
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '3')
        ->call('enterPart');
});

it('opens paper form entry only for custodians and supervisors', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $response = $this->actingAs($user)->get(route('station.paper-form'));

    $allowed ? $response->assertOk() : $response->assertForbidden();
    expect(in_array('Paper form entry', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('CS--'));

it('records a withdrawal from a paper form and opens its record', function () {
    $screen = ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))
        ->assertHasNoErrors()
        ->assertSee('Deep groove ball bearing')
        ->call('confirmEntry')
        ->assertHasNoErrors();

    $entry = StockTransaction::query()->where('paper_form_no', 'PF-00812')->sole();

    $screen->assertRedirect(route('transactions.show', $entry));

    expect($entry->is_backdated)->toBeTrue()
        ->and($entry->personnel_id)->toBe($this->person->id)
        ->and(onHand($this->part))->toBe('7.000');
});

it('records a delivery from a paper form', function () {
    Livewire::actingAs($this->custodian)->test(PaperFormEntry::class)
        ->set('kind', 'delivery')
        ->set('formNo', 'PF-00813')
        ->set('occurredAt', '2026-09-24T21:40')
        ->set('reason', 'Received during the outage')
        ->set('supplier', 'Pacific Bearings Inc.')
        ->set('referenceNo', 'DR-00921')
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '6')
        ->set('unitCost', '145.50')
        ->call('enterPart')
        ->call('confirmEntry')
        ->assertHasNoErrors();

    expect(StockTransaction::query()->where('paper_form_no', 'PF-00813')->sole()->transaction_no)->toStartWith('RP-')
        ->and(onHand($this->part))->toBe('16.000');
});

it('opens on a delivery when linked from the delivery screen', function () {
    $this->actingAs($this->custodian)->get(route('station.paper-form', ['kind' => 'delivery']))
        ->assertOk()
        ->assertSee(__('Supplier'))
        ->assertDontSee(__('Person who signed the form'));
});

it('asks a custodian for a supervisor when the form is older than the limit, and records it with one', function () {
    $screen = ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))
        ->set('occurredAt', '2026-09-10T08:00')
        ->assertSee(__('Supervisor password'))
        ->call('confirmEntry')
        ->assertHasErrors('supervisorPassword');

    expect(StockTransaction::query()->where('is_backdated', true)->exists())->toBeFalse();

    $screen->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        ->call('confirmEntry')
        ->assertHasNoErrors();

    expect(StockTransaction::query()->where('paper_form_no', 'PF-00812')->sole()->backdate_authorised_by_user_id)
        ->toBe($this->supervisor->id);
});

it('refuses a wrong supervisor password and records nothing', function () {
    ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))
        ->set('occurredAt', '2026-09-10T08:00')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'wrong')
        ->call('confirmEntry')
        ->assertHasErrors('supervisorPassword')
        ->assertSet('supervisorPassword', '');

    expect(StockTransaction::query()->where('is_backdated', true)->exists())->toBeFalse();
});

it('checks the supervisor password in the same request and never keeps it for another', function () {
    $screen = ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))
        ->set('occurredAt', '2026-09-10T08:00')
        ->set('supervisorEmail', 'rita@microtech.test')
        ->set('supervisorPassword', 'counter-secret')
        // Refused for something else, after the password was accepted.
        ->set('reason', '')
        ->call('confirmEntry')
        ->assertHasErrors('entry')
        ->assertSet('supervisorPassword', '');

    // The date moves further back and the reason is filled in. The earlier approval does not carry over.
    $screen->set('occurredAt', '2026-09-01T08:00')
        ->set('reason', 'Network outage')
        ->call('confirmEntry')
        ->assertHasErrors('supervisorPassword');

    expect(StockTransaction::query()->where('is_backdated', true)->exists())->toBeFalse();
});

it('lets a supervisor enter an old form without anyone else', function () {
    ($this->fillWithdrawal)(Livewire::actingAs($this->supervisor)->test(PaperFormEntry::class))
        ->set('occurredAt', '2026-09-10T08:00')
        ->assertDontSee(__('Supervisor password'))
        ->call('confirmEntry')
        ->assertHasNoErrors();

    expect(StockTransaction::query()->where('paper_form_no', 'PF-00812')->sole()->backdate_authorised_by_user_id)
        ->toBe($this->supervisor->id);
});

it('shows a refusal and records nothing when the form was already entered', function () {
    ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))->call('confirmEntry');
    $first = StockTransaction::query()->where('paper_form_no', 'PF-00812')->sole();

    ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))
        ->call('confirmEntry')
        ->assertHasErrors('entry')
        ->assertSee("has already been entered as {$first->transaction_no}");

    expect(StockTransaction::query()->where('paper_form_no', 'PF-00812')->count())->toBe(1);
});

it('says what to do when the recorded balance cannot cover the form', function () {
    ($this->fillWithdrawal)(Livewire::actingAs($this->custodian)->test(PaperFormEntry::class))
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', '9')
        ->call('enterPart')
        ->call('confirmEntry')
        ->assertHasErrors('entry')
        ->assertSee('Unrecorded Receipt');

    expect(onHand($this->part))->toBe('10.000');
});

it('adds and removes lines, and refuses a quantity that is not a number', function () {
    $other = partWithStock('5', ['part_number' => 'BLT-A42']);

    Livewire::actingAs($this->custodian)->test(PaperFormEntry::class)
        ->set('partEntry', 'BRG-6204')
        ->set('quantity', 'two')
        ->call('enterPart')
        ->assertHasErrors('partEntry')
        ->assertCount('lines', 0)
        ->set('quantity', '2')
        ->call('enterPart')
        ->set('partEntry', 'BLT-A42')
        ->call('enterPart')
        ->assertCount('lines', 2)
        ->call('removeLine', 0)
        ->assertCount('lines', 1)
        ->assertSet('lines.0.partId', $other->id);
});

it('offers the matches when a part number is not exact', function () {
    Livewire::actingAs($this->custodian)->test(PaperFormEntry::class)
        ->set('partEntry', 'BRG')
        ->call('enterPart')
        ->assertHasErrors('partEntry')
        ->assertSee('BRG-6204');
});

it('links from the withdrawal station and the delivery screen', function () {
    $this->actingAs($this->custodian)->get(route('station.withdrawal'))
        ->assertSee(route('station.paper-form', ['kind' => 'withdrawal']), false);

    $this->actingAs($this->custodian)->get(route('station.replenishment'))
        ->assertSee(route('station.paper-form', ['kind' => 'delivery']), false);
});

it('is the screen the delivery screen names for a delivery received on an earlier day', function () {
    expect(ReplenishmentRefused::receivedEarlier()->getMessage())->toContain('Paper form entry');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Backdating/PaperFormScreenTest.php
```

Expected: FAIL — `Route [station.paper-form] not defined`.

- [ ] **Step 3: Write the component**

Create `app/Livewire/Station/PaperFormEntry.php`:

```php
<?php

namespace App\Livewire\Station;

use App\Domain\Access\CounterAuthorisation;
use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Domain\Backdating\BackdatedEntryService;
use App\Domain\Backdating\Exceptions\BackdatedEntryRefused;
use App\Domain\Backdating\PaperForm;
use App\Domain\Backdating\PaperFormLine;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Parts\PartSearch;
use App\Enums\Permission;
use App\Enums\TransactionType;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A withdrawal or delivery written on the paper fallback form while SPIMS was unavailable, entered
 * afterwards (PRD FR-SPIMS-012). The screen holds the whole form; nothing is written until Confirm,
 * and then all of it or none (Plan 4 Decision 6).
 */
#[Title('Paper form entry')]
class PaperFormEntry extends Component
{
    use ActsAsSignedInUser;

    /** 'withdrawal' or 'delivery'. */
    #[Url]
    public string $kind = 'withdrawal';

    public string $formNo = '';

    /** As a datetime-local input sends it: Y-m-d\TH:i, in the install time zone. */
    public string $occurredAt = '';

    public string $reason = '';

    public string $personnelId = '';

    public string $equipmentId = '';

    public string $purposeId = '';

    public string $remarks = '';

    public string $supplier = '';

    public string $referenceNo = '';

    public string $partEntry = '';

    public string $quantity = '1';

    public string $unitCost = '';

    /** @var list<array{partId: int, quantity: string, unitCost: string}> */
    public array $lines = [];

    public string $supervisorEmail = '';

    public string $supervisorPassword = '';

    public function mount(): void
    {
        $this->authorize(Permission::BackdatedRecordWithinLimit);
    }

    public function enterPart(): void
    {
        $this->authorize(Permission::BackdatedRecordWithinLimit);
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
        $this->authorize(Permission::BackdatedRecordWithinLimit);
        $this->resetErrorBag();

        $part = Part::query()->findOrFail($partId);

        // Checked again when the form is recorded; here so the custodian hears it at once.
        try {
            $positive = BigDecimal::of(trim($this->quantity))->isPositive();
        } catch (MathException) {
            $positive = false;
        }

        if (! $positive) {
            $this->addError('partEntry', __('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($this->quantity)]));

            return;
        }

        $this->lines[] = [
            'partId' => $part->id,
            'quantity' => trim($this->quantity),
            'unitCost' => trim($this->unitCost),
        ];

        $this->partEntry = '';
        $this->quantity = '1';
        $this->unitCost = '';
        unset($this->matches, $this->lineParts);
    }

    public function removeLine(int $index): void
    {
        $this->authorize(Permission::BackdatedRecordWithinLimit);

        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        unset($this->lineParts);
    }

    public function confirmEntry(): void
    {
        $this->authorize(Permission::BackdatedRecordWithinLimit);
        $this->resetErrorBag();

        try {
            $entry = app(BackdatedEntryService::class)->record($this->form(), $this->actor(), $this->authoriser());
        } catch (AuthorisationRefused $refused) {
            $this->addError('supervisorPassword', $refused->getMessage());

            return;
        } catch (BackdatedEntryRefused|InstallNotConfigured|StockBusy $refused) {
            $this->addError('entry', $refused->getMessage());

            return;
        } finally {
            // Decision 7: a supervisor's password serves the request it was typed for, and no other.
            $this->supervisorPassword = '';
        }

        $this->redirectRoute('transactions.show', ['transaction' => $entry->id], navigate: true);
    }

    /**
     * Whether this form needs a supervisor at the counter: the signed-in user may not enter it alone,
     * and its date is older than the backdating limit.
     */
    #[Computed]
    public function needsSupervisor(): bool
    {
        if ($this->actor()->can(Permission::BackdatedRecordBeyondLimit)) {
            return false;
        }

        $service = app(BackdatedEntryService::class);

        try {
            return $service->isBeyondLimit($service->occurredAt($this->occurredAt));
        } catch (BackdatedEntryRefused) {
            // No valid date yet: the service says so when the form is recorded.
            return false;
        }
    }

    #[Computed]
    public function limitDays(): int
    {
        return app(BackdatedEntryService::class)->limitDays();
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
     * @return Collection<int, Part>
     */
    #[Computed]
    public function matches(): Collection
    {
        if (trim($this->partEntry) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->partEntry)->where('is_active', true)->limit(8)->get();
    }

    /**
     * The parts on the form, keyed by id, to show their numbers and descriptions.
     *
     * @return Collection<array-key, Part>
     */
    #[Computed]
    public function lineParts(): Collection
    {
        return Part::query()->whereIn('id', array_column($this->lines, 'partId'))->get()->keyBy('id');
    }

    /**
     * The supervisor authorising a form older than the limit, verified now, or null when none is
     * needed. Blank credentials are refused here, before CounterAuthorisation, so pressing Confirm
     * too early does not use up one of the supervisor's attempts.
     *
     * @throws AuthorisationRefused
     */
    private function authoriser(): ?User
    {
        if (! $this->needsSupervisor()) {
            return null;
        }

        if (trim($this->supervisorEmail) === '' || $this->supervisorPassword === '') {
            throw AuthorisationRefused::credentialsRequired($this->limitDays());
        }

        return app(CounterAuthorisation::class)->verify(
            $this->supervisorEmail,
            $this->supervisorPassword,
            Permission::BackdatedRecordBeyondLimit,
            (string) request()->ip(),
        );
    }

    private function form(): PaperForm
    {
        $id = fn (string $value): ?int => $value === '' ? null : (int) $value;

        return new PaperForm(
            type: $this->kind === 'delivery' ? TransactionType::Replenishment : TransactionType::Withdrawal,
            formNo: $this->formNo,
            occurredAt: $this->occurredAt,
            reason: $this->reason,
            lines: array_values(array_map(
                fn (array $line): PaperFormLine => new PaperFormLine(
                    (int) $line['partId'],
                    (string) $line['quantity'],
                    $line['unitCost'] === '' ? null : (string) $line['unitCost'],
                ),
                $this->lines,
            )),
            personnelId: $id($this->personnelId),
            equipmentId: $id($this->equipmentId),
            purposeId: $id($this->purposeId),
            remarks: $this->remarks,
            supplier: $this->supplier,
            referenceNo: $this->referenceNo,
        );
    }
}
```

In `app/Domain/Access/Exceptions/AuthorisationRefused.php`, add this named constructor after
`tooManyAttempts()`, for the blank credentials `authoriser()` refuses:

```php
    public static function credentialsRequired(int $days): self
    {
        return new self(__('This form is more than :days days old. An Inventory Supervisor must enter their email and password to authorise it.', ['days' => $days]));
    }
```

- [ ] **Step 4: Write the view**

Create `resources/views/livewire/station/paper-form-entry.blade.php`:

```blade
<section class="w-full max-w-4xl">
    <flux:heading size="xl" level="1" class="mb-2">{{ __('Paper form entry') }}</flux:heading>
    <flux:text class="mb-6">
        {{ __('Enter a withdrawal or delivery written on the paper fallback form while the system was unavailable. It is recorded as backdated, with both the time it took place and the time it was entered.') }}
    </flux:text>

    <flux:error name="entry" class="mb-4" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="kind" :label="__('The form records')" class="max-w-48">
            <flux:select.option value="withdrawal">{{ __('A withdrawal') }}</flux:select.option>
            <flux:select.option value="delivery">{{ __('A delivery') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model="formNo" :label="__('Paper form number')" class="max-w-48" autofocus />
        <flux:input wire:model.live="occurredAt" type="datetime-local" :label="__('Took place')" class="max-w-60" />
    </div>

    <flux:input wire:model="reason" :label="__('Why it is entered late')" class="mb-6" />

    @if ($kind === 'delivery')
        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input wire:model="supplier" :label="__('Supplier')" class="max-w-sm" />
            <flux:input wire:model="referenceNo" :label="__('DR or PO reference')" class="max-w-xs" />
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:select wire:model="personnelId" :label="__('Person who signed the form')" class="max-w-xs">
                <flux:select.option value="">{{ __('Choose a person') }}</flux:select.option>
                @foreach ($this->personnelOptions as $option)
                    <flux:select.option :value="$option->id">{{ $option->full_name }} ({{ $option->employee_no }})</flux:select.option>
                @endforeach
            </flux:select>
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
        </div>
    @endif

    <flux:textarea wire:model="remarks" :label="__('Remarks')" rows="2" class="mb-6" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input
            wire:model="partEntry"
            wire:keydown.enter.prevent="enterPart"
            :label="__('Part number')"
            :placeholder="__('Type, then press Enter')"
            class="max-w-sm"
        />
        <flux:input wire:model="quantity" :label="__('Quantity')" class="max-w-32" inputmode="decimal" />
        @if ($kind === 'delivery')
            <flux:input wire:model="unitCost" :label="__('Unit cost')" class="max-w-32" inputmode="decimal" />
        @endif
        <flux:button wire:click="enterPart">{{ __('Add') }}</flux:button>
    </div>

    @if ($this->matches->isNotEmpty())
        <div class="mb-6 space-y-2">
            @foreach ($this->matches as $match)
                <flux:button size="sm" wire:click="addPart({{ $match->id }})" wire:key="pf-match-{{ $match->id }}">
                    {{ $match->part_number }} — {{ $match->description }}
                </flux:button>
            @endforeach
        </div>
    @endif

    @if ($lines !== [])
        <flux:table class="mb-6">
            <flux:table.columns>
                <flux:table.column>{{ __('Part number') }}</flux:table.column>
                <flux:table.column>{{ __('Description') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Quantity') }}</flux:table.column>
                @if ($kind === 'delivery')
                    <flux:table.column align="end">{{ __('Unit cost') }}</flux:table.column>
                @endif
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($lines as $index => $line)
                    @php($linePart = $this->lineParts->get($line['partId']))
                    <flux:table.row wire:key="pf-line-{{ $index }}-{{ $line['partId'] }}">
                        <flux:table.cell>{{ $linePart?->part_number }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $linePart?->description }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $line['quantity'] }} {{ $linePart?->unit_of_measure }}</flux:table.cell>
                        @if ($kind === 'delivery')
                            <flux:table.cell align="end">{{ $line['unitCost'] }}</flux:table.cell>
                        @endif
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="subtle" wire:click="removeLine({{ $index }})">{{ __('Remove') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($this->needsSupervisor)
        <flux:callout variant="warning" icon="shield-check" class="mb-4">
            <flux:callout.heading>{{ __('This form is more than :days days old.', ['days' => $this->limitDays]) }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('An Inventory Supervisor authorises it by entering their own email and password. They are checked when you record the form, and not kept.') }}
            </flux:callout.text>
        </flux:callout>
        <div class="mb-6 flex flex-wrap items-end gap-4">
            <flux:input wire:model="supervisorEmail" type="email" :label="__('Supervisor email')" autocomplete="off" class="max-w-xs" />
            <flux:input wire:model="supervisorPassword" type="password" :label="__('Supervisor password')" autocomplete="new-password" class="max-w-xs" />
        </div>
    @endif

    <flux:button variant="primary" wire:click="confirmEntry">{{ __('Record the form') }}</flux:button>
</section>
```

- [ ] **Step 5: Link from the station screens and name this screen in the delivery refusal**

In `resources/views/livewire/station/withdrawal.blade.php`, replace:

```blade
        <flux:button icon="magnifying-glass" :href="route('station.part-lookup')" wire:navigate>
            {{ __('Part lookup') }}
        </flux:button>
```

with:

```blade
        <div class="flex gap-2">
            @can(\App\Enums\Permission::BackdatedRecordWithinLimit)
                <flux:button variant="subtle" icon="document-text" :href="route('station.paper-form', ['kind' => 'withdrawal'])" wire:navigate>
                    {{ __('Backdated entry') }}
                </flux:button>
            @endcan
            <flux:button icon="magnifying-glass" :href="route('station.part-lookup')" wire:navigate>
                {{ __('Part lookup') }}
            </flux:button>
        </div>
```

In `resources/views/livewire/station/replenishment.blade.php`, replace the first line inside the
section:

```blade
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Record a delivery') }}</flux:heading>
```

with:

```blade
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Record a delivery') }}</flux:heading>
        @can(\App\Enums\Permission::BackdatedRecordWithinLimit)
            <flux:button variant="subtle" icon="document-text" :href="route('station.paper-form', ['kind' => 'delivery'])" wire:navigate>
                {{ __('Backdated entry') }}
            </flux:button>
        @endcan
    </div>
```

In `app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php`, change the message of
`receivedEarlier()` to:

```php
        return new self(__('Record a delivery received on an earlier day as a backdated entry, on the Paper form entry screen.'));
```

`ReplenishmentServiceTest` and `ReplenishmentScreenTest` look for "backdated entry", which the new
message still contains.

- [ ] **Step 6: Add the route and the menu item**

In `routes/spims.php`, after the `station.replenishment` route:

```php
    Route::livewire('station/paper-form', Station\PaperFormEntry::class)->name('station.paper-form');
```

In `app/Support/Navigation.php`, add after `Record a delivery`:

```php
        ['group' => 'Storeroom', 'label' => 'Paper form entry', 'icon' => 'document-text', 'route' => 'station.paper-form', 'active' => 'station.paper-form', 'permission' => Permission::BackdatedRecordWithinLimit],
```

In `tests/Feature/Shell/AppShellTest.php`, the Custodian's menu becomes:

```php
        ->toBe(['Dashboard', 'Withdrawal station', 'Record a delivery', 'Paper form entry', 'Part lookup', 'Spare parts', 'Transactions'])
```

In `tests/Feature/Access/ScreenAccessMatrixTest.php`, add after `'Record a delivery'`:

```php
    'Paper form entry' => ['station.paper-form', null, 'CS--'],
```

In `tests/Feature/Access/ActionReauthorizationTest.php`, add
`use App\Livewire\Station\PaperFormEntry;` to the imports and these rows at the end of the dataset:

```php
    'Station\PaperFormEntry::enterPart' => [
        Role::Custodian, PaperFormEntry::class, fn () => [], 'enterPart', fn () => [],
    ],
    'Station\PaperFormEntry::addPart' => [
        Role::Custodian, PaperFormEntry::class, fn () => [], 'addPart', fn () => [Part::factory()->create()->id],
    ],
    'Station\PaperFormEntry::removeLine' => [
        Role::Custodian, PaperFormEntry::class, fn () => [], 'removeLine', fn () => [0],
    ],
    'Station\PaperFormEntry::confirmEntry' => [
        Role::Custodian, PaperFormEntry::class, fn () => [], 'confirmEntry', fn () => [],
    ],
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Backdating tests/Feature/Replenishments tests/Feature/Withdrawals/WithdrawalStationTest.php tests/Feature/Shell tests/Feature/Access
```

Expected: PASS, 17 new tests in `PaperFormScreenTest` (the role matrix is four), 6 new rows in
`ScreenAccessMatrixTest` and 4 in `ActionReauthorizationTest`. The replenishment and withdrawal tests
stay green.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Livewire/Station/PaperFormEntry.php resources/views/livewire/station/paper-form-entry.blade.php app/Domain/Replenishments/Exceptions/ReplenishmentRefused.php app/Domain/Access/Exceptions/AuthorisationRefused.php resources/views/livewire/station/withdrawal.blade.php resources/views/livewire/station/replenishment.blade.php app/Support/Navigation.php routes/spims.php tests/Feature/Shell/AppShellTest.php tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/Access/ActionReauthorizationTest.php tests/Feature/Backdating/PaperFormScreenTest.php
git commit -m "Add the paper form entry screen for backdated transactions"
```

---

## Task 8: `CountSessions` — open, count, review, remove, acknowledge, cancel

**Files:**
- Create: `app/Domain/Counting/CountSessions.php`, `app/Domain/Counting/CountReview.php`,
  `app/Domain/Counting/CountLineReview.php`, `app/Domain/Counting/ReasonRule.php`,
  `app/Domain/Counting/CountStaleness.php`, `app/Domain/Counting/Exceptions/CountRefused.php`,
  `app/Domain/Counting/Events/CountSessionCounted.php`
- Modify: `app/Domain/Ledger/TransactionNumberer.php`
- Test: `tests/Feature/Counting/CountSessionsTest.php`, `tests/Unit/ReasonRuleTest.php`

**Interfaces:**
- Consumes: `CountSession`, `CountLine`, `CountScope`, `CountStatus` (Task 1); `AuditLogger::record()`;
  `InstallSettings::get('counting.default_tolerance_pct')` (a float, default 0.0);
  `Permission::CountsManage` (Supervisor) and `Permission::CountsEnter` (Custodian, Supervisor);
  `Criticality`; `StorageLocation`.
- Produces:
  - `TransactionNumberer::nextCountSession(CarbonImmutable $openedAt): string`, such as `2026-0007`
    (Decision 14).
  - `CountSessions::open(User $supervisor, CountScope $scope, ?string $scopeValue = null, array $partNumbers = [], ?string $tolerancePct = null, ?string $notes = null): CountSession`
  - `CountSessions::enterCount(CountSession $session, CountLine $line, string $countedQty, User $counter): CountLine`
  - `CountSessions::markCounted(CountSession $session, User $counter): void`
  - `CountSessions::setReason(CountSession $session, CountLine $line, ?ReasonCode $reason, ?string $notes, User $supervisor): void`
  - `CountSessions::acknowledge(CountSession $session, CountLine $line, User $supervisor): void`
  - `CountSessions::removeLine(CountSession $session, CountLine $line, User $supervisor): void`
  - `CountSessions::cancel(CountSession $session, User $supervisor): void`
  - `CountSessions::review(CountSession $session): CountReview`
  - `CountReview` — `lines` (`list<CountLineReview>`, in part number order), `uncounted()`, `stale()`
    and `reasonsMissing()` (each a `list<string>` of part numbers), and `readyToPost(): bool`.
  - `CountLineReview` — `line`, `onHand`, `difference` (`?string`), `needsReason`, `stale`,
    `movedSinceOpened`.
  - `ReasonRule::requiresReason(BigDecimal $delta, BigDecimal $systemQty, BigDecimal $tolerancePct): bool`
    (design §5.6.2). Task 9 applies it again under lock.
  - `CountStaleness::staleLineIds(int $sessionId): list<int>` (Decision 13). Task 9 calls it under
    lock.
  - `App\Domain\Counting\Exceptions\CountRefused` (a `RuntimeException`) and the event
    `App\Domain\Counting\Events\CountSessionCounted` (after commit).

FR-SPIMS-013 steps 1 to 7, and every alternate flow except posting, which is Task 9.

- **Open** (Supervisor): the scope, then one statement that snapshots every part's balance into
  `count_lines`. A full, location or criticality session takes Active parts, plus Inactive parts that
  still hold stock (Decision 11). An adhoc session takes the part numbers it is given. A scope with no
  parts in it is refused, and the refusal rolls back the session and gives back its number. The
  tolerance defaults to `counting.default_tolerance_pct`.
- **Enter a count** (Custodian or Supervisor): zero or more, stamped with its time and counter. It may
  be entered again while the session is Open or Counted (Decision 15). A recount replaces any
  acknowledgement.
- **Mark counted** (Custodian or Supervisor): Open → Counted. Announces `CountSessionCounted` for
  Plan 5's discrepancy alerts.
- **Review** (read-only, for the screen): each line against the balance **now** (design §5.6.1).
  Its difference, whether it needs a reason (§5.6.2), whether it is stale (Decision 13) and the net
  movement since the session opened (FR-SPIMS-013: movements during the session "are listed on the
  posting screen").
- **Reasons, acknowledgements, removing a line and cancelling** (Supervisor). A reason of Other needs
  notes, as an adjustment's does. Only a stale line can be acknowledged, and only once the session is
  Counted. The last line cannot be removed.

Every change locks the session's row first, as `StockLedger::postCount()` will. A count entered while
a supervisor posts waits for the posting and then finds the session Posted.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ReasonRuleTest.php`:

```php
<?php

use App\Domain\Counting\ReasonRule;
use Brick\Math\BigDecimal;

it('requires a reason exactly where design §5.6.2 says', function (string $delta, string $system, string $tolerance, bool $required) {
    expect(ReasonRule::requiresReason(BigDecimal::of($delta), BigDecimal::of($system), BigDecimal::of($tolerance)))
        ->toBe($required);
})->with([
    'no difference' => ['0', '10', '0', false],
    'any difference at zero tolerance' => ['-0.001', '10', '0', true],
    'within the tolerance' => ['0.4', '10', '5', false],
    'exactly on the tolerance' => ['0.5', '10', '5', false],
    'just over the tolerance' => ['0.501', '10', '5', true],
    'a shortfall over the tolerance' => ['-2', '10', '10', true],
    'a part the system thought empty' => ['3', '0', '100', true],
    'nothing found on an empty part' => ['0', '0', '0', false],
]);
```

Create `tests/Feature/Counting/CountSessionsTest.php`:

```php
<?php

use App\Domain\Counting\CountSessions;
use App\Domain\Counting\Events\CountSessionCounted;
use App\Domain\Counting\Exceptions\CountRefused;
use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\CountScope;
use App\Enums\CountStatus;
use App\Enums\Criticality;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Manila'));

    $this->sessions = app(CountSessions::class);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->custodian = userWithRoles(Role::Custodian);
    $this->shelf = StorageLocation::factory()->create();
    $this->bearing = partWithStock('5', ['part_number' => 'BRG-6204', 'default_location_id' => $this->shelf->id, 'criticality' => Criticality::Critical]);
    $this->belt = partWithStock('12', ['part_number' => 'BLT-A42', 'criticality' => Criticality::Normal]);
    partWithStock('2', ['part_number' => 'OLD-100', 'is_active' => false]);
    partWithStock('0', ['part_number' => 'OLD-200', 'is_active' => false]);

    // A count taken in the same second as the opening balances would be stale against them
    // (Decision 13), so every test starts a minute later.
    $this->travel(1)->minutes();

    // The part numbers a session counts, each with the balance snapshotted when it opened.
    $this->snapshot = fn (CountSession $session): array => $session->lines()->with('part')->get()
        ->mapWithKeys(fn (CountLine $line): array => [$line->part->part_number => $line->system_qty])
        ->sortKeys()
        ->all();

    // A session over the bearing alone, with its count entered.
    $this->countedSession = function (string $counted = '5'): array {
        $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204']);
        $line = $this->sessions->enterCount($session, $session->lines()->sole(), $counted, $this->custodian);

        return [$session, $line];
    };

    $this->withdraw = fn (string $quantity) => app(StockLedger::class)->complete(
        StockTransaction::factory()->withLines([[$this->bearing, $quantity]])->create(),
        $this->custodian,
    );
});

it('opens a full count over every Active part and every Inactive part still holding stock', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Full);

    expect($session->status)->toBe(CountStatus::Open)
        ->and($session->scope)->toBe(CountScope::Full)
        ->and($session->scope_value)->toBeNull()
        ->and($session->opened_by_user_id)->toBe($this->supervisor->id)
        ->and(($this->snapshot)($session))->toBe(['BLT-A42' => '12.000', 'BRG-6204' => '5.000', 'OLD-100' => '2.000'])
        ->and(AuditLog::query()->where('auditable_type', 'count_session')->where('event', 'opened')->sole()->new_values['lines'])
        ->toBe(3);
});

it('opens a count of one storage location', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Location, (string) $this->shelf->id);

    expect($session->scope_value)->toBe((string) $this->shelf->id)
        ->and(($this->snapshot)($session))->toBe(['BRG-6204' => '5.000']);
});

it('opens a count of one criticality', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Criticality, 'critical');

    expect($session->scope_value)->toBe('critical')
        ->and(($this->snapshot)($session))->toBe(['BRG-6204' => '5.000']);
});

it('opens a count of chosen parts, Inactive ones included, ignoring case and repeats', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['old-200', ' BLT-A42 ', 'blt-a42']);

    expect(($this->snapshot)($session))->toBe(['BLT-A42' => '12.000', 'OLD-200' => '0.000']);
});

it('refuses chosen part numbers that do not exist, naming them', function () {
    expect(fn () => $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204', 'NOPE-1']))
        ->toThrow(CountRefused::class, 'NOPE-1');
});

it('refuses a scope with no spare parts in it, and keeps no session', function () {
    $empty = StorageLocation::factory()->create();

    expect(fn () => $this->sessions->open($this->supervisor, CountScope::Location, (string) $empty->id))
        ->toThrow(CountRefused::class, 'No spare parts');

    expect(CountSession::query()->exists())->toBeFalse();
});

it('refuses a location or criticality that does not exist', function (CountScope $scope, string $value, string $message) {
    expect(fn () => $this->sessions->open($this->supervisor, $scope, $value))->toThrow(CountRefused::class, $message);
})->with([
    'no such location' => [CountScope::Location, '999999', 'storage location'],
    'not a location id' => [CountScope::Location, 'A-01', 'storage location'],
    'no such criticality' => [CountScope::Criticality, 'urgent', 'criticality'],
]);

it('takes the tolerance from the install unless the supervisor sets one', function () {
    app(InstallSettings::class)->set('counting.default_tolerance_pct', '2.5', User::factory()->create());

    expect($this->sessions->open($this->supervisor, CountScope::Full)->variance_tolerance_pct)->toBe('2.500')
        ->and($this->sessions->open($this->supervisor, CountScope::Full, tolerancePct: '5')->variance_tolerance_pct)->toBe('5.000');
});

it('refuses a tolerance outside 0 to 100', function (string $tolerance) {
    expect(fn () => $this->sessions->open($this->supervisor, CountScope::Full, tolerancePct: $tolerance))
        ->toThrow(CountRefused::class, 'tolerance');
})->with(['-1', '100.5', 'abc']);

it('numbers sessions in sequence within the year without using up a transaction number', function () {
    $first = $this->sessions->open($this->supervisor, CountScope::Full);
    $second = $this->sessions->open($this->supervisor, CountScope::Full);

    expect([$first->session_no, $second->session_no])->toBe(['2026-0001', '2026-0002'])
        ->and(StockTransaction::query()->whereNotNull('transaction_no')->orderByDesc('id')->value('transaction_no'))
        ->toBe('OP-2026-000003');
});

it('stamps a count with its time and the person who counted it, and audits it', function () {
    [, $line] = ($this->countedSession)('4.5');

    expect($line->counted_qty)->toBe('4.500')
        ->and($line->counted_by_user_id)->toBe($this->custodian->id)
        ->and($line->counted_at?->equalTo(CarbonImmutable::now()))->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', 'count_line')->where('event', 'count_entered')->sole()->new_values)
        ->toBe(['counted_qty' => '4.500']);
});

it('accepts a count of zero', function () {
    [, $line] = ($this->countedSession)('0');

    expect($line->counted_qty)->toBe('0.000');
});

it('refuses a counted quantity that is negative or not a number', function (string $counted) {
    $session = $this->sessions->open($this->supervisor, CountScope::Full);

    expect(fn () => $this->sessions->enterCount($session, $session->lines()->firstOrFail(), $counted, $this->custodian))
        ->toThrow(CountRefused::class, 'is not a number of zero or more');
})->with(['-1', 'five', '', '1.2345']);

it('refuses a line that belongs to another session', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Full);
    $other = $this->sessions->open($this->supervisor, CountScope::Full);

    expect(fn () => $this->sessions->enterCount($session, $other->lines()->firstOrFail(), '1', $this->custodian))
        ->toThrow(CountRefused::class, 'not part of this count');
});

it('marks a session counted and announces it once it commits', function () {
    Event::fake([CountSessionCounted::class]);
    [$session] = ($this->countedSession)();

    $this->sessions->markCounted($session, $this->custodian);

    expect($session->refresh()->status)->toBe(CountStatus::Counted)
        ->and($session->marked_counted_by_user_id)->toBe($this->custodian->id);

    Event::assertDispatched(CountSessionCounted::class, fn (CountSessionCounted $event): bool => $event->session->is($session));

    expect(fn () => $this->sessions->markCounted($session, $this->custodian))
        ->toThrow(CountRefused::class, 'already marked counted');
});

it('still takes a recount after the session is marked counted', function () {
    [$session, $line] = ($this->countedSession)('5');
    $this->sessions->markCounted($session, $this->custodian);

    expect($this->sessions->enterCount($session, $line, '4', $this->custodian)->counted_qty)->toBe('4.000');
});

it('requires notes when the reason is Other, and stores a reason', function () {
    [$session, $line] = ($this->countedSession)('3');

    expect(fn () => $this->sessions->setReason($session, $line, ReasonCode::Other, ' ', $this->supervisor))
        ->toThrow(CountRefused::class, 'Notes are required');

    $this->sessions->setReason($session, $line, ReasonCode::Damage, 'Crushed box', $this->supervisor);

    expect($line->refresh()->reason_code)->toBe(ReasonCode::Damage)
        ->and($line->notes)->toBe('Crushed box');
});

it('flags a count as stale when stock moves after it, even in the same second', function () {
    [$session] = ($this->countedSession)('5');

    // Recorded in the same second as the count: it counts as after it (Decision 13).
    ($this->withdraw)('1');

    expect($this->sessions->review($session)->stale())->toBe(['BRG-6204']);
});

it('does not flag a movement recorded before the count', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204']);
    ($this->withdraw)('1');
    $this->travel(1)->seconds();

    $this->sessions->enterCount($session, $session->lines()->sole(), '4', $this->custodian);

    expect($this->sessions->review($session)->stale())->toBe([]);
});

it('clears staleness with an acknowledgement, until stock moves again', function () {
    [$session, $line] = ($this->countedSession)('5');
    $this->travel(1)->seconds();
    ($this->withdraw)('1');
    $this->sessions->markCounted($session, $this->custodian);
    $this->travel(1)->seconds();

    $this->sessions->acknowledge($session, $line, $this->supervisor);

    expect($this->sessions->review($session)->stale())->toBe([])
        ->and($line->refresh()->acknowledged_by_user_id)->toBe($this->supervisor->id);

    $this->travel(1)->seconds();
    ($this->withdraw)('1');

    expect($this->sessions->review($session)->stale())->toBe(['BRG-6204']);
});

it('replaces an acknowledgement with a recount', function () {
    [$session, $line] = ($this->countedSession)('5');
    $this->travel(1)->seconds();
    ($this->withdraw)('1');
    $this->sessions->markCounted($session, $this->custodian);
    $this->sessions->acknowledge($session, $line, $this->supervisor);
    $this->travel(1)->seconds();

    $recounted = $this->sessions->enterCount($session, $line, '4', $this->custodian);

    expect($recounted->acknowledged_at)->toBeNull()
        ->and($this->sessions->review($session)->stale())->toBe([]);
});

it('refuses to acknowledge a count that is not stale, or before counting is finished', function () {
    [$session, $line] = ($this->countedSession)('5');

    expect(fn () => $this->sessions->acknowledge($session, $line, $this->supervisor))
        ->toThrow(CountRefused::class, 'still being counted');

    $this->sessions->markCounted($session, $this->custodian);

    expect(fn () => $this->sessions->acknowledge($session, $line, $this->supervisor))
        ->toThrow(CountRefused::class, 'is not stale');
});

it('reviews each line against the balance now, not the snapshot', function () {
    // Opened at 5. Three are issued during the session, before the shelf is counted, and the shelf
    // then holds 2: nothing is missing (design §5.6.1).
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204']);
    $this->travel(1)->seconds();
    ($this->withdraw)('3');
    $this->travel(1)->seconds();
    $this->sessions->enterCount($session, $session->lines()->sole(), '2', $this->custodian);

    $review = $this->sessions->review($session)->lines[0];

    expect($review->onHand)->toBe('2.000')
        ->and($review->difference)->toBe('0.000')
        ->and($review->needsReason)->toBeFalse()
        ->and($review->stale)->toBeFalse()
        ->and($review->movedSinceOpened)->toBe('-3.000')
        ->and($review->line->snapshot_variance)->toBe('-3.000');
});

it('asks for a reason where the difference exceeds the tolerance or the part was thought empty', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BLT-A42', 'OLD-200'], tolerancePct: '10');
    $belt = $session->lines()->where('part_id', $this->belt->id)->sole();
    $empty = $session->lines()->whereKeyNot($belt->id)->sole();

    // 1 of 12 is 8.3%, within 10%. Anything found on a part the system thought empty needs a reason.
    $this->sessions->enterCount($session, $belt, '11', $this->custodian);
    $this->sessions->enterCount($session, $empty, '1', $this->custodian);

    expect($this->sessions->review($session)->reasonsMissing())->toBe(['OLD-200']);

    // 2 of 12 is 16.7%.
    $this->sessions->enterCount($session, $belt, '10', $this->custodian);

    expect($this->sessions->review($session)->reasonsMissing())->toBe(['BLT-A42', 'OLD-200']);

    $this->sessions->setReason($session, $belt, ReasonCode::Shrinkage, null, $this->supervisor);

    expect($this->sessions->review($session)->reasonsMissing())->toBe(['OLD-200']);
});

it('lists the parts not yet counted, and is ready to post only when nothing blocks it', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204', 'BLT-A42']);
    $this->sessions->enterCount($session, $session->lines()->where('part_id', $this->bearing->id)->sole(), '5', $this->custodian);

    $review = $this->sessions->review($session);

    expect($review->uncounted())->toBe(['BLT-A42'])
        ->and($review->readyToPost())->toBeFalse();

    $this->sessions->enterCount($session, $session->lines()->where('part_id', $this->belt->id)->sole(), '12', $this->custodian);

    expect($this->sessions->review($session)->readyToPost())->toBeTrue();
});

it('removes a line and audits it, but never the last one', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204', 'BLT-A42']);

    $this->sessions->removeLine($session, $session->lines()->where('part_id', $this->belt->id)->sole(), $this->supervisor);

    expect($session->lines()->count())->toBe(1)
        ->and(AuditLog::query()->where('auditable_type', 'count_session')->where('event', 'line_removed')->sole()->old_values)
        ->toBe(['part_number' => 'BLT-A42']);

    expect(fn () => $this->sessions->removeLine($session, $session->lines()->sole(), $this->supervisor))
        ->toThrow(CountRefused::class, 'Cancel the session instead');
});

it('cancels a session, after which nothing more can be done to it', function () {
    [$session, $line] = ($this->countedSession)('5');

    $this->sessions->cancel($session, $this->supervisor);

    expect($session->refresh()->status)->toBe(CountStatus::Cancelled)
        ->and($session->cancelled_by_user_id)->toBe($this->supervisor->id);

    expect(fn () => $this->sessions->enterCount($session, $line, '4', $this->custodian))->toThrow(CountRefused::class, 'was cancelled')
        ->and(fn () => $this->sessions->markCounted($session, $this->custodian))->toThrow(CountRefused::class, 'was cancelled')
        ->and(fn () => $this->sessions->cancel($session, $this->supervisor))->toThrow(CountRefused::class, 'was cancelled');
});

it('lets only an Inventory Supervisor open, give reasons, remove lines and cancel', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $open = fn () => $this->sessions->open($user, CountScope::Full);

    if ($allowed) {
        expect($open()->status)->toBe(CountStatus::Open);

        return;
    }

    $session = $this->sessions->open($this->supervisor, CountScope::Full);
    $line = $session->lines()->firstOrFail();

    expect($open)->toThrow(CountRefused::class, 'not allowed')
        ->and(fn () => $this->sessions->setReason($session, $line, ReasonCode::Miscount, null, $user))->toThrow(CountRefused::class, 'not allowed')
        ->and(fn () => $this->sessions->removeLine($session, $line, $user))->toThrow(CountRefused::class, 'not allowed')
        ->and(fn () => $this->sessions->acknowledge($session, $line, $user))->toThrow(CountRefused::class, 'not allowed')
        ->and(fn () => $this->sessions->cancel($session, $user))->toThrow(CountRefused::class, 'not allowed');
})->with(roleMatrix('-S--'));

it('lets custodians and supervisors enter counts and mark them counted, and nobody else', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $session = $this->sessions->open($this->supervisor, CountScope::Full);
    $enter = fn () => $this->sessions->enterCount($session, $session->lines()->firstOrFail(), '1', $user);
    $mark = fn () => $this->sessions->markCounted($session, $user);

    if ($allowed) {
        $enter();
        $mark();

        expect($session->refresh()->status)->toBe(CountStatus::Counted);
    } else {
        expect($enter)->toThrow(CountRefused::class, 'not allowed')
            ->and($mark)->toThrow(CountRefused::class, 'not allowed');
    }
})->with(roleMatrix('CS--'));
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/ReasonRuleTest.php tests/Feature/Counting/CountSessionsTest.php
```

Expected: FAIL — `Class "App\Domain\Counting\ReasonRule" not found`.

- [ ] **Step 3: Give count sessions their own numbers**

In `app/Domain/Ledger/TransactionNumberer.php`, replace `next()` with the three methods below, and add
the constant at the top of the class body. The message of the guard keeps the words
`TransactionNumbererGuardTest` looks for:

```php
    /**
     * The sequence count session numbers come from. A transaction prefix is letters only, so this
     * key can never be one, and a count never uses up a transaction number (Plan 4 Decision 14).
     */
    private const string COUNT_SESSION_SEQUENCE = 'count-session';

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
        $prefix = $this->settings->transactionPrefix($type);
        $year = $recordedAt->setTimezone($this->settings->timezone())->year;

        return sprintf('%s-%d-%06d', $prefix, $year, $this->advance($prefix, $year));
    }

    /**
     * The next count session number, e.g. 2026-0007, by the year in the install's time zone.
     */
    public function nextCountSession(CarbonImmutable $openedAt): string
    {
        $year = $openedAt->setTimezone($this->settings->timezone())->year;

        return sprintf('%d-%04d', $year, $this->advance(self::COUNT_SESSION_SEQUENCE, $year));
    }

    private function advance(string $sequence, int $year): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Numbers can only be issued inside the posting database transaction.');
        }

        return (int) DB::scalar(
            'insert into transaction_number_sequences (prefix, year, last_value) values (?, ?, 1)
             on conflict (prefix, year) do update set last_value = transaction_number_sequences.last_value + 1
             returning last_value',
            [$sequence, $year],
            false,
        );
    }
```

- [ ] **Step 4: Write the rule, the staleness query and the review values**

Create `app/Domain/Counting/ReasonRule.php`:

```php
<?php

namespace App\Domain\Counting;

use Brick\Math\BigDecimal;

/**
 * Design §5.6.2, PRD FR-SPIMS-013: a count difference needs a reason when it is not zero and either
 * the part's balance was zero when the session opened, or the difference as a percentage of that
 * balance exceeds the session's tolerance.
 */
final class ReasonRule
{
    public static function requiresReason(BigDecimal $delta, BigDecimal $systemQty, BigDecimal $tolerancePct): bool
    {
        if ($delta->isZero()) {
            return false;
        }

        if ($systemQty->isZero()) {
            return true;
        }

        // |delta| / system × 100 > tolerance, multiplied out so nothing divides.
        return $delta->abs()->multipliedBy(100)->isGreaterThan($tolerancePct->multipliedBy($systemQty));
    }
}
```

Create `app/Domain/Counting/CountStaleness.php`:

```php
<?php

namespace App\Domain\Counting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Plan 4 Decision 13: a count is stale when stock of its part was recorded moving at or after the
 * count, and after any acknowledgement of that. recorded_at, not occurred_at: a backdated entry or a
 * reversal carries a past occurred_at that the count never saw. Timestamps are stored to the second,
 * so a movement in the same second as the count counts as after it.
 */
final class CountStaleness
{
    /**
     * @return list<int> the stale lines' ids; an uncounted line is never stale, only uncounted
     */
    public function staleLineIds(int $sessionId): array
    {
        $ids = DB::table('count_lines as l')
            ->where('l.count_session_id', $sessionId)
            ->whereNotNull('l.counted_at')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('stock_movements as m')
                ->whereColumn('m.part_id', 'l.part_id')
                ->whereColumn('m.recorded_at', '>=', 'l.counted_at')
                ->where(fn (Builder $query) => $query
                    ->whereNull('l.acknowledged_at')
                    ->orWhereColumn('m.recorded_at', '>=', 'l.acknowledged_at')))
            ->orderBy('l.id')
            ->pluck('l.id');

        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids->all()));
    }
}
```

Create `app/Domain/Counting/CountLineReview.php`:

```php
<?php

namespace App\Domain\Counting;

use App\Models\CountLine;

/**
 * One line of a count as the review screen shows it, against the balance now. Read without a lock:
 * StockLedger::postCount() decides everything again under lock.
 */
final readonly class CountLineReview
{
    /**
     * @param  string  $onHand  the balance now
     * @param  string|null  $difference  counted minus the balance now; null until counted
     * @param  string  $movedSinceOpened  the net of every movement recorded since the session opened
     */
    public function __construct(
        public CountLine $line,
        public string $onHand,
        public ?string $difference,
        public bool $needsReason,
        public bool $stale,
        public string $movedSinceOpened,
    ) {}

    public function reasonMissing(): bool
    {
        return $this->needsReason && $this->line->reason_code === null;
    }
}
```

Create `app/Domain/Counting/CountReview.php`:

```php
<?php

namespace App\Domain\Counting;

/**
 * A count session's lines under review (PRD FR-SPIMS-013 step 7), in part number order, and what
 * still stops it from being posted.
 */
final readonly class CountReview
{
    /**
     * @param  list<CountLineReview>  $lines
     */
    public function __construct(public array $lines) {}

    /**
     * @return list<string>
     */
    public function uncounted(): array
    {
        return $this->partNumbers(fn (CountLineReview $review): bool => $review->line->counted_qty === null);
    }

    /**
     * @return list<string>
     */
    public function stale(): array
    {
        return $this->partNumbers(fn (CountLineReview $review): bool => $review->stale);
    }

    /**
     * @return list<string>
     */
    public function reasonsMissing(): array
    {
        return $this->partNumbers(fn (CountLineReview $review): bool => $review->reasonMissing());
    }

    public function readyToPost(): bool
    {
        return $this->uncounted() === [] && $this->stale() === [] && $this->reasonsMissing() === [];
    }

    /**
     * @param  callable(CountLineReview): bool  $matches
     * @return list<string>
     */
    private function partNumbers(callable $matches): array
    {
        return array_values(array_map(
            fn (CountLineReview $review): string => $review->line->part->part_number,
            array_filter($this->lines, $matches),
        ));
    }
}
```

- [ ] **Step 5: Write the refusal and the event**

Create `app/Domain/Counting/Exceptions/CountRefused.php`:

```php
<?php

namespace App\Domain\Counting\Exceptions;

use App\Enums\CountStatus;
use App\Models\CountSession;
use App\Models\Part;
use App\Models\User;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-013 stopped a change to a count session. Nothing was changed.
 */
final class CountRefused extends RuntimeException
{
    public static function notPermitted(User $user): self
    {
        return new self(__(':name is not allowed to do this to a stock count.', ['name' => $user->name]));
    }

    public static function nothingInScope(): self
    {
        return new self(__('No spare parts are in that scope, so there is nothing to count.'));
    }

    public static function unknownLocation(): self
    {
        return new self(__('Choose the storage location to count.'));
    }

    public static function unknownCriticality(string $value): self
    {
        return new self(__('":value" is not a criticality. Choose Critical, Important or Normal.', ['value' => $value]));
    }

    public static function partNumbersRequired(): self
    {
        return new self(__('List the part numbers to count.'));
    }

    /**
     * @param  list<string>  $partNumbers
     */
    public static function unknownParts(array $partNumbers): self
    {
        return new self(__('No spare part has the number :numbers.', ['numbers' => implode(', ', $partNumbers)]));
    }

    public static function toleranceInvalid(string $tolerance): self
    {
        return new self(__('A tolerance of :tolerance is not a percentage from 0 to 100.', ['tolerance' => trim($tolerance)]));
    }

    public static function countedQuantityInvalid(string $quantity): self
    {
        return new self(__('Counted quantity :quantity is not a number of zero or more.', ['quantity' => trim($quantity)]));
    }

    public static function notAllowedNow(CountSession $session): self
    {
        return new self(match ($session->status) {
            CountStatus::Posted => __('Count :number is already posted.', ['number' => $session->session_no]),
            CountStatus::Cancelled => __('Count :number was cancelled.', ['number' => $session->session_no]),
            CountStatus::Counted => __('Count :number is already marked counted.', ['number' => $session->session_no]),
            CountStatus::Open => __('Count :number is still being counted. Mark it counted first.', ['number' => $session->session_no]),
        });
    }

    public static function lineNotInSession(): self
    {
        return new self(__('That spare part is not part of this count.'));
    }

    public static function notesRequired(): self
    {
        return new self(__('Notes are required when the reason is Other.'));
    }

    public static function notStale(Part $part): self
    {
        return new self(__('The count of :part is not stale, so there is nothing to acknowledge.', ['part' => $part->part_number]));
    }

    public static function lastLine(): self
    {
        return new self(__('The last spare part cannot be removed from a count. Cancel the session instead.'));
    }
}
```

Create `app/Domain/Counting/Events/CountSessionCounted.php`:

```php
<?php

namespace App\Domain\Counting\Events;

use App\Models\CountSession;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Counting is finished and the differences are under review (PRD FR-SPIMS-013 step 5). Plan 5 opens
 * an Inventory Discrepancy alert for each difference above the tolerance (step 6).
 */
final readonly class CountSessionCounted implements ShouldDispatchAfterCommit
{
    public function __construct(public CountSession $session) {}
}
```

- [ ] **Step 6: Write the service**

Create `app/Domain/Counting/CountSessions.php`:

```php
<?php

namespace App\Domain\Counting;

use App\Domain\Audit\AuditLogger;
use App\Domain\Counting\Events\CountSessionCounted;
use App\Domain\Counting\Exceptions\CountRefused;
use App\Domain\Ledger\TransactionNumberer;
use App\Domain\Settings\InstallSettings;
use App\Enums\CountScope;
use App\Enums\CountStatus;
use App\Enums\Criticality;
use App\Enums\Permission;
use App\Enums\ReasonCode;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\User;
use App\Support\Text;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * A physical count from opening to review (PRD FR-SPIMS-013, design §5.6). Posting is
 * StockLedger::postCount(): nothing here changes a stock balance.
 */
final readonly class CountSessions
{
    public function __construct(
        private AuditLogger $audit,
        private InstallSettings $settings,
        private TransactionNumberer $numberer,
        private CountStaleness $staleness,
    ) {}

    /**
     * FR-SPIMS-013 steps 1 and 2: open a session over a scope and snapshot each part's balance, in
     * one statement however many parts there are.
     *
     * @param  string|null  $scopeValue  a storage location id for Location, a Criticality value for
     *                                   Criticality; ignored for the others
     * @param  list<string>  $partNumbers  the parts of an Adhoc session
     * @param  string|null  $tolerancePct  null or blank takes counting.default_tolerance_pct
     *
     * @throws CountRefused
     */
    public function open(
        User $supervisor,
        CountScope $scope,
        ?string $scopeValue = null,
        array $partNumbers = [],
        ?string $tolerancePct = null,
        ?string $notes = null,
    ): CountSession {
        $this->guardManager($supervisor);

        $tolerance = $this->tolerance($tolerancePct);
        [$parts, $storedScopeValue] = $this->scope($scope, $scopeValue, $partNumbers);

        return DB::transaction(function () use ($supervisor, $scope, $storedScopeValue, $tolerance, $notes, $parts): CountSession {
            $openedAt = CarbonImmutable::now();

            $session = CountSession::query()->create([
                'session_no' => $this->numberer->nextCountSession($openedAt),
                'scope' => $scope,
                'scope_value' => $storedScopeValue,
                'variance_tolerance_pct' => (string) $tolerance,
                'notes' => Text::nullIfBlank($notes),
                'opened_by_user_id' => $supervisor->id,
                'opened_at' => $openedAt,
            ]);

            $lines = DB::table('count_lines')->insertUsing(
                ['count_session_id', 'part_id', 'system_qty'],
                $parts->toBase()->selectRaw('cast(? as bigint), parts.id, part_stock.on_hand', [$session->id]),
            );

            // Rolls back the session and gives its number back.
            if ($lines === 0) {
                throw CountRefused::nothingInScope();
            }

            $this->audit->record('opened', $session, null, [
                'lines' => $lines,
                'scope' => $scope->value,
                'scope_value' => $storedScopeValue,
                'variance_tolerance_pct' => (string) $tolerance,
            ], $supervisor);

            return $session->refresh();
        });
    }

    /**
     * FR-SPIMS-013 steps 3 and 4. A count may be entered again, a recount, until the session is
     * posted or cancelled; a recount replaces any acknowledgement of the old one (Decision 13).
     *
     * @throws CountRefused
     */
    public function enterCount(CountSession $session, CountLine $line, string $countedQty, User $counter): CountLine
    {
        if (! $counter->can(Permission::CountsEnter)) {
            throw CountRefused::notPermitted($counter);
        }

        $quantity = $this->countedQuantity($countedQty);

        return $this->change($session, [CountStatus::Open, CountStatus::Counted], function (CountSession $session) use ($line, $quantity, $counter): CountLine {
            $line = $this->lineOf($session, $line);
            $old = $line->counted_qty;

            $line->forceFill([
                'counted_qty' => (string) $quantity,
                'counted_at' => CarbonImmutable::now(),
                'counted_by_user_id' => $counter->id,
                'acknowledged_at' => null,
                'acknowledged_by_user_id' => null,
            ])->save();

            $this->audit->record('count_entered', $line, ['counted_qty' => $old], ['counted_qty' => (string) $quantity], $counter);

            return $line->refresh();
        });
    }

    /**
     * FR-SPIMS-013 step 5.
     *
     * @throws CountRefused
     */
    public function markCounted(CountSession $session, User $counter): void
    {
        if (! $counter->can(Permission::CountsEnter)) {
            throw CountRefused::notPermitted($counter);
        }

        $this->change($session, [CountStatus::Open], function (CountSession $session) use ($counter): void {
            $session->forceFill([
                'status' => CountStatus::Counted,
                'marked_counted_by_user_id' => $counter->id,
                'marked_counted_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record(
                'marked_counted',
                $session,
                ['status' => CountStatus::Open->value],
                ['status' => CountStatus::Counted->value],
                $counter,
            );
        });

        event(new CountSessionCounted($session));
    }

    /**
     * FR-SPIMS-013 step 7. A reason may be given to any line, needed or not; posting requires one
     * wherever ReasonRule does.
     *
     * @throws CountRefused
     */
    public function setReason(CountSession $session, CountLine $line, ?ReasonCode $reason, ?string $notes, User $supervisor): void
    {
        $this->guardManager($supervisor);

        $notes = Text::nullIfBlank($notes);

        if ($reason === ReasonCode::Other && $notes === null) {
            throw CountRefused::notesRequired();
        }

        $this->change($session, [CountStatus::Open, CountStatus::Counted], function (CountSession $session) use ($line, $reason, $notes, $supervisor): void {
            $line = $this->lineOf($session, $line);
            $old = ['notes' => $line->notes, 'reason_code' => $line->reason_code?->value];

            $line->forceFill(['reason_code' => $reason, 'notes' => $notes])->save();

            $this->audit->record('reason_set', $line, $old, ['notes' => $notes, 'reason_code' => $reason?->value], $supervisor);
        });
    }

    /**
     * FR-SPIMS-013 alternate flow: stock moved after the count, and the supervisor confirms the count
     * still stands. Covers the movements recorded before now; a later one makes it stale again.
     *
     * @throws CountRefused
     */
    public function acknowledge(CountSession $session, CountLine $line, User $supervisor): void
    {
        $this->guardManager($supervisor);

        $this->change($session, [CountStatus::Counted], function (CountSession $session) use ($line, $supervisor): void {
            $line = $this->lineOf($session, $line);

            if (! in_array($line->id, $this->staleness->staleLineIds($session->id), true)) {
                throw CountRefused::notStale($line->part);
            }

            $line->forceFill([
                'acknowledged_at' => CarbonImmutable::now(),
                'acknowledged_by_user_id' => $supervisor->id,
            ])->save();

            $this->audit->record('stale_acknowledged', $line, null, ['counted_qty' => $line->counted_qty], $supervisor);
        });
    }

    /**
     * FR-SPIMS-013 alternate flow: a part that will not be counted is removed from the session.
     *
     * @throws CountRefused
     */
    public function removeLine(CountSession $session, CountLine $line, User $supervisor): void
    {
        $this->guardManager($supervisor);

        $this->change($session, [CountStatus::Open, CountStatus::Counted], function (CountSession $session) use ($line, $supervisor): void {
            $line = $this->lineOf($session, $line);

            if ($session->lines()->count() === 1) {
                throw CountRefused::lastLine();
            }

            $partNumber = $line->part->part_number;
            $line->delete();

            $this->audit->record('line_removed', $session, ['part_number' => $partNumber], null, $supervisor);
        });
    }

    /**
     * FR-SPIMS-013 alternate flow: no stock movement, and the session is kept as Cancelled.
     *
     * @throws CountRefused
     */
    public function cancel(CountSession $session, User $supervisor): void
    {
        $this->guardManager($supervisor);

        $this->change($session, [CountStatus::Open, CountStatus::Counted], function (CountSession $session) use ($supervisor): void {
            $old = $session->status;

            $session->forceFill([
                'status' => CountStatus::Cancelled,
                'cancelled_by_user_id' => $supervisor->id,
                'cancelled_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record('cancelled', $session, ['status' => $old->value], ['status' => CountStatus::Cancelled->value], $supervisor);
        });
    }

    /**
     * FR-SPIMS-013 step 7: every line against the balance now, for the screen to show.
     */
    public function review(CountSession $session): CountReview
    {
        $stale = $this->staleness->staleLineIds($session->id);
        $tolerance = BigDecimal::of($session->variance_tolerance_pct);

        // FR-SPIMS-013: withdrawals and deliveries during the session are listed on the posting screen.
        $moved = StockMovement::query()
            ->whereIn('part_id', CountLine::query()->select('part_id')->where('count_session_id', $session->id))
            ->where('recorded_at', '>=', $session->opened_at)
            ->groupBy('part_id')
            ->selectRaw('part_id, sum(quantity) as net')
            ->pluck('net', 'part_id');

        $lines = $session->lines()->with(['part.stock', 'part.defaultLocation'])->get()
            ->sortBy(fn (CountLine $line): string => $line->part->part_number)
            ->map(function (CountLine $line) use ($stale, $tolerance, $moved): CountLineReview {
                $stock = $line->part->stock;
                $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');
                $difference = $line->counted_qty === null ? null : BigDecimal::of($line->counted_qty)->minus($onHand);

                return new CountLineReview(
                    line: $line,
                    onHand: (string) $onHand,
                    difference: $difference === null ? null : (string) $difference,
                    needsReason: $difference !== null
                        && ReasonRule::requiresReason($difference, BigDecimal::of($line->system_qty), $tolerance),
                    stale: in_array($line->id, $stale, true),
                    movedSinceOpened: (string) BigDecimal::of((string) ($moved[$line->part_id] ?? '0'))->toScale(3),
                );
            })
            ->all();

        return new CountReview(array_values($lines));
    }

    /**
     * @throws CountRefused
     */
    private function guardManager(User $user): void
    {
        if (! $user->can(Permission::CountsManage)) {
            throw CountRefused::notPermitted($user);
        }
    }

    /**
     * @throws CountRefused
     */
    private function tolerance(?string $tolerancePct): BigDecimal
    {
        if ($tolerancePct === null || trim($tolerancePct) === '') {
            $default = $this->settings->get('counting.default_tolerance_pct');

            if (! is_int($default) && ! is_float($default)) {
                throw new UnexpectedValueException('Install setting [counting.default_tolerance_pct] must be a number.');
            }

            $tolerancePct = (string) $default;
        }

        try {
            $tolerance = BigDecimal::of(trim($tolerancePct))->toScale(3);
        } catch (MathException) {
            throw CountRefused::toleranceInvalid($tolerancePct);
        }

        if ($tolerance->isNegative() || $tolerance->isGreaterThan(100)) {
            throw CountRefused::toleranceInvalid($tolerancePct);
        }

        return $tolerance;
    }

    /**
     * The parts a scope covers, joined to their stock rows for the snapshot, and the scope value to
     * store.
     *
     * @param  list<string>  $partNumbers
     * @return array{0: Builder<Part>, 1: string|null}
     *
     * @throws CountRefused
     */
    private function scope(CountScope $scope, ?string $scopeValue, array $partNumbers): array
    {
        $parts = Part::query()->join('part_stock', 'part_stock.part_id', '=', 'parts.id');

        return match ($scope) {
            CountScope::Full => [$this->activeOrHeld($parts), null],
            CountScope::Location => $this->locationScope($parts, $scopeValue),
            CountScope::Criticality => $this->criticalityScope($parts, $scopeValue),
            CountScope::Adhoc => [$parts->whereIn('parts.id', $this->partIds($partNumbers)), null],
        };
    }

    /**
     * Decision 11: every Active part, and every Inactive one that still holds stock.
     *
     * @param  Builder<Part>  $parts
     * @return Builder<Part>
     */
    private function activeOrHeld(Builder $parts): Builder
    {
        return $parts->where(fn (Builder $query) => $query
            ->where('parts.is_active', true)
            ->orWhere('part_stock.on_hand', '<>', 0));
    }

    /**
     * @param  Builder<Part>  $parts
     * @return array{0: Builder<Part>, 1: string}
     *
     * @throws CountRefused
     */
    private function locationScope(Builder $parts, ?string $scopeValue): array
    {
        $location = $scopeValue !== null && ctype_digit($scopeValue)
            ? StorageLocation::query()->find((int) $scopeValue)
            : null;

        if (! $location instanceof StorageLocation) {
            throw CountRefused::unknownLocation();
        }

        return [$this->activeOrHeld($parts)->where('parts.default_location_id', $location->id), (string) $location->id];
    }

    /**
     * @param  Builder<Part>  $parts
     * @return array{0: Builder<Part>, 1: string}
     *
     * @throws CountRefused
     */
    private function criticalityScope(Builder $parts, ?string $scopeValue): array
    {
        $criticality = Criticality::tryFrom((string) $scopeValue) ?? throw CountRefused::unknownCriticality((string) $scopeValue);

        return [$this->activeOrHeld($parts)->where('parts.criticality', $criticality->value), $criticality->value];
    }

    /**
     * The ids of an adhoc session's parts, matched ignoring case, Active or not.
     *
     * @param  list<string>  $partNumbers
     * @return list<int>
     *
     * @throws CountRefused
     */
    private function partIds(array $partNumbers): array
    {
        $wanted = [];

        foreach ($partNumbers as $number) {
            $number = trim($number);

            if ($number !== '') {
                $wanted[Str::lower($number)] = $number;
            }
        }

        if ($wanted === []) {
            throw CountRefused::partNumbersRequired();
        }

        $found = Part::query()->whereIn(DB::raw('lower(part_number)'), array_keys($wanted))->get(['id', 'part_number']);
        $missing = array_diff_key($wanted, array_flip($found->map(fn (Part $part): string => Str::lower($part->part_number))->all()));

        if ($missing !== []) {
            throw CountRefused::unknownParts(array_values($missing));
        }

        return array_values($found->map(fn (Part $part): int => $part->id)->all());
    }

    /**
     * @throws CountRefused
     */
    private function countedQuantity(string $quantity): BigDecimal
    {
        try {
            $counted = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw CountRefused::countedQuantityInvalid($quantity);
        }

        if ($counted->isNegative()) {
            throw CountRefused::countedQuantityInvalid($quantity);
        }

        return $counted;
    }

    /**
     * Apply a change under the session's row lock, the lock StockLedger::postCount() takes first, so a
     * change racing a posting either lands before it or finds the session Posted.
     *
     * @template TResult
     *
     * @param  list<CountStatus>  $allowed
     * @param  Closure(CountSession): TResult  $change  given the locked copy of the session
     * @return TResult
     *
     * @throws CountRefused
     */
    private function change(CountSession $session, array $allowed, Closure $change): mixed
    {
        return DB::transaction(function () use ($session, $allowed, $change): mixed {
            $locked = CountSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $allowed, true)) {
                throw CountRefused::notAllowedNow($locked);
            }

            $result = $change($locked);

            // The caller's copy reads what was written.
            $session->setRawAttributes($locked->getAttributes(), true)->unsetRelations();

            return $result;
        });
    }

    /**
     * The line as it is now, and only if it belongs to this session.
     *
     * @throws CountRefused
     */
    private function lineOf(CountSession $session, CountLine $line): CountLine
    {
        return $session->lines()->with('part')->whereKey($line->id)->first() ?? throw CountRefused::lineNotInSession();
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/ReasonRuleTest.php tests/Feature/Counting tests/Feature/Ledger/TransactionNumbererTest.php
php artisan test tests/Committed/TransactionNumbererGuardTest.php
```

Expected: PASS. `ReasonRuleTest` has 8 cases. `CountSessionsTest` has 42 tests: the datasets are three
missing scopes, three tolerances, four counted quantities, and two role matrices of four. The
numbering tests stay green, the guard test included.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/Counting/CountSessions.php app/Domain/Counting/CountReview.php app/Domain/Counting/CountLineReview.php app/Domain/Counting/ReasonRule.php app/Domain/Counting/CountStaleness.php app/Domain/Counting/Exceptions/CountRefused.php app/Domain/Counting/Events/CountSessionCounted.php app/Domain/Ledger/TransactionNumberer.php tests/Unit/ReasonRuleTest.php tests/Feature/Counting/CountSessionsTest.php
git commit -m "Open, count and review a physical count session"
```

---

## Task 9: `StockLedger::postCount()` — posting a count

**Files:**
- Create: `app/Domain/Counting/Events/CountSessionPosted.php`
- Modify: `app/Domain/Ledger/StockLedger.php`, `app/Domain/Counting/Exceptions/CountRefused.php`
- Test: `tests/Feature/Ledger/CountPostingTest.php`

**Interfaces:**
- Consumes: `CountSessions`, `CountStaleness::staleLineIds()`, `ReasonRule::requiresReason()` and
  `CountRefused::notAllowedNow()` (Task 8); `stock_transactions.count_session_id` and the relaxed
  `adjustment_has_reason` (Task 1); `StockLedger`'s `applyLockTimeout()`, `writeBalances()` and
  `LOCK_NOT_AVAILABLE`.
- Produces:
  - `StockLedger::postCount(CountSession $session, User $supervisor): CountSession` — the Posted
    session. It throws `InstallNotConfigured`, `CountRefused` and `StockBusy`. The caller authorizes
    `Permission::CountsManage`.
  - `CountRefused::uncounted(list<string>)`, `CountRefused::stale(list<string>)` and
    `CountRefused::reasonsMissing(list<string>)`, each naming the parts.
  - The event `App\Domain\Counting\Events\CountSessionPosted` (after commit), for Plan 5 to resolve
    the discrepancy alerts of the posted parts (FR-SPIMS-013 step 10).
  - `StockLedger` gains a `CountStaleness` constructor dependency, and `lockBalances()` delegates to
    a new `lockPartBalances(array $partIds)`.

Design §5.6.1 in one posting:

1. Lock the session's row, then every counted part's stock row in part order.
2. Refuse, naming the parts, if any line is uncounted, any count is stale, or any difference needs a
   reason it lacks. Decision 13's staleness and §5.6.2's reason rule are checked **again under
   lock**, because both depend on stock that could have moved since the review screen was drawn.
3. For each line: `posting_delta = counted_qty − on_hand_at_post`, from the locked balance. The
   resulting balance is the count itself, so a count posting can never drive a balance negative,
   whatever was issued while the session was open.
4. Write one `adjustment` transaction, marked with `count_session_id` and numbered AJ, with one line
   and one `count_variance` movement per non-zero difference (Decision 1, design §5.6.3). Nothing is
   written when every count matched.
5. Record `on_hand_at_post` and `posting_delta` on every line, and set the session to Posted.

A full count can change thousands of parts in one database transaction. Every bulk statement is
therefore chunked at 500 rows. A movement row binds 12 parameters, and Postgres refuses a statement
with more than 65,535 (Review Focus 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ledger/CountPostingTest.php`:

```php
<?php

use App\Domain\Counting\CountSessions;
use App\Domain\Counting\Events\CountSessionPosted;
use App\Domain\Counting\Exceptions\CountRefused;
use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\ReversalRefused;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\CountScope;
use App\Enums\CountStatus;
use App\Enums\MovementType;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    configureInstall();
    $this->ledger = app(StockLedger::class);
    $this->sessions = app(CountSessions::class);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->custodian = userWithRoles(Role::Custodian);
    $this->bearing = partWithStock('5', ['part_number' => 'BRG-6204']);
    $this->belt = partWithStock('12', ['part_number' => 'BLT-A42']);
    $this->filter = partWithStock('3', ['part_number' => 'FLT-100']);
    // A count taken in the same second as the opening balances would be stale against them.
    $this->travel(1)->minutes();

    // An adhoc session over these parts with these counts entered, marked counted.
    $this->counted = function (array $counts): CountSession {
        $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: array_keys($counts));

        foreach ($counts as $partNumber => $counted) {
            $line = $session->lines()->whereHas('part', fn ($query) => $query->where('part_number', $partNumber))->sole();
            $this->sessions->enterCount($session, $line, $counted, $this->custodian);
        }

        $this->sessions->markCounted($session, $this->custodian);

        return $session;
    };

    // Give every line of a session a reason, needed or not.
    $this->reasonAll = function (CountSession $session, ReasonCode $reason = ReasonCode::Miscount): void {
        foreach ($session->lines()->get() as $line) {
            $this->sessions->setReason($session, $line, $reason, null, $this->supervisor);
        }
    };

    $this->withdraw = fn (Part $part, string $quantity) => $this->ledger->complete(
        StockTransaction::factory()->withLines([[$part, $quantity]])->create(),
        $this->custodian,
    );
});

it('posts against the balance at posting, so withdrawals during the session never drive it negative', function () {
    // Design §5.6.1: opened at 5, three issued while the session is open, and the shelf found empty.
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204']);
    $this->travel(1)->seconds();
    ($this->withdraw)($this->bearing, '3');
    $this->travel(1)->seconds();
    $this->sessions->enterCount($session, $session->lines()->sole(), '0', $this->custodian);
    $this->sessions->markCounted($session, $this->custodian);
    ($this->reasonAll)($session, ReasonCode::Shrinkage);

    $this->ledger->postCount($session, $this->supervisor);

    $line = $session->lines()->sole();
    $movement = StockMovement::query()->where('movement_type', MovementType::CountVariance->value)->sole();

    expect(onHand($this->bearing))->toBe('0.000')
        ->and($line->on_hand_at_post)->toBe('2.000')
        ->and($line->posting_delta)->toBe('-2.000')
        ->and($line->snapshot_variance)->toBe('-5.000')
        ->and($movement->quantity)->toBe('-2.000')
        ->and($movement->balance_after)->toBe('0.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('makes every counted balance equal its count, with one adjustment line per difference', function () {
    $session = ($this->counted)(['BRG-6204' => '7', 'BLT-A42' => '12', 'FLT-100' => '1.5']);
    ($this->reasonAll)($session);

    $posted = $this->ledger->postCount($session, $this->supervisor);

    $adjustment = StockTransaction::query()->where('count_session_id', $session->id)->sole();
    $movements = StockMovement::query()->where('stock_transaction_id', $adjustment->id)->get();

    expect($posted->status)->toBe(CountStatus::Posted)
        ->and($posted->posted_by_user_id)->toBe($this->supervisor->id)
        ->and(onHand($this->bearing))->toBe('7.000')
        ->and(onHand($this->belt))->toBe('12.000')
        ->and(onHand($this->filter))->toBe('1.500')
        ->and($adjustment->type)->toBe(TransactionType::Adjustment)
        ->and($adjustment->transaction_no)->toStartWith('AJ-')
        ->and($adjustment->reason_code)->toBeNull()
        ->and($adjustment->lines()->count())->toBe(2)
        ->and($movements)->toHaveCount(2)
        ->and($movements->every(fn (StockMovement $movement): bool => $movement->movement_type === MovementType::CountVariance))->toBeTrue()
        ->and($session->lines()->where('part_id', $this->belt->id)->sole()->posting_delta)->toBe('0.000')
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('posts a count where everything matched without writing any stock movement', function () {
    Event::fake([CountSessionPosted::class, StockTransactionCompleted::class]);
    $session = ($this->counted)(['BRG-6204' => '5', 'BLT-A42' => '12']);

    $this->ledger->postCount($session, $this->supervisor);

    expect($session->refresh()->status)->toBe(CountStatus::Posted)
        ->and(StockTransaction::query()->where('count_session_id', $session->id)->exists())->toBeFalse()
        ->and(StockMovement::query()->where('movement_type', MovementType::CountVariance->value)->exists())->toBeFalse();

    Event::assertDispatched(CountSessionPosted::class);
    Event::assertNotDispatched(StockTransactionCompleted::class);
});

it('announces the posting and its adjustment once they commit', function () {
    Event::fake([CountSessionPosted::class, StockTransactionCompleted::class]);
    $session = ($this->counted)(['BRG-6204' => '4']);
    ($this->reasonAll)($session);

    $this->ledger->postCount($session, $this->supervisor);

    Event::assertDispatched(CountSessionPosted::class, fn (CountSessionPosted $event): bool => $event->session->is($session));
    Event::assertDispatched(
        StockTransactionCompleted::class,
        fn (StockTransactionCompleted $event): bool => $event->transaction->count_session_id === $session->id,
    );
});

it('posts only a session marked counted, and only once', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204']);
    $this->sessions->enterCount($session, $session->lines()->sole(), '5', $this->custodian);

    expect(fn () => $this->ledger->postCount($session, $this->supervisor))
        ->toThrow(CountRefused::class, 'Mark it counted first');

    $this->sessions->markCounted($session, $this->custodian);
    $this->ledger->postCount($session, $this->supervisor);

    expect(fn () => $this->ledger->postCount($session, $this->supervisor))
        ->toThrow(CountRefused::class, 'already posted');
});

it('takes no more counts once the session is posted', function () {
    $session = ($this->counted)(['BRG-6204' => '5']);
    $this->ledger->postCount($session, $this->supervisor);

    expect(fn () => $this->sessions->enterCount($session, $session->lines()->sole(), '4', $this->custodian))
        ->toThrow(CountRefused::class, 'already posted');
});

it('refuses to post while a part is uncounted, until it is counted or removed', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BRG-6204', 'BLT-A42']);
    $this->sessions->enterCount($session, $session->lines()->where('part_id', $this->bearing->id)->sole(), '5', $this->custodian);
    $this->sessions->markCounted($session, $this->custodian);

    expect(fn () => $this->ledger->postCount($session, $this->supervisor))
        ->toThrow(CountRefused::class, 'Not counted yet: BLT-A42');

    expect(onHand($this->belt))->toBe('12.000');

    $this->sessions->removeLine($session, $session->lines()->where('part_id', $this->belt->id)->sole(), $this->supervisor);
    $this->ledger->postCount($session, $this->supervisor);

    expect($session->refresh()->status)->toBe(CountStatus::Posted);
});

it('refuses a stale count until it is counted again or acknowledged', function (string $remedy) {
    $session = ($this->counted)(['BRG-6204' => '5']);
    $this->travel(1)->seconds();
    // Issued after the count: the shelf now holds 3, and the count of 5 no longer describes it.
    ($this->withdraw)($this->bearing, '2');

    expect(fn () => $this->ledger->postCount($session, $this->supervisor))
        ->toThrow(CountRefused::class, 'Stock moved after these were counted: BRG-6204');

    expect(onHand($this->bearing))->toBe('3.000');

    $this->travel(1)->seconds();
    $line = $session->lines()->sole();

    if ($remedy === 'recount') {
        $this->sessions->enterCount($session, $line, '3', $this->custodian);
    } else {
        // The supervisor stands by the count of 5, which is now a difference of +2 and needs a reason.
        $this->sessions->acknowledge($session, $line, $this->supervisor);
        $this->sessions->setReason($session, $line, ReasonCode::FoundStock, null, $this->supervisor);
    }

    $this->ledger->postCount($session, $this->supervisor);

    expect(onHand($this->bearing))->toBe($remedy === 'recount' ? '3.000' : '5.000');
})->with(['recount', 'acknowledge']);

it('requires a reason for each difference the rule names', function () {
    $session = ($this->counted)(['BRG-6204' => '4', 'BLT-A42' => '12']);

    expect(fn () => $this->ledger->postCount($session, $this->supervisor))
        ->toThrow(CountRefused::class, 'Give a reason for the difference on BRG-6204');

    $this->sessions->setReason(
        $session,
        $session->lines()->where('part_id', $this->bearing->id)->sole(),
        ReasonCode::Shrinkage,
        null,
        $this->supervisor,
    );

    $this->ledger->postCount($session, $this->supervisor);

    expect(onHand($this->bearing))->toBe('4.000');
});

it('needs no reason for a difference within the session tolerance', function () {
    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: ['BLT-A42'], tolerancePct: '10');
    // 1 of 12 is 8.3%.
    $this->sessions->enterCount($session, $session->lines()->sole(), '11', $this->custodian);
    $this->sessions->markCounted($session, $this->custodian);

    $this->ledger->postCount($session, $this->supervisor);

    expect(onHand($this->belt))->toBe('11.000');
});

it('writes an adjustment that cannot be reversed', function () {
    $session = ($this->counted)(['BRG-6204' => '4']);
    ($this->reasonAll)($session);
    $this->ledger->postCount($session, $this->supervisor);

    $adjustment = StockTransaction::query()->where('count_session_id', $session->id)->sole();

    expect(fn () => $this->ledger->reverse($adjustment, 'Undo the count', $this->supervisor))
        ->toThrow(ReversalRefused::class, 'stock count');
});

it('audits the posting with its adjustment number', function () {
    $session = ($this->counted)(['BRG-6204' => '4']);
    ($this->reasonAll)($session);
    $this->ledger->postCount($session, $this->supervisor);

    $adjustment = StockTransaction::query()->where('count_session_id', $session->id)->sole();

    expect(AuditLog::query()->where('auditable_type', 'count_session')->where('event', 'posted')->sole()->new_values)
        ->toBe(['status' => 'posted', 'adjustment_transaction_no' => $adjustment->transaction_no]);
});

it('posts a count of more parts than one bulk statement takes', function () {
    $parts = Part::factory()->count(501)->create();
    $this->ledger->complete(
        StockTransaction::factory()->opening()->withLines($parts->map(fn (Part $part): array => [$part, '10'])->all())->create(),
        $this->supervisor,
    );
    $this->travel(1)->minutes();

    $session = $this->sessions->open($this->supervisor, CountScope::Adhoc, partNumbers: $parts->pluck('part_number')->all());

    // Every shelf holds one more than recorded. Written in bulk: entering 501 counts one at a time
    // would test nothing more, and slowly.
    CountLine::query()->where('count_session_id', $session->id)->update([
        'counted_qty' => DB::raw('system_qty + 1'),
        'counted_at' => now(),
        'counted_by_user_id' => $this->custodian->id,
        'reason_code' => ReasonCode::FoundStock->value,
    ]);
    $this->sessions->markCounted($session, $this->custodian);

    $this->ledger->postCount($session, $this->supervisor);

    $adjustment = StockTransaction::query()->where('count_session_id', $session->id)->sole();

    expect(StockMovement::query()->where('stock_transaction_id', $adjustment->id)->count())->toBe(501)
        ->and($adjustment->lines()->max('line_no'))->toBe(501)
        ->and(CountLine::query()->where('count_session_id', $session->id)->whereNull('posting_delta')->exists())->toBeFalse()
        ->and(PartStock::query()->whereIn('part_id', $parts->pluck('id'))->where('on_hand', '11')->count())->toBe(501)
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Ledger/CountPostingTest.php
```

Expected: FAIL — `Class "App\Domain\Counting\Events\CountSessionPosted" not found`.

- [ ] **Step 3: Write the event and the refusals**

Create `app/Domain/Counting/Events/CountSessionPosted.php`:

```php
<?php

namespace App\Domain\Counting\Events;

use App\Models\CountSession;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A count is posted and every counted balance equals its count (PRD FR-SPIMS-013 step 9). Plan 5
 * resolves the Inventory Discrepancy alerts of the posted parts (step 10).
 */
final readonly class CountSessionPosted implements ShouldDispatchAfterCommit
{
    public function __construct(public CountSession $session) {}
}
```

In `app/Domain/Counting/Exceptions/CountRefused.php`, add after `lastLine()`:

```php
    /**
     * @param  list<string>  $partNumbers
     */
    public static function uncounted(array $partNumbers): self
    {
        return new self(__('Not counted yet: :parts. Count them, or remove them from the session, before posting.', [
            'parts' => implode(', ', $partNumbers),
        ]));
    }

    /**
     * @param  list<string>  $partNumbers
     */
    public static function stale(array $partNumbers): self
    {
        return new self(__('Stock moved after these were counted: :parts. Count them again, or acknowledge the count, before posting.', [
            'parts' => implode(', ', $partNumbers),
        ]));
    }

    /**
     * @param  list<string>  $partNumbers
     */
    public static function reasonsMissing(array $partNumbers): self
    {
        return new self(__('Give a reason for the difference on :parts before posting.', [
            'parts' => implode(', ', $partNumbers),
        ]));
    }
```

- [ ] **Step 4: Lock stock rows by part id**

In `app/Domain/Ledger/StockLedger.php`, replace `lockBalances()` with the two methods below, so a
count posting can lock the parts of its `count_lines`. The existing callers are unchanged:

```php
    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     * @return array<int, BigDecimal> locked on_hand by part id
     */
    private function lockBalances(Collection $lines): array
    {
        return $this->lockPartBalances($lines->map(fn (StockTransactionLine $line): int => $line->part_id)->all());
    }

    /**
     * Lock every affected stock row in one statement, ordered by part id. The same order for every
     * posting is what keeps two stations from deadlocking (design §5.2).
     *
     * @param  array<int, int>  $partIds
     * @return array<int, BigDecimal> locked on_hand by part id
     */
    private function lockPartBalances(array $partIds): array
    {
        $partIds = array_values(array_unique($partIds));
        sort($partIds);

        $stock = PartStock::query()
            ->whereIn('part_id', $partIds)
            ->orderBy('part_id')
            ->lockForUpdate()
            ->get();

        if ($stock->count() !== count($partIds)) {
            throw new LogicException('Every part must have a part_stock row; the parts insert trigger creates it.');
        }

        return $stock
            ->mapWithKeys(fn (PartStock $row): array => [$row->part_id => BigDecimal::of($row->on_hand)])
            ->all();
    }
```

- [ ] **Step 5: Add `postCount()`**

In `app/Domain/Ledger/StockLedger.php`:

1. Add these imports:

```php
use App\Domain\Counting\CountStaleness;
use App\Domain\Counting\Events\CountSessionPosted;
use App\Domain\Counting\Exceptions\CountRefused;
use App\Domain\Counting\ReasonRule;
use App\Enums\CountStatus;
use App\Models\CountLine;
use App\Models\CountSession;
```

2. Add `private CountStaleness $staleness,` as the last constructor parameter, and this constant
   after `LOCK_NOT_AVAILABLE`:

```php
    /**
     * Rows per bulk statement in a count posting. A movement row binds 12 parameters, and Postgres
     * refuses a statement with more than 65,535.
     */
    private const int COUNT_CHUNK = 500;
```

3. Add these methods after `postReversal()`:

```php
    /**
     * Post a counted session (PRD FR-SPIMS-013 steps 8 to 11, design §5.6.1): make every counted
     * part's balance equal its count. Each difference is counted_qty minus the balance read under
     * the stock row lock — never the snapshot — so the result is the count itself and can never be
     * negative. Every non-zero difference is one line of one adjustment transaction, with a
     * count_variance movement (Plan 4 Decision 1). All of it posts or none of it does. The caller
     * authorizes Permission::CountsManage.
     *
     * @throws InstallNotConfigured
     * @throws CountRefused when the session is not Counted, or a line is uncounted, stale or missing
     *                      the reason its difference needs
     * @throws StockBusy
     */
    public function postCount(CountSession $session, User $supervisor): CountSession
    {
        if (! $this->settings->isReadyForStockTransactions()) {
            throw new InstallNotConfigured;
        }

        try {
            /** @var array{0: CountSession, 1: StockTransaction|null} $result */
            $result = DB::transaction(fn (): array => $this->postCountSession($session->id, $supervisor), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new StockBusy($exception);
            }

            throw $exception;
        }

        [$posted, $adjustment] = $result;

        if ($adjustment instanceof StockTransaction) {
            event(new StockTransactionCompleted($adjustment));
        }

        event(new CountSessionPosted($posted));

        return $posted;
    }

    /**
     * @return array{0: CountSession, 1: StockTransaction|null}
     */
    private function postCountSession(int $sessionId, User $supervisor): array
    {
        $this->applyLockTimeout();

        // The session's row first, the lock CountSessions takes for every change, then the stock rows.
        $session = CountSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();

        if ($session->status !== CountStatus::Counted) {
            throw CountRefused::notAllowedNow($session);
        }

        $lines = $session->lines()->with('part')->orderBy('part_id')->get();

        $uncounted = $lines->whereNull('counted_qty');

        if ($uncounted->isNotEmpty()) {
            throw CountRefused::uncounted($this->countedPartNumbers($uncounted));
        }

        $balances = $this->lockPartBalances($lines->map(fn (CountLine $line): int => $line->part_id)->all());

        // Plan 4 Decision 13, again now that nothing else can move these parts.
        $stale = $this->staleness->staleLineIds($session->id);

        if ($stale !== []) {
            throw CountRefused::stale($this->countedPartNumbers($lines->whereIn('id', $stale)));
        }

        // Design §5.6.2, on the difference that will actually be posted.
        $tolerance = BigDecimal::of($session->variance_tolerance_pct);
        /** @var array<int, BigDecimal> $deltas by count line id */
        $deltas = [];
        $reasonsMissing = [];

        foreach ($lines as $line) {
            $delta = BigDecimal::of((string) $line->counted_qty)->minus($balances[$line->part_id]);
            $deltas[$line->id] = $delta;

            if ($line->reason_code === null && ReasonRule::requiresReason($delta, BigDecimal::of($line->system_qty), $tolerance)) {
                $reasonsMissing[] = $line->part->part_number;
            }
        }

        if ($reasonsMissing !== []) {
            sort($reasonsMissing);

            throw CountRefused::reasonsMissing($reasonsMissing);
        }

        $recordedAt = CarbonImmutable::now();
        $changed = $lines->filter(fn (CountLine $line): bool => ! $deltas[$line->id]->isZero())->values();
        $adjustment = $changed->isEmpty()
            ? null
            : $this->writeCountAdjustment($session, $changed, $deltas, $supervisor, $recordedAt);

        $this->writeCountResults($lines, $balances, $deltas);

        $session->forceFill([
            'status' => CountStatus::Posted,
            'posted_by_user_id' => $supervisor->id,
            'posted_at' => $recordedAt,
        ])->save();

        $this->audit->record('posted', $session, ['status' => CountStatus::Counted->value], [
            'status' => CountStatus::Posted->value,
            'adjustment_transaction_no' => $adjustment?->transaction_no,
        ], $supervisor);

        return [$session, $adjustment];
    }

    /**
     * Design §5.6.3: one adjustment transaction for the session, one line per changed part, and one
     * count_variance movement per line whose balance_after is the count itself. Written in chunks,
     * because a full count can change thousands of parts.
     *
     * @param  Collection<int, CountLine>  $changed  in part id order, keyed from 0
     * @param  array<int, BigDecimal>  $deltas  by count line id
     */
    private function writeCountAdjustment(
        CountSession $session,
        Collection $changed,
        array $deltas,
        User $supervisor,
        CarbonImmutable $recordedAt,
    ): StockTransaction {
        $adjustment = StockTransaction::query()->create([
            'type' => TransactionType::Adjustment,
            'custodian_user_id' => $supervisor->id,
            'remarks' => __('Stock count :number', ['number' => $session->session_no]),
            'occurred_at' => $recordedAt,
        ]);

        // Decision 2: what makes this adjustment a count posting, exempt from adjustment_has_reason
        // (its reasons are per part, on count_lines) and impossible to reverse.
        $adjustment->forceFill(['count_session_id' => $session->id])->save();

        $lineRows = [];

        foreach ($changed as $index => $line) {
            $lineRows[] = [
                'stock_transaction_id' => $adjustment->id,
                'line_no' => $index + 1,
                'part_id' => $line->part_id,
                'quantity' => (string) $deltas[$line->id]->abs(),
                'direction' => $deltas[$line->id]->isPositive() ? 1 : -1,
            ];
        }

        foreach (array_chunk($lineRows, self::COUNT_CHUNK) as $rows) {
            StockTransactionLine::query()->insert($rows);
        }

        $lineIds = StockTransactionLine::query()
            ->where('stock_transaction_id', $adjustment->id)
            ->pluck('id', 'part_id');

        $movementRows = [];
        $resulting = [];

        foreach ($changed as $line) {
            $counted = BigDecimal::of((string) $line->counted_qty);
            $resulting[$line->part_id] = $counted;

            $movementRows[] = [
                'part_id' => $line->part_id,
                'stock_transaction_id' => $adjustment->id,
                'stock_transaction_line_id' => $lineIds[$line->part_id],
                'movement_type' => MovementType::CountVariance->value,
                'quantity' => (string) $deltas[$line->id],
                // Design §5.6.1: the resulting balance is exactly the count.
                'balance_after' => (string) $counted,
                'occurred_at' => $recordedAt,
                'recorded_at' => $recordedAt,
                'personnel_id' => null,
                'equipment_id' => null,
                'purpose_id' => null,
                'custodian_user_id' => $supervisor->id,
            ];
        }

        foreach (array_chunk($movementRows, self::COUNT_CHUNK) as $rows) {
            StockMovement::query()->insert($rows);
        }

        foreach (array_chunk($resulting, self::COUNT_CHUNK, true) as $chunk) {
            $this->writeBalances($chunk, $recordedAt);
        }

        $adjustment->forceFill([
            'status' => TransactionStatus::Completed,
            'transaction_no' => $this->numberer->next(TransactionType::Adjustment, $recordedAt),
            'recorded_at' => $recordedAt,
        ])->save();

        $this->audit->record(
            'completed',
            $adjustment,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Completed->value, 'transaction_no' => $adjustment->transaction_no],
            $supervisor,
        );

        return $adjustment->refresh();
    }

    /**
     * Every line's on_hand_at_post and posting_delta, zero differences included, in one UPDATE per
     * chunk rather than one per line: a posting holds every counted part's stock lock until it commits.
     *
     * @param  Collection<int, CountLine>  $lines
     * @param  array<int, BigDecimal>  $balances  locked on_hand by part id
     * @param  array<int, BigDecimal>  $deltas  by count line id
     */
    private function writeCountResults(Collection $lines, array $balances, array $deltas): void
    {
        foreach ($lines->chunk(self::COUNT_CHUNK) as $chunk) {
            $rows = implode(', ', array_fill(0, $chunk->count(), '(cast(? as bigint), cast(? as numeric), cast(? as numeric))'));
            $bindings = [];

            foreach ($chunk as $line) {
                $bindings[] = $line->id;
                $bindings[] = (string) $balances[$line->part_id];
                $bindings[] = (string) $deltas[$line->id];
            }

            DB::update(
                "update count_lines set on_hand_at_post = v.on_hand, posting_delta = v.delta
                 from (values {$rows}) as v (id, on_hand, delta)
                 where count_lines.id = v.id",
                $bindings,
            );
        }
    }

    /**
     * @param  Collection<int, CountLine>  $lines
     * @return list<string>
     */
    private function countedPartNumbers(Collection $lines): array
    {
        $numbers = array_values($lines->map(fn (CountLine $line): string => $line->part->part_number)->all());
        sort($numbers);

        return $numbers;
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Ledger tests/Feature/Counting
```

Expected: PASS, 14 new tests (the stale remedy is a dataset of two). Every other ledger test stays
green: `lockBalances()` gives the same result through `lockPartBalances()`, and `StockLedger` is only
ever resolved from the container, so its new constructor dependency is injected.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Counting/Events/CountSessionPosted.php app/Domain/Counting/Exceptions/CountRefused.php app/Domain/Ledger/StockLedger.php tests/Feature/Ledger/CountPostingTest.php
git commit -m "Post a count session so every counted balance equals its count"
```

---

## Task 10: The stock count screens

**Files:**
- Create: `app/Livewire/Counts/Index.php`, `resources/views/livewire/counts.blade.php`,
  `app/Livewire/Counts/Show.php`, `resources/views/livewire/counts/show.blade.php`
- Modify: `app/Support/Navigation.php`, `routes/spims.php`, `tests/Feature/Shell/AppShellTest.php`,
  `tests/Feature/Access/ScreenAccessMatrixTest.php`,
  `tests/Feature/Access/ActionReauthorizationTest.php`
- Test: `tests/Feature/Counting/CountScreensTest.php`

**Interfaces:**
- Consumes: every `CountSessions` method and `CountReview` (Task 8); `StockLedger::postCount()`
  (Task 9); route `transactions.show` (Task 4); `Permission::CountsEnter` (Custodian, Supervisor) and
  `Permission::CountsManage` (Supervisor).
- Produces: routes `counts.index` (`/counts`) and `counts.show` (`/counts/{count}`); the menu item
  **Stock counts**; the Livewire actions `openSession()` on the list and `saveCount(int)`,
  `markCounted()`, `saveReason(int)`, `acknowledge(int)`, `removeLine(int)`, `postSession()` and
  `cancelSession()` on the session.

Two screens, both open to whoever may enter counts.

**Stock counts** lists the sessions, newest first, with how many lines are counted. A supervisor also
sees **Open a count**:

- what to count: every part, a storage location, a criticality, or a list of part numbers
- the tolerance, blank for the install's default
- notes

**Count 2026-0007** is where the counting happens:

- **Custodians count blind** (Decision 12). They see each part, its description and its location, type
  what is on the shelf and press Enter, then **Mark counted**. They never see the recorded balance or
  the difference.
- **A supervisor sees everything.** For each line: the balance now, the count, the difference, the
  net movement since the session opened, and whether the count is stale. Once the session is
  Counted, each line has a reason, notes, **Acknowledge** for a stale count and **Remove**. A summary
  says what still blocks posting, then comes **Post count**. **Cancel count** is there until it is
  posted.
- A posted session shows each line's balance at posting and the difference posted, and links to the
  adjustment it wrote.

Every action saves one line at a time, on Enter or a click. There is no autosave on blur: Plan 3's
autosave needed three fix rounds, and a count is typed one shelf at a time anyway.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Counting/CountScreensTest.php`:

```php
<?php

use App\Domain\Counting\CountSessions;
use App\Domain\Ledger\StockLedger;
use App\Enums\CountScope;
use App\Enums\CountStatus;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Livewire\Counts\Index;
use App\Livewire\Counts\Show;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use Livewire\Livewire;

beforeEach(function () {
    configureInstall();
    $this->sessions = app(CountSessions::class);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->custodian = userWithRoles(Role::Custodian);
    $this->shelf = StorageLocation::factory()->create(['code' => 'A-01']);
    $this->bearing = partWithStock('5', ['part_number' => 'BRG-6204', 'description' => 'Deep groove ball bearing', 'default_location_id' => $this->shelf->id]);
    $this->belt = partWithStock('12', ['part_number' => 'BLT-A42', 'default_location_id' => $this->shelf->id]);
    // Counts must not share a second with the opening balances.
    $this->travel(1)->minutes();

    $this->session = $this->sessions->open($this->supervisor, CountScope::Location, (string) $this->shelf->id);
    $this->lineOf = fn ($part): CountLine => $this->session->lines()->where('part_id', $part->id)->sole();
});

it('opens the count screens only for custodians and supervisors', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $list = $this->actingAs($user)->get(route('counts.index'));
    $session = $this->actingAs($user)->get(route('counts.show', $this->session));

    $allowed ? $list->assertOk() : $list->assertForbidden();
    $allowed ? $session->assertOk() : $session->assertForbidden();
    expect(in_array('Stock counts', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('CS--'));

it('lists the sessions with how far counting has got', function () {
    $this->sessions->enterCount($this->session, ($this->lineOf)($this->bearing), '5', $this->custodian);

    Livewire::actingAs($this->custodian)->test(Index::class)
        ->assertSee($this->session->session_no)
        ->assertSee('1 / 2');
});

it('offers Open a count to a supervisor only', function () {
    Livewire::actingAs($this->custodian)->test(Index::class)->assertDontSee(__('Open a count'));
    Livewire::actingAs($this->supervisor)->test(Index::class)->assertSee(__('Open a count'));
});

it('opens a count from the screen and goes to it', function () {
    $component = Livewire::actingAs($this->supervisor)->test(Index::class)
        ->set('scope', 'adhoc')
        ->set('partNumbers', "BRG-6204\nblt-a42")
        ->set('tolerance', '5')
        ->call('openSession')
        ->assertHasNoErrors();

    $opened = CountSession::query()->where('scope', CountScope::Adhoc)->sole();

    $component->assertRedirect(route('counts.show', $opened));

    expect($opened->lines()->count())->toBe(2)
        ->and($opened->variance_tolerance_pct)->toBe('5.000');
});

it('shows why a count could not be opened', function () {
    Livewire::actingAs($this->supervisor)->test(Index::class)
        ->set('scope', 'adhoc')
        ->set('partNumbers', 'NOPE-1')
        ->call('openSession')
        ->assertHasErrors('open')
        ->assertSee('NOPE-1');
});

it('lets a custodian count blind', function () {
    Livewire::actingAs($this->custodian)->test(Show::class, ['count' => $this->session])
        ->assertSee('Deep groove ball bearing')
        ->assertSee('A-01')
        ->assertDontSee(__('Recorded now'))
        ->assertDontSee(__('Difference'));
});

it('saves a count and marks the session counted', function () {
    $line = ($this->lineOf)($this->bearing);

    Livewire::actingAs($this->custodian)->test(Show::class, ['count' => $this->session])
        ->set("counts.{$line->id}", '4')
        ->call('saveCount', $line->id)
        ->assertHasNoErrors()
        ->call('markCounted')
        ->assertHasNoErrors();

    expect($line->refresh()->counted_qty)->toBe('4.000')
        ->and($line->counted_by_user_id)->toBe($this->custodian->id)
        ->and($this->session->refresh()->status)->toBe(CountStatus::Counted);
});

it('shows a count it refused under its line', function () {
    $line = ($this->lineOf)($this->bearing);

    Livewire::actingAs($this->custodian)->test(Show::class, ['count' => $this->session])
        ->set("counts.{$line->id}", '-2')
        ->call('saveCount', $line->id)
        ->assertHasErrors("counts.{$line->id}");

    expect($line->refresh()->counted_qty)->toBeNull();
});

it('lets a supervisor review, give a reason and post, then links to the adjustment', function () {
    $bearing = ($this->lineOf)($this->bearing);
    $belt = ($this->lineOf)($this->belt);
    $this->sessions->enterCount($this->session, $bearing, '4', $this->custodian);
    $this->sessions->enterCount($this->session, $belt, '12', $this->custodian);
    $this->sessions->markCounted($this->session, $this->custodian);

    $component = Livewire::actingAs($this->supervisor)->test(Show::class, ['count' => $this->session])
        ->assertSee(__('Recorded now'))
        ->assertSee(__('Give a reason for: :parts', ['parts' => 'BRG-6204']))
        ->call('postSession')
        ->assertHasErrors('session')
        ->set("reasons.{$bearing->id}", ReasonCode::Shrinkage->value)
        ->call('saveReason', $bearing->id)
        ->assertHasNoErrors()
        ->call('postSession')
        ->assertHasNoErrors();

    $adjustment = StockTransaction::query()->where('count_session_id', $this->session->id)->sole();

    expect($this->session->refresh()->status)->toBe(CountStatus::Posted)
        ->and(onHand($this->bearing))->toBe('4.000');

    $component->assertSee($adjustment->transaction_no)
        ->assertSee(route('transactions.show', $adjustment), false);
});

it('flags a stale count and lets a supervisor acknowledge it', function () {
    $bearing = ($this->lineOf)($this->bearing);
    $this->sessions->enterCount($this->session, $bearing, '5', $this->custodian);
    $this->sessions->enterCount($this->session, ($this->lineOf)($this->belt), '12', $this->custodian);
    $this->sessions->markCounted($this->session, $this->custodian);
    $this->travel(1)->seconds();
    app(StockLedger::class)->complete(StockTransaction::factory()->withLines([[$this->bearing, '1']])->create(), $this->custodian);
    $this->travel(1)->seconds();

    Livewire::actingAs($this->supervisor)->test(Show::class, ['count' => $this->session])
        ->assertSee(__('Stale'))
        ->call('acknowledge', $bearing->id)
        ->assertHasNoErrors()
        ->assertDontSee(__('Stale'));

    expect($bearing->refresh()->acknowledged_by_user_id)->toBe($this->supervisor->id);
});

it('removes a line from the screen', function () {
    $this->sessions->markCounted($this->session, $this->custodian);
    $belt = ($this->lineOf)($this->belt);

    Livewire::actingAs($this->supervisor)->test(Show::class, ['count' => $this->session])
        ->call('removeLine', $belt->id)
        ->assertHasNoErrors()
        ->assertDontSee('BLT-A42');

    expect($this->session->lines()->count())->toBe(1);
});

it('cancels a count from the screen', function () {
    Livewire::actingAs($this->supervisor)->test(Show::class, ['count' => $this->session])
        ->call('cancelSession')
        ->assertHasNoErrors();

    expect($this->session->refresh()->status)->toBe(CountStatus::Cancelled);
});

it('keeps posting, reasons and cancelling to a supervisor', function (string $action, array $arguments) {
    $this->sessions->markCounted($this->session, $this->custodian);
    $line = ($this->lineOf)($this->bearing);

    Livewire::actingAs($this->custodian)->test(Show::class, ['count' => $this->session])
        ->call($action, ...array_map(fn ($argument) => $argument === 'line' ? $line->id : $argument, $arguments))
        ->assertForbidden();

    expect($this->session->refresh()->status)->toBe(CountStatus::Counted);
})->with([
    'post' => ['postSession', []],
    'cancel' => ['cancelSession', []],
    'reason' => ['saveReason', ['line']],
    'acknowledge' => ['acknowledge', ['line']],
    'remove' => ['removeLine', ['line']],
]);
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Counting/CountScreensTest.php
```

Expected: FAIL — `Route [counts.index] not defined`.

- [ ] **Step 3: Write the list**

Create `app/Livewire/Counts/Index.php`:

```php
<?php

namespace App\Livewire\Counts;

use App\Domain\Counting\CountSessions;
use App\Domain\Counting\Exceptions\CountRefused;
use App\Domain\Settings\InstallSettings;
use App\Enums\CountScope;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\CountSession;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The physical counts, and where a supervisor opens one (PRD FR-SPIMS-013 step 1).
 */
#[Title('Stock counts')]
class Index extends Component
{
    use ActsAsSignedInUser;

    public string $scope = 'full';

    public string $locationId = '';

    public string $criticality = '';

    /** One part number per line, or separated by commas, for an adhoc count. */
    public string $partNumbers = '';

    public string $tolerance = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize(Permission::CountsEnter);
    }

    public function openSession(): void
    {
        $this->authorize(Permission::CountsManage);
        $this->resetErrorBag();

        $scope = CountScope::tryFrom($this->scope);

        if ($scope === null) {
            $this->addError('open', __('Choose what to count.'));

            return;
        }

        try {
            $session = app(CountSessions::class)->open(
                $this->actor(),
                $scope,
                match ($scope) {
                    CountScope::Location => $this->locationId,
                    CountScope::Criticality => $this->criticality,
                    default => null,
                },
                $scope === CountScope::Adhoc ? $this->partNumberList() : [],
                $this->tolerance,
                $this->notes,
            );
        } catch (CountRefused $refused) {
            $this->addError('open', $refused->getMessage());

            return;
        }

        $this->redirectRoute('counts.show', ['count' => $session->id], navigate: true);
    }

    /**
     * @return Collection<int, CountSession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return CountSession::query()
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($query) => $query->whereNotNull('counted_qty')])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, StorageLocation>
     */
    #[Computed]
    public function locations(): Collection
    {
        return StorageLocation::query()->where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function defaultTolerance(): string
    {
        $default = app(InstallSettings::class)->get('counting.default_tolerance_pct');

        return is_int($default) || is_float($default) ? (string) $default : '';
    }

    /**
     * Part numbers may contain spaces, so only line breaks and commas separate them.
     *
     * @return list<string>
     */
    private function partNumberList(): array
    {
        $numbers = preg_split('/[\r\n,]+/', $this->partNumbers);

        return array_values(array_filter(
            array_map(trim(...), $numbers === false ? [] : $numbers),
            fn (string $number): bool => $number !== '',
        ));
    }
}
```

Create `resources/views/livewire/counts.blade.php` (Livewire 4 names an `Index` component after its
folder):

```blade
<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ __('Stock counts') }}</flux:heading>

    @can(\App\Enums\Permission::CountsManage)
        <div class="mb-8 max-w-3xl space-y-4">
            <flux:heading size="lg">{{ __('Open a count') }}</flux:heading>

            <flux:error name="open" />

            <div class="flex flex-wrap items-end gap-4">
                <flux:select wire:model.live="scope" :label="__('Count')" class="max-w-56">
                    @foreach (\App\Enums\CountScope::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($scope === 'location')
                    <flux:select wire:model="locationId" :label="__('Storage location')" class="max-w-xs">
                        <flux:select.option value="">{{ __('Choose a location') }}</flux:select.option>
                        @foreach ($this->locations as $location)
                            <flux:select.option :value="$location->id">{{ $location->code }} — {{ $location->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @elseif ($scope === 'criticality')
                    <flux:select wire:model="criticality" :label="__('Criticality')" class="max-w-xs">
                        <flux:select.option value="">{{ __('Choose a criticality') }}</flux:select.option>
                        @foreach (\App\Enums\Criticality::cases() as $option)
                            <flux:select.option :value="$option->value">{{ \Illuminate\Support\Str::headline($option->value) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="tolerance" :label="__('Tolerance (%)')" :placeholder="$this->defaultTolerance" class="max-w-32" inputmode="decimal" />
            </div>

            @if ($scope === 'adhoc')
                <flux:textarea wire:model="partNumbers" :label="__('Part numbers')" :description="__('One per line, or separated by commas.')" rows="4" />
            @endif

            <flux:input wire:model="notes" :label="__('Notes')" />

            <flux:button variant="primary" wire:click="openSession">{{ __('Open count') }}</flux:button>
        </div>
    @endcan

    @if ($this->sessions->isEmpty())
        <flux:text>{{ __('No counts yet.') }}</flux:text>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Count') }}</flux:table.column>
                <flux:table.column>{{ __('What') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Counted') }}</flux:table.column>
                <flux:table.column>{{ __('Opened') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->sessions as $session)
                    <flux:table.row wire:key="count-session-{{ $session->id }}">
                        <flux:table.cell>
                            <flux:link :href="route('counts.show', $session)" wire:navigate>{{ $session->session_no }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $session->scope->label() }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm">{{ \Illuminate\Support\Str::headline($session->status->value) }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ $session->counted_lines_count }} / {{ $session->lines_count }}</flux:table.cell>
                        <flux:table.cell>{{ $session->opened_at->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone())->format('Y-m-d H:i') }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
```

- [ ] **Step 4: Write the session screen**

Create `app/Livewire/Counts/Show.php`:

```php
<?php

namespace App\Livewire\Counts;

use App\Domain\Counting\CountReview;
use App\Domain\Counting\CountSessions;
use App\Domain\Counting\Exceptions\CountRefused;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\StockLedger;
use App\Enums\CountScope;
use App\Enums\Criticality;
use App\Enums\Permission;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\StorageLocation;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One count session: custodians count blind, a supervisor reviews and posts (PRD FR-SPIMS-013).
 * Every change saves one line at a time, on Enter or a click.
 */
#[Title('Stock count')]
class Show extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $sessionId;

    /** @var array<int, string> the counted quantity typed, by line id */
    public array $counts = [];

    /** @var array<int, string> the reason chosen, by line id */
    public array $reasons = [];

    /** @var array<int, string> by line id */
    public array $notes = [];

    public function mount(CountSession $count): void
    {
        $this->authorize(Permission::CountsEnter);

        $this->sessionId = $count->id;

        foreach ($count->lines()->get() as $line) {
            $this->counts[$line->id] = Quantity::display($line->counted_qty);
            $this->reasons[$line->id] = $line->reason_code?->value ?? '';
            $this->notes[$line->id] = $line->notes ?? '';
        }
    }

    public function saveCount(int $lineId): void
    {
        $this->authorize(Permission::CountsEnter);
        $this->resetErrorBag();

        try {
            $line = app(CountSessions::class)->enterCount(
                $this->session(),
                $this->line($lineId),
                $this->counts[$lineId] ?? '',
                $this->actor(),
            );
        } catch (CountRefused $refused) {
            $this->addError("counts.{$lineId}", $refused->getMessage());

            return;
        }

        $this->counts[$lineId] = Quantity::display($line->counted_qty);
        $this->forget();
    }

    public function markCounted(): void
    {
        $this->authorize(Permission::CountsEnter);
        $this->resetErrorBag();

        try {
            app(CountSessions::class)->markCounted($this->session(), $this->actor());
        } catch (CountRefused $refused) {
            $this->addError('session', $refused->getMessage());

            return;
        }

        $this->forget();

        Flux::toast(variant: 'success', text: __('Marked counted. An Inventory Supervisor reviews and posts it.'));
    }

    public function saveReason(int $lineId): void
    {
        $this->authorize(Permission::CountsManage);
        $this->resetErrorBag();

        try {
            app(CountSessions::class)->setReason(
                $this->session(),
                $this->line($lineId),
                ReasonCode::tryFrom($this->reasons[$lineId] ?? ''),
                $this->notes[$lineId] ?? null,
                $this->actor(),
            );
        } catch (CountRefused $refused) {
            $this->addError("reasons.{$lineId}", $refused->getMessage());

            return;
        }

        $this->forget();
    }

    public function acknowledge(int $lineId): void
    {
        $this->authorize(Permission::CountsManage);
        $this->resetErrorBag();

        try {
            app(CountSessions::class)->acknowledge($this->session(), $this->line($lineId), $this->actor());
        } catch (CountRefused $refused) {
            $this->addError("reasons.{$lineId}", $refused->getMessage());

            return;
        }

        $this->forget();
    }

    public function removeLine(int $lineId): void
    {
        $this->authorize(Permission::CountsManage);
        $this->resetErrorBag();

        try {
            app(CountSessions::class)->removeLine($this->session(), $this->line($lineId), $this->actor());
        } catch (CountRefused $refused) {
            $this->addError('session', $refused->getMessage());

            return;
        }

        unset($this->counts[$lineId], $this->reasons[$lineId], $this->notes[$lineId]);
        $this->forget();
    }

    public function postSession(): void
    {
        $this->authorize(Permission::CountsManage);
        $this->resetErrorBag();

        try {
            app(StockLedger::class)->postCount($this->session(), $this->actor());
        } catch (CountRefused|InstallNotConfigured|StockBusy $refused) {
            $this->addError('session', $refused->getMessage());

            return;
        }

        $this->forget();

        Flux::toast(variant: 'success', text: __('Count posted. Every counted balance now equals its count.'));
    }

    public function cancelSession(): void
    {
        $this->authorize(Permission::CountsManage);
        $this->resetErrorBag();

        try {
            app(CountSessions::class)->cancel($this->session(), $this->actor());
        } catch (CountRefused $refused) {
            $this->addError('session', $refused->getMessage());

            return;
        }

        $this->forget();
    }

    #[Computed]
    public function session(): CountSession
    {
        return CountSession::query()->with(['openedBy', 'adjustment'])->findOrFail($this->sessionId);
    }

    #[Computed]
    public function review(): CountReview
    {
        return app(CountSessions::class)->review($this->session());
    }

    /**
     * What the session covers, in words: "All spare parts", "One storage location — A-01".
     */
    #[Computed]
    public function coverage(): string
    {
        $session = $this->session();

        $detail = match ($session->scope) {
            CountScope::Location => StorageLocation::query()->find((int) $session->scope_value)?->code,
            CountScope::Criticality => Str::headline(Criticality::from((string) $session->scope_value)->value),
            default => null,
        };

        return $detail === null ? $session->scope->label() : "{$session->scope->label()} — {$detail}";
    }

    private function line(int $lineId): CountLine
    {
        return CountLine::query()->where('count_session_id', $this->sessionId)->findOrFail($lineId);
    }

    private function forget(): void
    {
        unset($this->session, $this->review, $this->coverage);
    }
}
```

Create `resources/views/livewire/counts/show.blade.php`:

```blade
@php
    $session = $this->session;
    $review = $this->review;
    $posted = $session->status === \App\Enums\CountStatus::Posted;
    $counting = in_array($session->status, [\App\Enums\CountStatus::Open, \App\Enums\CountStatus::Counted], true);
    $manages = auth()->user()->can(\App\Enums\Permission::CountsManage);
    $reviewing = $manages && $session->status === \App\Enums\CountStatus::Counted;
@endphp

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Count :number', ['number' => $session->session_no]) }}</flux:heading>
            <flux:text>
                {{ $this->coverage }} ·
                {{ __('Tolerance :pct%', ['pct' => \App\Support\Quantity::display($session->variance_tolerance_pct)]) }} ·
                {{ \Illuminate\Support\Str::headline($session->status->value) }}
            </flux:text>
        </div>

        <div class="flex gap-2">
            @if ($session->status === \App\Enums\CountStatus::Open)
                <flux:button wire:click="markCounted">{{ __('Mark counted') }}</flux:button>
            @endif
            @if ($manages && $counting)
                <flux:button variant="danger" wire:click="cancelSession" wire:confirm="{{ __('Cancel this count? Nothing is posted.') }}">
                    {{ __('Cancel count') }}
                </flux:button>
            @endif
        </div>
    </div>

    <flux:error name="session" class="mb-4" />

    @if ($counting && ! $manages)
        <flux:text class="mb-6">{{ __('Type what is on the shelf and press Enter to save each line. When every shelf is counted, press Mark counted.') }}</flux:text>
    @endif

    @if ($reviewing && ! $review->readyToPost())
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
            <flux:callout.heading>{{ __('Before this count can be posted') }}</flux:callout.heading>
            @if ($review->uncounted() !== [])
                <flux:callout.text>{{ __('Count or remove: :parts', ['parts' => implode(', ', $review->uncounted())]) }}</flux:callout.text>
            @endif
            @if ($review->stale() !== [])
                <flux:callout.text>{{ __('Stock moved after these were counted, so count them again or acknowledge: :parts', ['parts' => implode(', ', $review->stale())]) }}</flux:callout.text>
            @endif
            @if ($review->reasonsMissing() !== [])
                <flux:callout.text>{{ __('Give a reason for: :parts', ['parts' => implode(', ', $review->reasonsMissing())]) }}</flux:callout.text>
            @endif
        </flux:callout>
    @endif

    @if ($posted && $session->adjustment)
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            <flux:callout.heading>
                {{ __('Posted as') }}
                <flux:link :href="route('transactions.show', $session->adjustment)" wire:navigate>{{ $session->adjustment->transaction_no }}</flux:link>
            </flux:callout.heading>
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            {{-- Decision 12: counting is blind. Only a supervisor sees the recorded balance. --}}
            @if ($manages)
                <flux:table.column align="end">{{ $posted ? __('Recorded at posting') : __('Recorded now') }}</flux:table.column>
            @endif
            <flux:table.column>{{ __('Counted') }}</flux:table.column>
            @if ($manages)
                <flux:table.column align="end">{{ __('Difference') }}</flux:table.column>
                @unless ($posted)
                    <flux:table.column align="end">{{ __('Moved since opened') }}</flux:table.column>
                @endunless
                <flux:table.column>{{ __('Reason') }}</flux:table.column>
            @endif
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($review->lines as $row)
                @php($line = $row->line)
                <flux:table.row wire:key="count-line-{{ $line->id }}">
                    <flux:table.cell>{{ $line->part->part_number }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $line->part->description }}</flux:table.cell>
                    <flux:table.cell>{{ $line->part->defaultLocation?->code }}</flux:table.cell>
                    @if ($manages)
                        <flux:table.cell align="end">
                            {{ \App\Support\Quantity::display($posted ? $line->on_hand_at_post : $row->onHand) }}
                        </flux:table.cell>
                    @endif
                    <flux:table.cell>
                        @if ($counting)
                            <div class="flex items-center gap-2">
                                <flux:input
                                    size="sm"
                                    wire:model="counts.{{ $line->id }}"
                                    wire:keydown.enter.prevent="saveCount({{ $line->id }})"
                                    inputmode="decimal"
                                    class="max-w-28"
                                    :aria-label="__('Counted quantity of :part', ['part' => $line->part->part_number])"
                                />
                                <flux:button size="sm" wire:click="saveCount({{ $line->id }})">{{ __('Save') }}</flux:button>
                            </div>
                            <flux:error name="counts.{{ $line->id }}" />
                        @else
                            {{ \App\Support\Quantity::display($line->counted_qty) }}
                        @endif
                    </flux:table.cell>
                    @if ($manages)
                        <flux:table.cell align="end">
                            {{ \App\Support\Quantity::display($posted ? $line->posting_delta : $row->difference) }}
                            @if ($row->stale && $counting)
                                <flux:badge size="sm" color="amber">{{ __('Stale') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        @unless ($posted)
                            <flux:table.cell align="end">{{ \App\Support\Quantity::display($row->movedSinceOpened) }}</flux:table.cell>
                        @endunless
                        <flux:table.cell>
                            @if ($reviewing)
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:select size="sm" wire:model="reasons.{{ $line->id }}" class="max-w-44">
                                        <flux:select.option value="">{{ $row->needsReason ? __('Reason needed') : __('No reason') }}</flux:select.option>
                                        @foreach (\App\Enums\ReasonCode::cases() as $reasonCode)
                                            <flux:select.option :value="$reasonCode->value">{{ \Illuminate\Support\Str::headline($reasonCode->value) }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:input size="sm" wire:model="notes.{{ $line->id }}" :placeholder="__('Notes')" class="max-w-40" />
                                    <flux:button size="sm" wire:click="saveReason({{ $line->id }})">{{ __('Save reason') }}</flux:button>
                                    @if ($row->stale)
                                        <flux:button size="sm" variant="subtle" wire:click="acknowledge({{ $line->id }})">{{ __('Acknowledge') }}</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="subtle" wire:click="removeLine({{ $line->id }})">{{ __('Remove') }}</flux:button>
                                </div>
                                <flux:error name="reasons.{{ $line->id }}" />
                            @elseif ($line->reason_code)
                                {{ \Illuminate\Support\Str::headline($line->reason_code->value) }}
                            @endif
                        </flux:table.cell>
                    @endif
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($reviewing)
        <div class="mt-6">
            <flux:button variant="primary" wire:click="postSession">{{ __('Post count') }}</flux:button>
        </div>
    @endif
</section>
```

- [ ] **Step 5: Add the routes and the menu item**

In `routes/spims.php`, add `use App\Livewire\Counts;` to the imports and, after the adjustment route:

```php
    Route::livewire('counts', Counts\Index::class)->name('counts.index');
    Route::livewire('counts/{count}', Counts\Show::class)->whereNumber('count')->name('counts.show');
```

In `app/Support/Navigation.php`, add after `Transactions` (so before `Stock adjustment`):

```php
        ['group' => 'Storeroom', 'label' => 'Stock counts', 'icon' => 'clipboard-document-check', 'route' => 'counts.index', 'active' => 'counts.*', 'permission' => Permission::CountsEnter],
```

In `tests/Feature/Shell/AppShellTest.php`, the Custodian's menu becomes:

```php
        ->toBe(['Dashboard', 'Withdrawal station', 'Record a delivery', 'Paper form entry', 'Part lookup', 'Spare parts', 'Transactions', 'Stock counts'])
```

In `tests/Feature/Access/ScreenAccessMatrixTest.php`, add `use App\Models\CountSession;` and after
`'Stock adjustment'`:

```php
    'Stock counts' => ['counts.index', null, 'CS--'],
    'Stock count' => ['counts.show', fn () => ['count' => CountSession::factory()->create()], 'CS--'],
```

In `tests/Feature/Access/ActionReauthorizationTest.php`, add
`use App\Livewire\Counts\Index as CountList;`, `use App\Livewire\Counts\Show as CountScreen;` and
`use App\Models\CountSession;` to the imports and these rows at the end of the dataset. Every action
authorizes before it reads its argument:

```php
    'Counts\Index::openSession' => [
        Role::Supervisor, CountList::class, fn () => [], 'openSession', fn () => [],
    ],
    'Counts\Show::saveCount' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'saveCount', fn () => [0],
    ],
    'Counts\Show::markCounted' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'markCounted', fn () => [],
    ],
    'Counts\Show::saveReason' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'saveReason', fn () => [0],
    ],
    'Counts\Show::acknowledge' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'acknowledge', fn () => [0],
    ],
    'Counts\Show::removeLine' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'removeLine', fn () => [0],
    ],
    'Counts\Show::postSession' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'postSession', fn () => [],
    ],
    'Counts\Show::cancelSession' => [
        Role::Supervisor, CountScreen::class, fn () => ['count' => CountSession::factory()->create()], 'cancelSession', fn () => [],
    ],
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Counting tests/Feature/Shell tests/Feature/Access
```

Expected: PASS, 20 new tests in `CountScreensTest` (the role matrix is four, the supervisor-only
actions five), 12 new rows in `ScreenAccessMatrixTest` and 8 in `ActionReauthorizationTest`.
`LivewireActionNamesTest` passes: none of the new actions is named after the `$wire` API, and the
component uses no method called `fill`, which Livewire's `Component` already defines.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Livewire/Counts/Index.php resources/views/livewire/counts.blade.php app/Livewire/Counts/Show.php resources/views/livewire/counts/show.blade.php app/Support/Navigation.php routes/spims.php tests/Feature/Shell/AppShellTest.php tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/Access/ActionReauthorizationTest.php tests/Feature/Counting/CountScreensTest.php
git commit -m "Add the stock count screens: open, count blind, review and post"
```

---

## Task 11: The invariant test gains reversals and count postings

**Files:**
- Modify: `tests/Feature/Ledger/LedgerInvariantTest.php`

**Interfaces:**
- Consumes: `StockLedger::reverse()` (Task 3), `CountSessions` (Task 8), `StockLedger::postCount()`
  (Task 9).
- Produces: nothing new. This task only tests.

Design §13.1 lists six kinds of posting for the random sequence. Plans 1 and 3 generate four of them:
withdrawals with and without overrides, replenishments, adjustments, and backdated entries. This
task adds the last two:

- **Reversals.** One step in ten reverses a transaction the sequence completed earlier, picked at
  random. That can be a backdated entry, a withdrawal that carried an override, or an adjustment
  running both ways. A reversal that would take a balance negative must be refused, and one that
  would not must post.
- **Count postings.** One step in ten counts one to three parts. Half of those issue some stock while
  the session is open, before the shelf is counted, which is design §5.6.1's case. Then the step
  posts the count. After **every** count posting, each counted part's balance must equal its count
  exactly. That is design §13.1's companion test, asserted inside the generator, so it runs over every
  mix of earlier postings rather than one hand-built case.

The generator's model of the expected balances follows each step, and the end-of-sequence checks are
unchanged: the verifier, every balance, the running `balance_after` in id order, and backdating that
really reordered time. Two more checks make sure the sequence actually exercised both new postings.

- [ ] **Step 1: Replace the test with the extended generator**

Replace the whole of `tests/Feature/Ledger/LedgerInvariantTest.php` with:

```php
<?php

use App\Domain\Counting\CountSessions;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Enums\CountScope;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/*
 * Design §13.1: the single most valuable test in the system. A seeded random sequence of
 * withdrawals, replenishments, adjustments, backdated entries, reversals and count postings, checked
 * against an independent model of what every balance should be. A failure names its seed, so it can
 * be replayed exactly.
 */
it('keeps every balance equal to its ledger through random postings, backdating, reversals and counts', function (int $seed) {
    configureInstall();
    mt_srand($seed);

    $ledger = app(StockLedger::class);
    $counts = app(CountSessions::class);
    $custodian = User::factory()->create();
    $supervisor = userWithRoles(Role::Supervisor);
    $parts = Part::factory()->count(4)->create()->all();
    $expected = [];
    /** @var list<int> $reversible completed transactions a later step may reverse */
    $reversible = [];
    $reversals = 0;
    $countPostings = 0;

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
        $roll = mt_rand(1, 10);

        // Design §5.5: reverse a transaction completed earlier in the sequence.
        if ($roll === 1 && $reversible !== []) {
            $index = mt_rand(0, count($reversible) - 1);
            $original = StockTransaction::query()->with('lines')->findOrFail($reversible[$index]);
            $wouldGoNegative = false;

            foreach ($original->lines as $line) {
                $wouldGoNegative = $wouldGoNegative
                    || $expected[$line->part_id]->minus($line->signedQuantity())->isNegative();
            }

            try {
                $ledger->reverse($original, 'Invariant test', $supervisor);
                expect($wouldGoNegative)->toBeFalse("Seed {$seed}, step {$step}: reversed although a balance would go negative.");

                foreach ($original->lines as $line) {
                    $expected[$line->part_id] = $expected[$line->part_id]->minus($line->signedQuantity());
                }

                array_splice($reversible, $index, 1);
                $reversals++;
            } catch (InsufficientStock) {
                expect($wouldGoNegative)->toBeTrue("Seed {$seed}, step {$step}: reversal refused although stock was enough.");
            }

            $this->travel(mt_rand(1, 240))->minutes();

            continue;
        }

        // Design §5.6.1: count one to three parts, sometimes issuing stock while the session is open.
        if ($roll === 2) {
            $chosen = $parts;
            shuffle($chosen);
            $chosen = array_slice($chosen, 0, mt_rand(1, 3));

            $session = $counts->open(
                $supervisor,
                CountScope::Adhoc,
                partNumbers: array_map(fn (Part $part): string => $part->part_number, $chosen),
            );

            $take = BigDecimal::of(mt_rand(1, 3000))->dividedBy(1000, 3);

            if (mt_rand(0, 1) === 1 && ! $expected[$chosen[0]->id]->isLessThan($take)) {
                $this->travel(1)->seconds();
                $issued = $ledger->complete(
                    StockTransaction::factory()->withLines([[$chosen[0], (string) $take]])->create(['custodian_user_id' => $custodian->id]),
                    $custodian,
                );
                $expected[$chosen[0]->id] = $expected[$chosen[0]->id]->minus($take);
                $reversible[] = $issued->id;
            }

            // The shelf is counted after anything issued, so no count is stale.
            $this->travel(1)->seconds();
            $counted = [];

            foreach ($session->lines()->get() as $line) {
                $counted[$line->part_id] = BigDecimal::of(mt_rand(0, 40_000))->dividedBy(1000, 3);
                $counts->enterCount($session, $line, (string) $counted[$line->part_id], $supervisor);
                $counts->setReason($session, $line, ReasonCode::Miscount, null, $supervisor);
            }

            $counts->markCounted($session, $supervisor);
            $ledger->postCount($session, $supervisor);

            // Design §13.1's companion: after every count posting, each counted balance is its count.
            foreach ($counted as $partId => $quantity) {
                expect(PartStock::query()->findOrFail($partId)->on_hand)
                    ->toBe((string) $quantity->toScale(3), "Seed {$seed}, step {$step}: part {$partId} after its count");

                $expected[$partId] = $quantity;
            }

            $countPostings++;
            $this->travel(mt_rand(1, 240))->minutes();

            continue;
        }

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

            // The withdrawal may be reversed later; its override adjustments may not (design §5.5).
            $reversible[] = $draft->id;
            $this->travel(mt_rand(1, 240))->minutes();

            continue;
        }

        try {
            $ledger->complete($draft, $custodian);
            expect($wouldGoNegative)->toBeFalse("Seed {$seed}, step {$step}: posted although stock was short.");

            foreach ($lines as [$part, $quantity, $direction]) {
                $expected[$part->id] = $expected[$part->id]->plus(BigDecimal::of($quantity)->multipliedBy($direction));
            }

            $reversible[] = $draft->id;
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

    // Both outcomes of an override, and both of this plan's postings.
    expect(WithdrawalOverride::query()->whereNotNull('adjustment_transaction_id')->exists())->toBeTrue("Seed {$seed}: no override posted")
        ->and(WithdrawalOverride::query()->whereNotNull('discarded_at')->exists())->toBeTrue("Seed {$seed}: no override discarded")
        ->and($reversals)->toBeGreaterThan(0, "Seed {$seed}: no reversal posted")
        ->and($countPostings)->toBeGreaterThan(0, "Seed {$seed}: no count posted");
})->with([1, 2, 3]);
```

- [ ] **Step 2: Run the test**

```bash
php artisan test tests/Feature/Ledger/LedgerInvariantTest.php
```

Expected: PASS, 3 tests (seeds 1, 2 and 3). The random sequence differs from the one Plan 3 ran,
because the new steps draw from the same seeded generator.

If a seed fails, the message names the seed and step. First replay it and decide which is wrong,
the model or the ledger. A model error is fixed in the test. A ledger error is a defect in Task 3 or
Task 9: fix it there, add a focused test for the case to that task's test file, and record it in this
task's report. Never change the seed list to make the test pass.

If `$reversals` or `$countPostings` is zero for a seed, the sequence never reached that step. Check
`$roll` and the `$reversible` list before anything else.

- [ ] **Step 3: Run the full check and commit**

```bash
composer test
git add tests/Feature/Ledger/LedgerInvariantTest.php
git commit -m "Add reversals and count postings to the ledger invariant test"
```

---

## Done When

- [ ] Every task is committed, one commit per task, and `composer test` is green at every commit:
      Pint, PHPStan level 7 and the full Pest suite. The committed suite
      (`php artisan test tests/Committed`, after `php artisan config:clear`) is green too.
- [ ] The roadmap's key tests exist and pass:
  - reversing a withdrawal leaves its month's consumption at zero — `ReversalTest`
  - a reversal cannot be reversed — `ReversalTest`
  - a count posting with withdrawals during the session never breaks `on_hand >= 0` —
    `CountPostingTest` and the generator
  - a stale count blocks posting until it is counted again or acknowledged — `CountPostingTest`
  - the backdating limit applies per role — `BackdatedEntryServiceTest`, `PaperFormScreenTest`
  - the same paper form cannot be entered twice — `BackdatedEntryServiceTest`
- [ ] Each Review Focus line has its test: Task 3 (reversing unusual transactions), Task 7 (the
      password never kept), Task 6 (nothing left behind, and the limit around midnight), Task 9 (a
      count longer than one chunk).
- [ ] **A person walks the new screens in a browser**, on a scratch copy of the demo install, with
      the keyboard where the screen is meant for it. An agent does not drive a browser with stored
      passwords: hand the walk to the user. Check each:
  1. As a supervisor, **Stock adjustment**: choose a part by number, decrease it with Damage, see the
     resulting balance, confirm, and open the linked record. Then try a decrease below zero: the
     screen warns before Confirm and refuses on it.
  2. **Transactions**: find a withdrawal by number and open it. **Reverse transaction** shows each line
     with the balance now, the change and the balance after. Give a reason and confirm. The record
     says Reversed, by whom and why, and links to the reversing transaction. That transaction links
     back.
  3. The reversing transaction, an opening balance and a count adjustment each show why they cannot
     be reversed, with no Reverse button.
  4. **Returning unused parts** (Decision 4): reverse a withdrawal, then record the part actually used
     at the withdrawal station with the person's badge.
  5. As a custodian, the withdrawal station's **Backdated entry** opens Paper form entry on a
     withdrawal. Enter a form from two days ago with a person, equipment, purpose and two lines, and
     record it. The record shows both times, the form number, the reason, and "From the signed paper
     form".
  6. Enter the same form number again: it is refused, naming the first entry.
  7. Enter a form from ten days ago as a custodian. The supervisor fields appear. A wrong password is
     refused and the field is empty afterwards. The right one records the form, and its record says
     who authorised it.
  8. The delivery screen's **Backdated entry** opens the form on a delivery. On the delivery screen, a
     date received yesterday is refused with a message naming Paper form entry.
  9. As a supervisor, **Stock counts**: open a count of one storage location. As a custodian, open it.
     There is no recorded balance or difference. Count each line with the keyboard, pressing Enter,
     then **Mark counted**.
  10. As a supervisor: the differences, the lines needing a reason, and the summary. Issue one of the
      counted parts at the station. Back on the count, that line says Stale. Acknowledge it, or have it
      counted again. Give the reasons, **Post count**, and follow the link to the adjustment. Every
      counted balance now equals its count, and that adjustment cannot be reversed.
  11. Open another count and cancel it. It is kept as Cancelled, and no stock moved.
  12. As a Planner and as a System Administrator: the sidebar shows Transactions, but not Paper form
      entry, Stock counts or Stock adjustment. `/counts`, `/station/paper-form` and
      `/corrections/adjustment` return 403.
- [ ] The roadmap's Plan 4 row is updated, and the CLAUDE.md "Current state" says Plan 4 is merged.

## Notes for Later Plans

Carry these to the plan named, in this plan's ledger under `.superpowers/sdd/`.

**Plan 5 (analytics, prediction, alerts and dashboard).**

- **Two count events are waiting.** Open `inventory_discrepancy` alerts on `CountSessionCounted`
  (FR-SPIMS-013 step 6). Use `CountSessions::review()` to find each line whose difference needs a
  reason under the session's tolerance, since that is the rule for "above tolerance". Resolve them on
  `CountSessionPosted` (step 10).
- **`StockTransactionCompleted` now also fires for** manual adjustments, reversing transactions,
  paper-form entries and count adjustments. Alert evaluation over the event's parts covers all of
  them. A backdated entry's movements carry a past `occurred_at`, so consumption for an earlier
  period changes after the event, and the event is the only moment to notice that.
- The dashboard's "today's counts" tile reads `count_sessions` by `opened_at` in the install time zone.

**Plan 6 (reports and exports).**

- **The Exceptions report (J) and the daily exceptions summary** read:
  - backdated entries: `is_backdated`, with `backdate_authorised_by_user_id` set for every entry
    beyond the limit. FR-SPIMS-012 puts every backdated entry in the daily summary.
  - reversals: `status = 'reversed'` with `reversed_by_user_id` and `reversal_reason` on the
    original, and the reversing transaction through `reverses_transaction_id`
  - adjustments: `reason_code`, and `count_session_id` for count postings
  - count differences: `count_lines.posting_delta` with its reason
- Movement history shows `count_variance` movements. The transaction record component
  (`<x-transaction-record>`) is reused rather than rebuilt.
- The keyboard-only browser test is still owed (Plan 3's note). Count entry by Enter is a good second
  case.
- Count sheets to print, and export of a session, are report work and belong here.

**Plan 7 (operations and go-live).**

- **A count posting holds the stock lock of every counted part until it commits.** A full count of a
  large storeroom should be posted outside a busy shift. Stations waiting on it fail with
  `StockBusy` after `spims.lock_timeout`.
- `lockPartBalances()` binds one parameter per part, so a single session of more than 65,535 parts
  cannot be posted. Microtech's catalogue is far smaller; a customer install with a larger one would
  need the lock taken through a subquery.

**Questions for Microtech**, recorded in this plan's Decisions:

- Decision 8: a backdated withdrawal that the recorded balance cannot cover is refused until a
  supervisor corrects the balance, rather than going through the FR-SPIMS-008 override.
- Decision 9: a paper form naming a person, machine, purpose or part deactivated since is refused
  until it is reactivated.
- Decision 12: custodians count blind.
- Q2 (count cadence) and Q13 (returns of unused parts, answered for now by Decision 4).
