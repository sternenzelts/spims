# SPIMS for Microtech — Implementation Roadmap

| | |
|---|---|
| **Scope** | Microtech production and demo installs of SPIMS. Customer installs stay parked (PRD Appendix A.2). |
| **Requirements** | `docs/prd/Microtech_SPIMS_PRD_v1.0.md` |
| **Design** | `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md` |
| **Stack** | PHP 8.4+ · Laravel 13 · Livewire 4 + Flux 2 · PostgreSQL 17 (Supabase free tier when hosted) · Pest 5 · PHPStan level 7 |
| **Date** | 2026-09-13 |
| **Classification** | Confidential — for Microtech use only |

---

## 1. How the Work Is Split

SPIMS is built in **seven plans**. Each plan ends with software that runs, passes `composer test`, and can
be demonstrated or reviewed on its own. Plans run in order, because each builds on the tables,
services and permissions of the plans before it.

**Only Plan 1 is written in full.** The detailed plan for each later plan is written when the plan before
it is merged, against the code as actually built, so its class names, method signatures and test
helpers are real rather than predicted. This roadmap fixes what each plan delivers, which requirements
it covers, and which decisions it must settle.

| # | Plan | A user can… | PRD | Design §14 milestone | Status |
|---|---|---|---|---|---|
| 1 | [Foundation and Stock Ledger](2026-09-13-spims-plan-01-foundation-ledger.md) | sign in; nothing stock-related on screen yet, but the ledger is proven | NFR-001, 003, 005, 013; domain parts of FR-004, FR-018 | M1 | Detailed plan written |
| 2 | Administration and Master Data | manage users, install settings, locations, equipment, parts, stock levels and personnel with badges; import parts and opening balances | FR-001–005, FR-018; NFR-004, 010 | M1, M3 | Outline |
| 3 | Withdrawal and Replenishment at the Station | issue parts with the scan-last flow, including overrides and manual identification; receive stock | FR-006–009; FR-016 (lookup); NFR-006, 007 (drafts), 009 | M2, M3 | Outline |
| 4 | Corrections and Counting | adjust stock, reverse transactions, enter paper-fallback transactions, run physical counts | FR-010–013 | M3, M6 | Outline |
| 5 | Analytics, Prediction, Alerts and Dashboard | see consumption, depletion and predicted stock-outs; receive and acknowledge alerts; use the dashboard | FR-014, FR-015, §10 notifications, FR-017 (dashboard) | M4 | Outline |
| 6 | Reports and Exports | run reports A–K and export them; view movement history | FR-016, FR-017 (reports); NFR-003 (export audit) | M5 | Outline |
| 7 | Operations and Go-Live | load demo sample data; rely on nightly backups, verification and keep-alive; deploy both installs to Supabase | FR-019; NFR-007, 008, 012, 013 | M6 + proposal Phase 4 | Outline |

**Rules for every plan:**

- The Global Constraints in Plan 1 apply to every plan: PostgreSQL only, BigDecimal quantities, UTC storage, the ledger invariant, audited configuration changes, and no company-specific values in code.
- Every stock change goes through `App\Domain\Ledger\StockLedger`. A plan that needs a new kind of posting extends `StockLedger` and adds that posting to the invariant test.
- Every action checks a `Permission` from PRD §8 through a policy or `can()`, and every plan includes role-by-action feature tests.
- Test-driven, one commit per task, `composer test` green at every commit.
- When a plan is merged, update its row in the table above and write the next plan's detailed version.

---

## 2. Plan 1 — Foundation and Stock Ledger

Written in full: [2026-09-13-spims-plan-01-foundation-ledger.md](2026-09-13-spims-plan-01-foundation-ledger.md).
It has 11 tasks: scaffold and CI, audit trail, roles and first administrator, install settings and
purposes, master data schema, personnel and badges, ledger schema, transaction numbering,
`StockLedger`, ledger verification, and concurrency.

**What later plans build on:**

| Interface | Used by |
|---|---|
| `StockLedger::complete(StockTransaction, User): StockTransaction` and its exceptions (`InsufficientStock`, `StockBusy`, `TransactionNotDraft`, `InstallNotConfigured`, `OpeningBalanceAlreadyRecorded`) | Plans 2, 3, 4, 7 |
| `StockTransactionCompleted` event (dispatched after commit) | Plan 5 alert evaluation |
| `InstallSettings` (`get`, `set`, typed getters, `DEFAULTS`) | Every plan |
| `AuditLogger::record()` | Every plan |
| `PersonnelBadges::enroll()` / `identify()`, `BadgeIdentity::mask()` | Plans 2, 3, 6 |
| `Role` / `Permission` enums, seeded by `RolesAndPermissionsSeeder` | Every plan |
| `TransactionNumberer::next()` | Plans 3, 4 |
| `LedgerVerifier::verify()`, `ledger:verify` | Plans 4, 7 |
| Test helpers `inSavepoint`, `configureInstall`, `partWithStock`, `onHand`, `postConcurrently`; the `tests/Committed` suite | Every plan |

**Design doc amendments made with Plan 1** (commit them together with the design doc):

1. The stack line reads Laravel 13 · Livewire 4 · PHP 8.4+.
2. §4.2: primary keys are `bigserial` (`$table->id()`), not identity columns.
3. §4.3:
   - `stock_transactions.transaction_no` is nullable until completion, with a `posted_is_numbered` check.
   - `stock_transaction_lines` has a `direction` column (-1 or 1).
   - `audit_logs.auditable_id` is `text`.
   - The trigger function is `prevent_append_only_mutation()` and is shared with `audit_logs`.
   - A partial unique index allows one opening movement per part.
   - A trigger creates each part's `part_stock` row.
   - A `transaction_number_sequences` table is added.
4. §8.2: badge UIDs are hashed with HMAC-SHA256 and a per-install pepper. The masked form shows at most five leading characters and always hides the last four.

---

## 3. Plan 2 — Administration and Master Data

**Outcome.** A System Administrator provisions an install through screens alone:

- creates user accounts with roles
- sets the company profile, time zone, purposes, prefixes and thresholds
- creates storage locations and equipment
- imports spare parts and opening balances with a dry run
- registers personnel and enrolls badges

This covers steps 4–8 of the design §3.6 provisioning checklist.

**Requirements.** FR-SPIMS-001 to 005 and FR-SPIMS-018; NFR-004 (sign-in lockout, masked badges) and NFR-010. Design §3.4, §3.6, §4.4, §5.7 and §8.2.

**Scope.**

- **Application shell:**
  - A Flux sidebar layout with a role-aware menu.
  - `APP_INSTALL_TYPE=demo|production` in `config/spims.php`.
  - The Demo banner on every screen (FR-019 rule).
  - A setup prompt for System Administrators while the company name is missing.
- **Users and roles:**
  - A user list and form: create, edit, deactivate, and assign roles.
  - An administrator cannot remove their own admin role.
  - Every change is audited.
  - Login throttling: temporarily lock sign-in for 15 minutes after repeated failures (NFR-004), configured in the Fortify `login` rate limiter.
- **Install Settings screen**, validating per FR-018:
  - Company name, logo on the configured disk, and address.
  - Time zone and currency changes require confirmation.
  - Withdrawal purposes can be renamed or deactivated, never deleted once used, and at least one must stay Active.
  - Prefixes use letters only and must be unique.
  - Thresholds must be greater than zero, except the count tolerance.
  - Notification recipients.
- **Storage locations and equipment** (FR-003): records are deactivated rather than deleted once referenced.
- **Spare parts** (FR-001):
  - Search by exact or prefix part number and by description (`pg_trgm`), within 150 ms at p95 (NFR-006).
  - A custodian may create a part only when receiving it, and may edit only basic fields.
  - Equipment associations through `part_equipment`.
- **Stock levels and criticality** (FR-002):
  - A planner-only form.
  - It warns, without refusing, when `critical ≤ min ≤ reorder ≤ max` is violated (design §4.4).
  - Changes are audited with old and new values.
- **Personnel and badges** (FR-004):
  - A registration form and a scan field for the keyboard-wedge reader.
  - Masked badge everywhere; the full badge only with `BadgesViewFull`.
  - Deactivation rather than deletion.
  - Photo upload.
- **Import** (FR-005), using `maatwebsite/excel` 4.x:
  - The `import_batches` table (design §4.3).
  - Template download, upload, column mapping, row validation and a dry-run preview. Confirming writes parts and opening balances through `StockLedger` as `opening` transactions, chunked into batches of lines.
  - Column permissions: stock levels are admin/planner only; opening quantities are admin only.
  - A queue job for large files.
- Policies for every model above, mapped to `Permission`.

**Key tests.**

- A role-by-action matrix over every screen and action.
- Import:
  - A dry run writes nothing.
  - Duplicate part numbers (case-insensitive) are reported per row.
  - An opening balance for a part that already has movements is refused.
  - A confirmed import leaves `ledger:verify` clean.
- Changing a prefix leaves existing numbers unchanged.
- A used purpose cannot be deleted.
- A badge already held by an Active person is refused with the holder's name.
- The login lockout releases after 15 minutes (time travel).

**Decisions to settle when writing the detailed plan.**

- The import column contract and template file. This needs the Q18 answer on Microtech's starting parts list.
- The batch size for opening-balance transactions, so a 5,000-part import does not hold thousands of row locks in one transaction.
- The file storage disk for logos and photos: local during development, S3-compatible when hosted (Plan 7).

**Open questions that affect it** (defaults apply until answered): Q5 purposes, Q6 equipment list, Q8 stock level semantics, Q10 parts on several machines, Q18 starting parts list.

---

## 4. Plan 3 — Withdrawal and Replenishment at the Station

**Outcome.** At the station, a custodian:

- enters parts by keyboard or scanner
- sets equipment and purpose
- reviews warnings
- has the requester scan their badge last, and confirms

A supervisor authorizes an insufficient-stock override or manual identification at the counter. The
custodian also records deliveries. All of this works with the keyboard and badge reader alone.

**Requirements.** FR-SPIMS-006 to 009 and the part lookup of FR-016; NFR-005, NFR-006 (a 10-line confirmation under 500 ms at p95), NFR-007 (drafts survive connection interruptions) and NFR-009. Design §5.1–§5.4 and §10.

**Scope.**

- **`App\Domain\Withdrawals\WithdrawalService`:**
  - Create a draft; add a line, merging duplicates of the same part; set equipment or purpose per line; remove a line; cancel.
  - Review warnings for lines that exceed stock or fall to or below the minimum or critical level.
  - Enforce remarks when the purpose requires them, and Active parts, equipment and purposes only.
- **Identification:**
  - `identify(draft, rawBadge)` uses `PersonnelBadges::identify()`.
  - The badge lookup is rate limited, and rejected scans are audited as `badge_scan_rejected`.
  - An identification expires after the `withdrawal.identification_timeout_seconds` setting.
  - Any change to a line, the equipment or the purpose clears the identification.
- **Manual identification** (pending Q12): a supervisor re-enters their own credentials at the station without replacing the custodian's session, and gives a reason. The withdrawal is marked `supervisor_manual`.
- **Insufficient-stock override** (FR-008, design §5.3):
  - The supervisor's authorization and verified quantity are stored on the draft.
  - `StockLedger` gains an override posting. It computes `adjustment = verified_qty − on_hand_locked` under the same lock, allows the adjustment only to increase stock, and discards it if the locked balance already covers the line.
  - The adjustment and the withdrawal are two transactions in one database commit.
  - It dispatches a `StockDiscrepancyReported` event, which Plan 5 turns into an `inventory_discrepancy` alert.
- **`App\Domain\Replenishments\ReplenishmentService`** (FR-009): supplier, DR/PO reference and unit cost; Active parts only.
- **Station screens** (Livewire):
  - Withdrawal, keyboard-first with a focused scan field and explicit Enter handling.
  - Replenishment.
  - Part lookup with description, location and on-hand quantity.
  - `StockBusy` and `InsufficientStock` shown as clear messages naming the parts.
  - Drafts persisted as rows, so a reload resumes the cart.
- A confirmation receipt screen that shows the FR-007 transaction record fields.

**Key tests.**

- Confirm the whole scan-last flow.
- A scan after a change is required again.
- An expired identification is refused.
- Inactive personnel are refused.
- Every override path from design §5.3, including the replenishment committed between authorization and confirmation.
- A concurrent override and withdrawal (`postConcurrently`).
- A keyboard-only browser test with the reader simulated as keystrokes plus Enter.
- A latency check on a 10-line confirmation.

**Decisions to settle when writing the detailed plan.**

- Schema additions for draft identification (`identified_at`) and override authorization (per line or its own table).
- How a supervisor authenticates at the counter: password re-entry or a supervisor badge.
- The browser-testing tool for keyboard flows (Pest browser plugin or Playwright).

**Open questions that affect it:** Q1 badge technology (reader input format), Q11 supervisor availability at night, Q12 manual identification.

---

## 5. Plan 4 — Corrections and Counting

**Outcome.** Supervisors correct stock through adjustments and reversals. Custodians enter
transactions from paper fallback forms after an outage. The storeroom runs physical counts that make
balances equal what is on the shelf.

**Requirements.** FR-SPIMS-010 to 013; NFR-001 and NFR-005. Design §5.5, §5.6 and §8.4.

**Scope.**

- **`AdjustmentService`** (FR-010): supervisor only, with a reason code and a direction per line. Inactive parts can be adjusted.
- **Reversal** (FR-011), a new `StockLedger` posting:
  - Writes equal and opposite movements that keep the original `movement_type` and `occurred_at`.
  - The original becomes `reversed`; the reversing transaction links through `reverses_transaction_id`.
  - Opening balances, reversals and adjustments created by overrides or counts cannot be reversed.
  - `guardDirections()` accepts the opposite direction on reversing transactions.
- **Backdated entry** (FR-012):
  - Captures the paper form number (unique) and the true occurred time, entered in the install time zone and stored in UTC.
  - Custodians may enter within `backdating.limit_days`; older entries need supervisor authorization.
  - `identification_method = paper_form`.
- **Physical counts** (FR-013):
  - The `count_sessions` and `count_lines` tables (design §4.3).
  - Open a session by scope, which snapshots `system_qty`.
  - Enter counts, which stamps `counted_at`.
  - Mark the session counted.
  - Review with the reason rule of §5.6.2 and the staleness guard of §5.6.1.
  - Post, supervisor only, through a `StockLedger` count posting: `posting_delta = counted_qty − on_hand_at_post` under lock, with `count_variance` movements.
  - Cancel.
- The invariant test generator gains reversals and count postings, and a companion test asserts that `on_hand == counted_qty` after every posting.

**Key tests.**

- Reversing a withdrawal leaves consumption for its original month at zero.
- A reversal cannot be reversed.
- A count posting with withdrawals during the open session never violates `on_hand >= 0`.
- A stale count blocks posting until it is recounted or acknowledged.
- The backdating limit applies per role.
- The same paper form cannot be entered twice.

**Decisions to settle when writing the detailed plan.**

- How a transaction records its origin (`manual`, `override` or `count`), so non-reversible adjustments are identifiable.
- Which transaction type count postings use, since `stock_transactions.type` has no `count` value yet.
- The interim return-to-stock procedure (reverse, then re-record) until Q13 is answered.

**Open questions that affect it:** Q2 count cadence, Q13 returns of unused parts.

---

## 6. Plan 5 — Analytics, Prediction, Alerts and Dashboard

**Outcome.** Planners and managers see consumption, depletion, movement class and predicted stock-out
dates with confidence levels. Alerts open once, auto-resolve when their condition clears, and arrive in
a daily digest. The dashboard is the landing page.

**Requirements.** FR-SPIMS-014, FR-SPIMS-015, the PRD §10 notifications and the dashboard of FR-017; NFR-002 and NFR-006 (page load under 2 s). Design §6, §7 and §9.2.

**Scope.**

- **`App\Domain\Analytics\ConsumptionCalculator`**, pure and table-driven:
  - The proposal's average monthly consumption formula, verbatim.
  - Average daily consumption over the analysis window, shortened for parts with less history.
  - Confidence by event count (§6.3).
  - Movement class by withdrawal event count, with the 90-day minimum history (§6.4).
  - Days of cover.
  - Prediction rate `max(window_ADC, recent_ADC with ≥ 3 events)`, the predicted stock-out date, and risk (`late`, `order_now`, `ok`) against lead time (§6.6).
  - Periods in the install time zone.
- Queries read `stock_movements` through the partial withdrawals index. There is no rollup table until p95 exceeds 2 s (§6.5).
- **`alerts` table and `AlertEvaluator`:**
  - Six alert rules as pure functions.
  - The partial unique index keeps one alert open per part and type.
  - At most one stock-level alert per part, the most severe.
  - Auto-resolution when the condition clears.
  - Evaluation runs after `StockTransactionCompleted` (queued, affected parts only) and in a nightly sweep of all Active parts.
  - `StockDiscrepancyReported` (Plan 3) and count postings (Plan 4) open and resolve `inventory_discrepancy`.
- Acknowledgement: stock-level alerts with `StockAlertsAcknowledge`, every other type with `AlertsAcknowledge`.
- **Notifications:**
  - A daily alert digest and a daily exceptions summary to the recipients in settings.
  - Instant email per alert type, off by default (Q14).
- **Dashboard** (§9.2): open alerts by severity, parts below reorder point and critical level, today's counts, the last ten transactions and the top consumed parts, each tile linking to its report.

**Key tests.**

- Boundary tables for every formula: zero consumption, a single event, and a window longer than the history.
- The high-consumption rule never compares a 30-day total with a daily rate.
- An alert is not duplicated across 40 commits.
- A worsening stock level resolves the current alert and opens the more severe one.
- A predicted stock-out crosses into `order_now` overnight with no transaction.
- The dashboard permissions.

**Open questions that affect it:** Q4 digest recipients, Q8 stock level semantics (possible merge of the Low Stock and Replenishment alerts), Q14 instant critical email, Q20 lead time defaults.

---

## 7. Plan 6 — Reports and Exports

**Outcome.** Every role sees the reports PRD §8 allows, filters them, and exports them to CSV, XLSX or
PDF with the company header and, on demo installs, the Demo label. Every part has a full movement history.

**Requirements.** FR-SPIMS-016 and FR-SPIMS-017 (reports A–K); NFR-003 (exports are audited) and NFR-006. Design §9 and §9.1.

**Scope.**

- One shared filter component and one export pipeline:
  - `maatwebsite/excel` for CSV and XLSX; `barryvdh/laravel-dompdf` 3.x for PDF.
  - Exports above a row threshold are generated on the queue to the configured disk and delivered as a signed link that expires after 7 days.
  - Every export writes an audit entry.
- Reports:
  - A Current Inventory
  - B Withdrawal
  - C Monthly Consumption
  - D Depletion Rate
  - E Low Stock
  - F Critical Spare Parts
  - G Personnel Withdrawal History
  - H Equipment Consumption
  - I Replenishment
  - J Exceptions (backdated, manual identification, overrides, reversals, adjustments)
  - K Predicted Out-of-Stock
- Custodians see reports A, E and F only (`StockReportsView`).
- **Stock balance and movement history** (FR-016): the running balance in recording order, with the occurred and recorded times shown together for backdated rows.
- Badge identifiers are masked on every report and export.

**Key tests.**

- Each report against a fixed ledger fixture.
- Reports C and D agree for the same part and window (design §6.2).
- A reversed withdrawal nets to zero in its original month.
- Periods follow the install time zone.
- Export permissions and audit entries.
- A large export is queued.

**Open questions that affect it:** Q4 scheduled report emails, Q9 Report I.

---

## 8. Plan 7 — Operations and Go-Live

**Outcome.** Microtech's production and demo installs run on Supabase:

- nightly off-platform backups, with a restore drill completed
- nightly ledger verification that notifies administrators on failure
- a daily keep-alive, so the demo project does not pause
- demo sample data loaded
- a written deployment and provisioning runbook

**Requirements.** FR-SPIMS-019; NFR-007, NFR-008, NFR-012 and NFR-013. Design §3.3 (RLS), §3.6, §11, §12.1 and §13.5.

**Scope.**

- **Sample data** (FR-019):
  - A loader that refuses to run unless `APP_INSTALL_TYPE=demo`.
  - Fictional personnel and several months of withdrawals, replenishments, adjustments and counts, written through the domain services so the ledger stays consistent.
  - A reset that truncates tables (the only action that removes stock movements); it is confirmed and audited.
- **Backups:**
  - A nightly `pg_dump` over the session connection (port 5432) to an off-platform disk (Q3).
  - Retention of 30 daily and 12 monthly backups.
  - Failure notification to System Administrators.
  - A restore runbook, and one completed restore drill.
- A daily heartbeat query on both installs (Supabase pauses projects after a week of low activity), and an administrator notification when `ledger:verify` fails.
- **Supabase configuration:**
  - The application uses the transaction pooler (port 6543) with emulated prepares.
  - A direct connection is used for migrations and backups.
  - `sslmode=require`.
  - A migration that enables deny-all RLS and runs only when the `anon` and `authenticated` roles exist, so plain Postgres installs skip it (design §3.3, NFR-012).
- **Deployment runbook** for the host chosen in Q15:
  - A queue worker and scheduler cron.
  - Mail and the S3-compatible disk.
  - Environment variables, including `BADGE_PEPPER` generated per install.
  - The provisioning checklist of design §3.6.
- **CI install tests** (design §13.5):
  - A fresh install on an empty plain Postgres database: migrate, seed, create an administrator, then a smoke suite.
  - A seeder guard test.
  - A "no hard-coded company values" test that changes time zone, currency, prefixes and purposes.
- A confirmation-latency and lookup-latency check run against the hosted database (NFR-006).

**Key tests.** Everything in the design §13.5 list, plus:

- Sample data is refused on production.
- Backup retention pruning.
- The heartbeat is scheduled on both installs.

**Open questions that affect it:** Q3 backup destination, Q15 application hosting. Supabase's 2-project free limit is used up by production and demo, so staging needs the Pro plan or local Postgres (design §11).

---

## 9. Microtech Go-Live Track (Outside the Code)

These run alongside the plans, following design §14 (proposal Phases 1, 3, 4 and 5).

| When | Item | Owner | PRD question |
|---|---|---|---|
| Before Plan 2 is finished | Confirm the starting parts list and its columns | Microtech storeroom | Q18 |
| Before Plan 2 is finished | Equipment list, or plan to build it | Microtech maintenance | Q6 |
| Before Plan 3 is finished | Badge technology; buy a keyboard-wedge reader (13.56 MHz MIFARE DESFire if badges are new) | Microtech IT | Q1 |
| Before Plan 3 is finished | Supervisor coverage on night shifts | Microtech storeroom | Q11, Q12 |
| Before Plan 5 is finished | Stock level definitions, digest recipients, lead time defaults | Microtech management / storeroom | Q4, Q8, Q14, Q20 |
| Before Plan 7 is finished | Application hosting; backup destination and access | Engineering / Microtech IT | Q15, Q3 |
| Plan 7 | Create the Supabase production and demo projects; provision both installs | Engineering | — |
| After Plan 7 | Import real data with a dry run; full verification count | Microtech storeroom | Q2 |
| After Plan 7 | UAT against proposal §4 and §7; restore drill; concurrency and invariant suites green | Engineering + storeroom | — |
| After Plan 7 | Custodian training (all shifts); requester briefing; two-week parallel run with paper; go-live | Microtech | Q7, Q16 |

---

## 10. Design Gaps Found While Planning

These are deliberately left to the plan that owns them, and recorded here so they are not rediscovered:

| Gap | Owner |
|---|---|
| Where a draft stores its identification time and a supervisor override authorization | Plan 3 |
| How a supervisor authenticates at the station without replacing the custodian's session | Plan 3 |
| The inventory discrepancy alert is raised before the `alerts` table exists; resolved with an event that Plan 5 handles | Plans 3 → 5 |
| A transaction origin marker, so override and count adjustments are identifiable as non-reversible | Plan 4 |
| The transaction type used by count postings | Plan 4 |
| The batch size for opening-balance imports | Plan 2 |
