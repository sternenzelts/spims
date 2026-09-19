# SPIMS Plan 2: Administration and Master Data — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A System Administrator provisions an install through screens alone: user accounts and roles, install settings and withdrawal purposes, storage locations, equipment, spare parts with their stock levels, and personnel with enrolled badges. Every change is audited and every screen is gated by a PRD §8 permission.

**Architecture:** Livewire 4 class components built from stock Flux 2 components, one page component per screen under `app/Livewire/{Admin,Users,MasterData,Parts,Personnel}`. Business rules live in small domain services under `app/Domain/{Access,Settings,MasterData,Parts,Identity}`, which validate, write and audit. Components validate input, authorize a `Permission`, and call a service. This plan writes no stock. New parts start at zero through Plan 1's `parts_create_stock_row` trigger, and opening balances arrive with the import in Plan 2b.

**Tech Stack:** as Plan 1. PHP 8.4+, Laravel 13, Livewire 4.4 with Flux 2.19 (free components only), Fortify, spatie/laravel-permission 8, brick/math, PostgreSQL 17, Pest 5, Larastan (PHPStan level 7) and Pint. No new Composer packages.

**Related documents:** roadmap `docs/superpowers/plans/2026-09-13-spims-microtech-roadmap.md` · design `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md` · requirements `docs/prd/Microtech_SPIMS_PRD_v1.0.md` · Plan 1 `docs/superpowers/plans/2026-09-13-spims-plan-01-foundation-ledger.md` (conventions and test helpers)

## Global Constraints

Plan 1's constraints hold unchanged:

- **PHP `^8.4`.** Laravel 13 accepts 8.3, but Pest 5 requires 8.4. Do not lower the Composer constraint. CI runs PHP 8.4 and 8.5.
- **PostgreSQL 17** for development, tests and CI. Never SQLite.
- **`composer test` passes before every commit.** It runs Pint (`laravel` preset), PHPStan level 7 over `app/ bootstrap/app.php config/ database/ routes/`, then the whole Pest suite. Never add `@phpstan-ignore` comments or baseline entries. Local PHP is capped at `memory_limit=128M`; `composer types:check` already passes `--memory-limit=512M`. In an agent shell, run `env -u CLAUDECODE -u AI_AGENT composer test` for the normal report instead of JSON.
- **Schema conventions:**
  - `text`, never `varchar`.
  - `timestamptz` at full precision. Never pass a precision to `timestampTz()`.
  - `numeric(14,3)` for quantities.
  - `text` + `CHECK` instead of enums.
  - Explicitly named indexes.
  - `SchemaConventionsTest` enforces the first two.
- **Quantities are decimal strings in PHP** (`decimal:3` casts), with arithmetic in `Brick\Math\BigDecimal`. Never cast a quantity to `float` or `int`.
- **The ledger invariant:** for every part, `part_stock.on_hand = SUM(stock_movements.quantity)`. Only `StockLedger` writes stock. Nothing in this plan writes stock.
- **No company-specific values in code** (design §3.6). Read them from `InstallSettings`.
- **The morph map is enforced.** Every model that is audited is listed in `AppServiceProvider::configureMorphMap()`. Every model this plan audits is already listed there.
- **Tests:**
  - Use Pest function syntax.
  - A test that expects a database error wraps the failing call in `inSavepoint()`.
  - Tests whose writes must commit live in `tests/Committed`.
- **Commits:** one per task, with the message given in the task, staged by path. Never `git add -A` or `git add .`.

Added by this plan:

- **Install type is environment configuration.** `APP_INSTALL_TYPE` (`demo` or `production`) is read only through `App\Enums\InstallType::current()`. It is never a setting and never editable on a screen (design §3.6 rule 5, PRD FR-SPIMS-019).
- **UI: Flux 2 free components on Livewire 4, with no custom styling.** The app's visual direction is parked (CLAUDE.md, "Design work"). Screens use stock Flux layout, so a later `impeccable` pass restyles them without rework. Ignore React-library suggestions from any design skill.
- **Every screen authorizes twice.** `mount()` calls `$this->authorize(Permission::…)` and so does every action that changes data. Livewire actions do not re-run route middleware reliably, and tests call components directly. `authorize()` and `can()` accept the `Permission` enum.
- **Every screen has a role-by-action test** built with `roleMatrix()`. Plan 1's `RolePermissionMatrixTest` proves role → permission; these tests prove screen → permission.
- **Nothing is deleted.** Records with history are set to Inactive. The one deletion in this plan is a withdrawal purpose that no transaction has ever used (PRD FR-SPIMS-018).
- **Normalisation on save:**
  - Storage location codes, equipment codes and employee numbers are trimmed and uppercased by model mutators, so `a-01` and `A-01` cannot become two racks.
  - Part numbers are trimmed and keep their case; the database already makes them unique ignoring case.
  - Emails are trimmed and lowercased by a model mutator, because Fortify lowercases the address at sign-in.
- **Uploaded files** (the company logo and personnel photos) go to the default filesystem disk (`FILESYSTEM_DISK`: `local` now, S3-compatible in Plan 7, design §3.6 rule 3). The `media.show` route serves them to signed-in users only.
- **Audit event names** added by this plan: `created`, `updated`, `deleted`, `password_reset`, `signed_in`, `sign_in_failed`, `locked_out`.

## Before You Start

Read these first:

- Design doc: §3.4–§3.6, the master data and `settings` parts of §4.3, §4.4 and §8.2.
- PRD: FR-SPIMS-001 to 004, FR-SPIMS-018, §7.8, the §8 permissions matrix and its conditional access table, §9.1, §12.2, §12.3, §12.8, NFR-SPIMS-004 and NFR-SPIMS-010.
- Plan 1's helpers in `tests/Pest.php`: `inSavepoint()`, `configureInstall()`, `partWithStock()` and `onHand()`.

Work in a worktree off `master` (superpowers:using-git-worktrees). `.env` is gitignored, so a new worktree has none. Copy the main checkout's `.env` into it. Then run `composer install` and `npm install && npm run build`, because pages render through `@vite`. Commands are written for Git Bash on Windows and work unchanged on macOS and Linux.

**How the code in this plan was checked.** It was written against `master` at `be0e767`, from the installed sources of Laravel 13, Livewire 4.4.4, Flux 2.19, Fortify, spatie/laravel-permission and brick/math 0.18. Before publishing:

- Every complete PHP file in the plan passed `php -l` and Pint (`laravel` preset).
- PHPStan level 7 ran over the new application classes. It reported only the members this plan adds to existing models (`User::$is_active`, `StorageLocation::parts()`), which the tasks add.
- Pest was confirmed to pass a dataset closure through unchanged when the parameter is typed `Closure` (Task 13 relies on it).

The tests themselves were not run, because running them is executing the plan. Run each task's tests exactly as written. If a result differs from the expected output, fix the code before committing, and record the deviation in the task report.

Where a step says "Replace `file` with", compare the file as it stands with the listing before overwriting it. Plan 1's Task 7 showed that a whole-file listing can silently revert a fix made after the listing was written. These listings match `be0e767` plus the earlier tasks of this plan.

**Plan 1 review notes this plan resolves:**

| Note | Resolved in |
|---|---|
| Task 1 review: the starter kit's registration leftovers | Task 2 |
| Task 3 review: the profile screen stores email case as typed, which locks the user out | Task 2 |
| Task 4 review: `InstallSettings::set()` stores whatever type it is given | Task 4 |
| Task 8 review: validate transaction number prefixes | Task 4 |
| Task 5 review: `attach()`/`sync()` must pass the same pivot keys for every id | Task 10 |
| Task 5 review: codes are unique case-sensitively; normalise them | Tasks 7, 8 and 12 |
| Task 6 review: the enrollment race surfaces `UniqueConstraintViolationException`; `employee_no` case | Task 12 |

## Decisions Made in This Plan

These settle the roadmap's "Decisions to settle" for Plan 2 and record the choices a reviewer would otherwise have to guess.

1. **The import (FR-SPIMS-005, provisioning step 7) moves to Plan 2b.** It is written after this plan merges, against the installed spreadsheet library's real source, and once Microtech's starting parts list (Q18) is known. The roadmap row and section are amended in the same commit as this plan.
2. **Stock levels block what the database blocks and warn about the rest.** A critical level above the minimum, or a maximum below the minimum, is refused. This matches PRD FR-SPIMS-002 and the `parts_levels_ordered` CHECK. A reorder point below the minimum or above the maximum is saved after the user confirms a warning (design §4.4). The roadmap's wording, which warned on all four relations, is corrected.
3. **Sign-in lockout (NFR-SPIMS-004).** Fortify's failed-attempt limiter is used with a 15-minute window. Five failed sign-ins lock the email-and-address pair until 15 minutes after the first failure. Only failures count, and a successful sign-in clears them. Fortify's default locks for one minute; the starter kit's route throttle counted successful sign-ins too.
4. **Sign-in auditing (PRD §9.1).** `signed_in`, `sign_in_failed` and `locked_out` are recorded against the account. A failed attempt for an email with no account has nothing to attach to and is not recorded.
5. **Accounts are deactivated, never deleted.** This plan adds `users.is_active`:
   - A deactivated account cannot sign in, and a session that is already open ends on its next request.
   - Administrators cannot remove their own System Administrator role or deactivate themselves, so every install keeps at least one active administrator.
   - Administrators set the initial password. There is no email invitation, because mail delivery is configured in Plan 7.
   - The starter kit's self-service account deletion is removed. Stock transactions keep their custodian for ever.
6. **Settings are validated and cast in the domain** (`SettingValues`), so every caller stores the same types. The default count tolerance becomes `0.0`, a float like the other fractional setting. Prefixes are letters only, uppercased, unique across types and at most 10 characters.
7. **Part search** uses a new `lower(part_number) text_pattern_ops` index for exact and prefix matches and Plan 1's trigram index for descriptions. A test proves the planner can use both. Latency against the hosted database is measured in Plan 7.
8. **Custodian conditional rights (PRD §8).**
   - `PartsEditBasic` lets a custodian change description, unit of measure and storage location on the part form, never status.
   - `PartsCreateOnReceipt` belongs to Plan 3's receiving screen. The Plan 2 create screen requires `PartsCreate`.
9. **Two FR steps are deferred to the plan that owns the dependency:**
   - Re-evaluating alerts after a stock level change goes to Plan 5.
   - Per-alert-type instant emails are edited in Plan 5, once alert types exist.
   - This plan edits the digest recipients.
10. **The setup checklist** (PRD §12.8) has seven items computed from data. The backup item arrives with the backup job in Plan 7.
11. **Part links to equipment are edited on the part form.** The equipment form lists the linked parts read-only.

## File Map

```
app/
  Auth/LoginLockout.php                      15-minute failed sign-in lock           (Task 2)
  Domain/
    Access/UserAccounts.php, Exceptions/     create, update, deactivate users        (Task 3)
    Settings/SettingValues.php               validate and cast every setting         (Task 4)
    Settings/PurposeCatalogue.php, Exceptions/  withdrawal purposes                  (Task 6)
    MasterData/StorageLocations.php, EquipmentRegister.php, Exceptions/              (Tasks 7, 8)
    Parts/PartSearch.php                     exact, prefix and description search   (Task 9)
    Parts/PartRecords.php, Exceptions/       create and update spare parts           (Task 10)
    Parts/StockLevels.php                    levels, criticality, lead time          (Task 11)
    Identity/PersonnelRegistry.php, Exceptions/  register and update personnel       (Task 12)
  Enums/InstallType.php                                                              (Task 1)
  Http/Controllers/MediaController.php       logos and photos to signed-in users     (Task 5)
  Http/Middleware/EnsureUserIsActive.php                                             (Task 2)
  Listeners/RecordSignInActivity.php                                                 (Task 2)
  Livewire/
    Concerns/ActsAsSignedInUser.php          the actor of every change               (Task 3)
    Users/{Index,Form}.php                                                           (Task 3)
    Admin/{InstallSettingsForm,WithdrawalPurposes}.php                               (Tasks 5, 6)
    MasterData/StorageLocations/{Index,Form}.php, MasterData/Equipment/{Index,Form}.php  (Tasks 7, 8)
    Parts/{Index,Show,Form,StockLevelsForm}.php                                      (Tasks 9–11)
    Personnel/{Index,Form}.php                                                       (Task 12)
  Policies/PartPolicy.php                    create, edit, edit all fields, levels   (Tasks 10, 11)
  Support/Navigation.php (1), SearchTerm.php (3), Quantity.php (9), AuditValue.php (11)
routes/spims.php                             every Plan 2 screen                     (Task 3 onward)
resources/views/components/install-notices.blade.php  demo label, setup prompt       (Tasks 1, 5)
resources/views/livewire/…                   one view per component above
database/migrations/2026_09_19_2000NN_*.php  users.is_active (2), part number index (9)
tests/Feature/{Shell,Access,Settings,MasterData,Parts,Personnel}/
```

---

## Task 1: App shell and install type

**Files:**
- Create: `app/Enums/InstallType.php`, `app/Support/Navigation.php`, `resources/views/components/install-notices.blade.php`, `tests/Feature/Shell/AppShellTest.php`
- Modify: `config/spims.php`, `.env`, `.env.example`, `phpunit.xml`, `routes/web.php`, `resources/views/layouts/app.blade.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/layouts/auth/simple.blade.php`, `resources/views/components/app-logo.blade.php`, `tests/Pest.php`
- Delete: `resources/views/welcome.blade.php`, `tests/Feature/ExampleTest.php`

**Interfaces:**
- Consumes: `InstallSettings::companyName()` (Plan 1), `Permission`, `Role`.
- Produces:
  - `enum InstallType` with `current(): self` and `label(): string`.
  - `Navigation::for(User $user): array<string, list<array{label, icon, route, active}>>`. Later tasks add one row each to `Navigation::ITEMS`.
  - The `<x-install-notices />` component. Task 5 adds the setup prompt to it.
  - Test helpers:
    - `userWithRoles(Role ...$roles): User`
    - `roleMatrix(string $holders): array`, with holders written as in `RolePermissionMatrixTest`, for example `'-SPA'`
    - `menuLabels(User $user): list<string>`

- [ ] **Step 1: Write the failing tests**

Add these helpers to the end of `tests/Pest.php`, and add `use App\Enums\Role;` and `use App\Support\Navigation;` to its imports:

```php
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
```

Create `tests/Feature/Shell/AppShellTest.php`:

```php
<?php

use App\Enums\InstallType;
use App\Enums\Role;
use App\Models\User;

it('reads the install type from the environment', function () {
    config(['spims.install_type' => 'demo']);
    expect(InstallType::current())->toBe(InstallType::Demo);

    config(['spims.install_type' => 'production']);
    expect(InstallType::current())->toBe(InstallType::Production);
});

it('refuses an install type it does not know', function () {
    config(['spims.install_type' => 'staging']);

    expect(fn () => InstallType::current())
        ->toThrow(UnexpectedValueException::class, 'APP_INSTALL_TYPE must be demo or production, got [staging].');
});

it('labels every screen of a demo install, including sign-in', function () {
    config(['spims.install_type' => 'demo']);

    $this->get(route('login'))->assertSee('Demo install');
    $this->actingAs(userWithRoles(Role::Custodian))->get(route('dashboard'))->assertSee('Demo install');
});

it('shows no demo label on a production install', function () {
    $this->get(route('login'))->assertDontSee('Demo install');
    $this->actingAs(userWithRoles(Role::Custodian))->get(route('dashboard'))->assertDontSee('Demo install');
});

it('shows the company name from install settings', function () {
    configureInstall();

    $this->actingAs(userWithRoles(Role::Custodian))
        ->get(route('dashboard'))
        ->assertSee('Microtech Industrial Solutions, Inc.');
});

it('sends the home page to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('drops the starter kit links', function () {
    $this->actingAs(userWithRoles(Role::Custodian))
        ->get(route('dashboard'))
        ->assertDontSee('livewire-starter-kit');
});

it('shows each user only the menu items they may open', function () {
    expect(menuLabels(User::factory()->create()))->toBe([])
        ->and(menuLabels(userWithRoles(Role::Custodian)))->toContain('Dashboard');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Shell
```

Expected: FAIL with `Class "App\Enums\InstallType" not found`.

- [ ] **Step 3: Add the install type**

Append this entry to the array in `config/spims.php`, after `nightly_at_utc`:

```php

    /*
    |--------------------------------------------------------------------------
    | Install Type
    |--------------------------------------------------------------------------
    |
    | demo or production, set once when the install is provisioned (design
    | §3.6 rule 5). Environment configuration on purpose: nobody can change
    | it from the database or the admin screens. A demo install shows a Demo
    | label on every screen and export.
    |
    */

    'install_type' => env('APP_INSTALL_TYPE', 'production'),
```

Add `APP_INSTALL_TYPE=production` below `APP_URL` in both `.env` and `.env.example`.

In `phpunit.xml`, add this line after the `BADGE_PEPPER` line, so a developer who sets `demo` in `.env` to try the Demo label cannot change test results:

```xml
        <env name="APP_INSTALL_TYPE" value="production"/>
```

Create `app/Enums/InstallType.php`:

```php
<?php

namespace App\Enums;

use Illuminate\Support\Facades\Config;
use UnexpectedValueException;

enum InstallType: string
{
    case Production = 'production';
    case Demo = 'demo';

    /**
     * The type this install was provisioned as, from APP_INSTALL_TYPE (design §3.6 rule 5).
     */
    public static function current(): self
    {
        $value = Config::string('spims.install_type');

        return self::tryFrom($value)
            ?? throw new UnexpectedValueException("APP_INSTALL_TYPE must be demo or production, got [{$value}].");
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production install',
            self::Demo => 'Demo install',
        };
    }
}
```

- [ ] **Step 4: Add the role-aware menu**

Create `app/Support/Navigation.php`:

```php
<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * The sidebar menu. A screen appears only to users holding its permission, so each role sees only
 * what it can open (PRD §8). Labels and group headings are translated when rendered.
 */
final class Navigation
{
    /**
     * @var list<array{group: string, label: string, icon: string, route: string, active: string, permission: Permission}>
     */
    private const array ITEMS = [
        ['group' => 'Storeroom', 'label' => 'Dashboard', 'icon' => 'home', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => Permission::DashboardView],
    ];

    /**
     * The menu groups this user may see, in order, each with its items.
     *
     * @return array<string, list<array{label: string, icon: string, route: string, active: string}>>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (self::ITEMS as $item) {
            if ($user->can($item['permission'])) {
                $groups[$item['group']][] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'route' => $item['route'],
                    'active' => $item['active'],
                ];
            }
        }

        return $groups;
    }
}
```

In `resources/views/layouts/app/sidebar.blade.php`, replace the first `<flux:sidebar.nav>…</flux:sidebar.nav>` block (the one with the `Platform` group) with:

```blade
            <flux:sidebar.nav>
                @foreach (app(\App\Support\Navigation::class)->for(auth()->user()) as $heading => $items)
                    <flux:sidebar.group :heading="__($heading)" class="grid">
                        @foreach ($items as $item)
                            <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['active'])" wire:navigate>
                                {{ __($item['label']) }}
                            </flux:sidebar.item>
                        @endforeach
                    </flux:sidebar.group>
                @endforeach
            </flux:sidebar.nav>
```

In the same file, delete the second `<flux:sidebar.nav>…</flux:sidebar.nav>` block, the one after `<flux:spacer />` holding the `Repository` and `Documentation` links. Keep `<flux:spacer />`.

- [ ] **Step 5: Show the company name and the demo label**

In `resources/views/components/app-logo.blade.php`, insert this block after the `@props([...])` block:

```blade
@php
    $companyName = app(\App\Domain\Settings\InstallSettings::class)->companyName() ?? config('app.name', 'SPIMS');
@endphp
```

Then replace both occurrences of `:name="config('app.name', 'Laravel')"` in that file with `:name="$companyName"`.

Create `resources/views/components/install-notices.blade.php`:

```blade
{{-- PRD FR-SPIMS-019: a demo install shows a Demo label on every screen. --}}
@if (\App\Enums\InstallType::current() === \App\Enums\InstallType::Demo)
    <flux:callout variant="warning" icon="beaker" class="mb-6" data-test="demo-label">
        <flux:callout.heading>{{ __('Demo install') }}</flux:callout.heading>
        <flux:callout.text>{{ __('Sample data for demonstrations. Nothing here is real stock.') }}</flux:callout.text>
    </flux:callout>
@endif
```

Replace `resources/views/layouts/app.blade.php` with:

```blade
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-install-notices />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
```

In `resources/views/layouts/auth/simple.blade.php`, insert `<x-install-notices />` on the line before `<div class="flex flex-col gap-6">`.

- [ ] **Step 6: Send the home page to the dashboard**

In `routes/web.php`, replace `Route::view('/', 'welcome')->name('home');` with:

```php
Route::redirect('/', '/dashboard')->name('home');
```

Delete the starter kit's welcome page and the test that expected it:

```bash
git rm resources/views/welcome.blade.php tests/Feature/ExampleTest.php
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Shell
```

Expected: PASS, 8 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Enums/InstallType.php app/Support/Navigation.php resources/views/components/install-notices.blade.php resources/views/components/app-logo.blade.php resources/views/layouts/app.blade.php resources/views/layouts/app/sidebar.blade.php resources/views/layouts/auth/simple.blade.php config/spims.php .env.example phpunit.xml routes/web.php tests/Pest.php tests/Feature/Shell
git commit -m "Show the install type, company name and a role-aware menu in the app shell"
```

`git rm` in Step 6 already staged the two deletions.

---

## Task 2: Sign-in security and account lifecycle

**Files:**
- Create: `database/migrations/2026_09_19_200001_add_is_active_to_users_table.php`, `app/Auth/LoginLockout.php`, `app/Http/Middleware/EnsureUserIsActive.php`, `app/Listeners/RecordSignInActivity.php`, `tests/Feature/Access/SignInSecurityTest.php`, `tests/Feature/Settings/ProfileAccountTest.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`, `bootstrap/app.php`, `app/Livewire/Settings/Profile.php`, `resources/views/livewire/settings/profile.blade.php`, `tests/Feature/Settings/ProfileUpdateTest.php`
- Delete: `app/Actions/Fortify/CreateNewUser.php`, `resources/views/livewire/auth/register.blade.php`, `tests/Feature/Auth/RegistrationTest.php`, `app/Livewire/Settings/DeleteUserForm.php`, `resources/views/livewire/settings/delete-user-form.blade.php`

**Interfaces:**
- Consumes: `AuditLogger::record()` (Plan 1), Fortify's `LoginRateLimiter`, `Illuminate\Auth\Events\{Login, Failed, Lockout}`.
- Produces:
  - `users.is_active` and `User::$is_active`.
  - `User` stores `email` trimmed and lowercased.
  - `UserFactory::inactive()`.
  - `LoginLockout::LOCK_SECONDS` (900).
  - Audit events `signed_in`, `sign_in_failed` and `locked_out` on the `user` morph type.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Access/SignInSecurityTest.php`:

```php
<?php

use App\Auth\LoginLockout;
use App\Models\AuditLog;
use App\Models\User;

it('refuses sign-in to a deactivated account', function () {
    $user = User::factory()->inactive()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('ends the session of an account deactivated while signed in', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('locks sign-in for 15 minutes after five failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // Locked: even the right password is refused.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(LoginLockout::LOCK_SECONDS + 1)->seconds();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('counts only failures, and a successful sign-in clears them', function () {
    $user = User::factory()->create();
    $fail = fn () => $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    foreach (range(1, 4) as $attempt) {
        $fail();
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    foreach (range(1, 4) as $attempt) {
        $fail();
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

it('audits sign-ins, failed sign-ins and lockouts against the account', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->travel(LoginLockout::LOCK_SECONDS + 1)->seconds();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(AuditLog::query()
        ->where('auditable_type', 'user')
        ->where('auditable_id', (string) $user->id)
        ->orderBy('id')
        ->pluck('event')
        ->all()
    )->toBe([...array_fill(0, 5, 'sign_in_failed'), 'locked_out', 'signed_in']);
});

it('records nothing for a failed sign-in to an email with no account', function () {
    $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'wrong-password']);

    expect(AuditLog::query()->exists())->toBeFalse();
});
```

Create `tests/Feature/Settings/ProfileAccountTest.php`:

```php
<?php

use App\Livewire\Settings\Profile;
use App\Models\User;
use Livewire\Livewire;

it('stores a changed email lowercased, so the account can still sign in', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Profile::class)
        ->set('email', '  New.Address@Example.TEST ')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('new.address@example.test');
});

it('refuses an email another account holds in any letter case', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::actingAs(User::factory()->create())->test(Profile::class)
        ->set('email', 'Taken@Example.test')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => 'unique']);
});

it('offers no way to delete your own account', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Access/SignInSecurityTest.php tests/Feature/Settings/ProfileAccountTest.php
```

Expected: FAIL. The first failure reads `Call to undefined method Database\Factories\UserFactory::inactive()`.

- [ ] **Step 3: Add `users.is_active` and lowercase stored emails**

Create `database/migrations/2026_09_19_200001_add_is_active_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts are deactivated, never deleted: stock transactions keep their custodian for ever.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
```

In `app/Models/User.php`:

1. Add `use Illuminate\Database\Eloquent\Casts\Attribute;` to the imports.
2. Add ` * @property bool $is_active` to the class docblock, after `@property string $password`.
3. Add `'is_active' => 'boolean',` to the array returned by `casts()`.
4. Add this method after `casts()`:

```php
    /**
     * Stored trimmed and lowercased. Fortify lowercases the address at sign-in
     * (fortify.lowercase_usernames) and Postgres compares text case-sensitively, so an address saved
     * with capitals could never sign in.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::lower(trim($value)));
    }
```

In `database/factories/UserFactory.php`, add this state after `unverified()`:

```php
    /**
     * A deactivated account.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
```

- [ ] **Step 4: Lock sign-in for 15 minutes and refuse deactivated accounts**

Create `app/Auth/LoginLockout.php`:

```php
<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

/**
 * NFR-SPIMS-004: after five failed sign-ins the account is locked until 15 minutes after the first
 * failure, so a mistyped password cannot stop a shift for long. Fortify's own limiter locks for one
 * minute. Only failures are counted, and a successful sign-in clears them.
 */
final class LoginLockout extends LoginRateLimiter
{
    public const int LOCK_SECONDS = 15 * 60;

    public function increment(Request $request): void
    {
        $this->limiter->hit($this->throttleKey($request), self::LOCK_SECONDS);
    }
}
```

In `config/fortify.php`, set the `login` limiter to `null` and say why:

```php
    'limiters' => [
        // Null: Fortify's failed-attempt limiter (App\Auth\LoginLockout) runs instead of a route
        // throttle, which would also count successful sign-ins.
        'login' => null,
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],
```

Replace `app/Providers/FortifyServiceProvider.php` with:

```php
<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\LoginLockout;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginRateLimiter::class, LoginLockout::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureAuthentication();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions. There is no self-registration: System Administrators create accounts.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('livewire.auth.login'));
        Fortify::verifyEmailView(fn () => view('livewire.auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('livewire.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('livewire.auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('livewire.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('livewire.auth.forgot-password'));
    }

    /**
     * Deactivated accounts cannot sign in. EnsureUserIsActive ends sessions that are already open.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', Str::lower($request->string(Fortify::username())->value()))->first();

            return $user !== null && $user->is_active && Hash::check($request->string('password')->value(), $user->password)
                ? $user
                : null;
        });
    }

    /**
     * Configure rate limiting. Sign-in attempts are limited by App\Auth\LoginLockout.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
```

Create `app/Http/Middleware/EnsureUserIsActive.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * End the session of an account deactivated while signed in, on its next request, whether it
     * signed in with a password or a passkey.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('This account has been deactivated.')]);
        }

        return $next($request);
    }
}
```

In `bootstrap/app.php`, add `use App\Http\Middleware\EnsureUserIsActive;` and change the web middleware line to:

```php
        $middleware->web(append: [CaptureAuditContext::class, EnsureUserIsActive::class]);
```

- [ ] **Step 5: Audit sign-in activity**

Create `app/Listeners/RecordSignInActivity.php`. Laravel discovers listeners in `app/Listeners` from each `handle*` method's parameter type, so nothing registers it by hand:

```php
<?php

namespace App\Listeners;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

/**
 * PRD §9.1: sign-ins, failed sign-ins and lockouts are audited against the account. A failed attempt
 * for an email with no account has nothing to attach to and is not recorded.
 */
final readonly class RecordSignInActivity
{
    public function __construct(private AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->record('signed_in', $event->user, actor: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // Fortify's authenticateUsing callback reports failures without the user, so find it by email.
        $user = $event->user instanceof User ? $event->user : $this->account($event->credentials['email'] ?? null);

        if ($user !== null) {
            $this->audit->record('sign_in_failed', $user);
        }
    }

    public function handleLockout(Lockout $event): void
    {
        $user = $this->account($event->request->input('email'));

        if ($user !== null) {
            $this->audit->record('locked_out', $user);
        }
    }

    private function account(mixed $email): ?User
    {
        return is_string($email) ? User::query()->where('email', Str::lower($email))->first() : null;
    }
}
```

- [ ] **Step 6: Remove self-registration and self-service account deletion**

```bash
git rm app/Actions/Fortify/CreateNewUser.php resources/views/livewire/auth/register.blade.php tests/Feature/Auth/RegistrationTest.php app/Livewire/Settings/DeleteUserForm.php resources/views/livewire/settings/delete-user-form.blade.php
```

In `resources/views/livewire/settings/profile.blade.php`, delete this block:

```blade
        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
```

In `app/Livewire/Settings/Profile.php`:

1. Delete the `showDeleteUser()` method and its `#[Computed]` attribute.
2. Add `use Illuminate\Support\Str;` to the imports.
3. In `updateProfileInformation()`, insert these lines before `$validated = $this->validate(...)`:

```php
        // Lowercase first, so the unique check compares what User::email() will store.
        $this->email = Str::lower(trim($this->email));
```

In `tests/Feature/Settings/ProfileUpdateTest.php`, delete `test_user_can_delete_their_account()` and `test_correct_password_must_be_provided_to_delete_account()`.

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan migrate
php artisan test tests/Feature/Access/SignInSecurityTest.php tests/Feature/Settings/ProfileAccountTest.php tests/Feature/Auth tests/Feature/Settings/ProfileUpdateTest.php
```

Expected: PASS. The two new files contribute 9 tests. `RegistrationDisabledTest` and every starter-kit sign-in test still pass.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_19_200001_add_is_active_to_users_table.php app/Auth/LoginLockout.php app/Http/Middleware/EnsureUserIsActive.php app/Listeners/RecordSignInActivity.php app/Models/User.php database/factories/UserFactory.php app/Providers/FortifyServiceProvider.php config/fortify.php bootstrap/app.php app/Livewire/Settings/Profile.php resources/views/livewire/settings/profile.blade.php tests/Feature/Settings/ProfileUpdateTest.php tests/Feature/Access/SignInSecurityTest.php tests/Feature/Settings/ProfileAccountTest.php
git commit -m "Lock sign-in for 15 minutes, audit sign-ins and deactivate accounts instead of deleting them"
```

`git rm` in Step 6 already staged the five deletions.

---

## Task 3: User accounts and roles

**Files:**
- Create: `app/Domain/Access/UserAccounts.php`, `app/Domain/Access/Exceptions/AccountChangeRefused.php`, `app/Livewire/Concerns/ActsAsSignedInUser.php`, `app/Support/SearchTerm.php`, `app/Livewire/Users/Index.php`, `app/Livewire/Users/Form.php`, `resources/views/livewire/users/index.blade.php`, `resources/views/livewire/users/form.blade.php`, `routes/spims.php`, `tests/Feature/Access/UserManagementTest.php`
- Modify: `routes/web.php`, `app/Support/Navigation.php`

**Interfaces:**
- Consumes: `AuditLogger::record()`, `Role`, `Permission::UsersManage`, `users.is_active` and the email mutator (Task 2), `PasswordValidationRules` (starter kit).
- Produces:
  - `UserAccounts` methods:
    - `create(string $name, string $email, string $password, list<Role> $roles, User $actor): User`
    - `update(User $user, string $name, string $email, list<Role> $roles, bool $isActive, User $actor): void`
    - `resetPassword(User $user, string $password, User $actor): void`
    - `roleNames(User $user): list<string>`
  - `AccountChangeRefused` with a public `$field` (`roles` or `is_active`).
  - `ActsAsSignedInUser::actor(): User`, used by every Plan 2 component that changes data.
  - `SearchTerm::contains(string): string` and `SearchTerm::startsWith(string): string`, both LIKE patterns with wildcards in the term escaped.
  - `routes/spims.php`, which every later task adds its routes to.
  - Routes `users.index`, `users.create` and `users.edit`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Access/UserManagementTest.php`:

```php
<?php

use App\Enums\Role;
use App\Livewire\Users\Form;
use App\Livewire\Users\Index;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lets only System Administrators manage users', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('users.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('users.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('users.edit', $user))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Users', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('---A'));

it('creates an account with its roles, ready to sign in', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(Form::class)
        ->set('name', 'Maria Santos')
        ->set('email', ' Maria.Santos@Microtech.TEST ')
        ->set('password', 'a-long-password-1')
        ->set('password_confirmation', 'a-long-password-1')
        ->set('roles', ['supervisor', 'custodian'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('users.index'));

    $user = User::query()->where('email', 'maria.santos@microtech.test')->sole();

    expect($user->hasAllRoles([Role::Custodian, Role::Supervisor]))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-password-1', $user->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'created')->where('auditable_type', 'user')->sole())
        ->auditable_id->toBe((string) $user->id)
        ->user_id->toBe($admin->id)
        ->new_values->toBe([
            'name' => 'Maria Santos',
            'email' => 'maria.santos@microtech.test',
            'is_active' => true,
            'roles' => ['custodian', 'supervisor'],
        ]);
});

it('refuses an email another account holds, in any letter case', function () {
    User::factory()->create(['email' => 'taken@microtech.test']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('name', 'Someone')
        ->set('email', 'Taken@Microtech.test')
        ->set('password', 'a-long-password-1')
        ->set('password_confirmation', 'a-long-password-1')
        ->set('roles', ['planner'])
        ->call('save')
        ->assertHasErrors(['email' => 'unique']);
});

it('requires at least one role', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('name', 'Someone')
        ->set('email', 'someone@microtech.test')
        ->set('password', 'a-long-password-1')
        ->set('password_confirmation', 'a-long-password-1')
        ->call('save')
        ->assertHasErrors(['roles' => 'required']);
});

it('changes roles and deactivates an account, recording old and new values', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Custodian);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
        ->assertSet('roles', ['custodian'])
        ->set('roles', ['supervisor'])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    $user = $user->fresh();

    expect($user->is_active)->toBeFalse()
        ->and($user->hasRole(Role::Supervisor))->toBeTrue()
        ->and($user->hasRole(Role::Custodian))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'updated')->where('auditable_id', (string) $user->id)->sole())
        ->old_values->toBe(['is_active' => true, 'roles' => ['custodian']])
        ->new_values->toBe(['is_active' => false, 'roles' => ['supervisor']]);
});

it('does not let administrators remove their own administrator role', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $admin])
        ->set('roles', ['planner'])
        ->call('save')
        ->assertHasErrors('roles');

    expect($admin->fresh()->hasRole(Role::Admin))->toBeTrue();
});

it('does not let administrators deactivate themselves', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $admin])
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('is_active');

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('sets a new password without recording it, and keeps it when left blank', function () {
    $admin = userWithRoles(Role::Admin);
    $user = userWithRoles(Role::Planner);

    Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
        ->set('password', 'another-password-2')
        ->set('password_confirmation', 'another-password-2')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('another-password-2', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'password_reset')->sole())
        ->old_values->toBeNull()
        ->new_values->toBeNull();

    Livewire::actingAs($admin)->test(Form::class, ['user' => $user])
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('another-password-2', $user->fresh()->password))->toBeTrue();
});

it('finds users by name or email and filters them by role', function () {
    $admin = userWithRoles(Role::Admin);
    User::factory()->create(['name' => 'Pedro Reyes'])->assignRole(Role::Planner);
    User::factory()->create(['name' => 'Ana Cruz', 'email' => 'ana.cruz@microtech.test'])->assignRole(Role::Custodian);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('search', 'pedro')
        ->assertSee('Pedro Reyes')
        ->assertDontSee('Ana Cruz')
        ->set('search', 'ANA.CRUZ@')
        ->assertSee('Ana Cruz')
        ->assertDontSee('Pedro Reyes')
        ->set('search', '')
        ->set('role', 'planner')
        ->assertSee('Pedro Reyes')
        ->assertDontSee('Ana Cruz');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Access/UserManagementTest.php
```

Expected: FAIL with `Route [users.index] not defined.`

- [ ] **Step 3: Create the account service**

Create `app/Domain/Access/Exceptions/AccountChangeRefused.php`:

```php
<?php

namespace App\Domain\Access\Exceptions;

use DomainException;

/**
 * A change administrators may not make to their own account, because it could leave the install
 * without an active System Administrator. $field names the input the refusal is about.
 */
final class AccountChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function ownAdministratorRole(): self
    {
        return new self('You cannot remove your own System Administrator role.', 'roles');
    }

    public static function ownDeactivation(): self
    {
        return new self('You cannot deactivate your own account.', 'is_active');
    }
}
```

Create `app/Domain/Access/UserAccounts.php`:

```php
<?php

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\AccountChangeRefused;
use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * SPIMS user accounts, managed by System Administrators (PRD §8, Manage Users and Roles). Accounts
 * are deactivated, never deleted, because stock transactions keep their custodian for ever.
 */
final readonly class UserAccounts
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<Role>  $roles
     */
    public function create(string $name, string $email, string $password, array $roles, User $actor): User
    {
        return DB::transaction(function () use ($name, $email, $password, $roles, $actor): User {
            $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            // An administrator typed the address, so it is trusted.
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles($roles);

            $this->audit->record('created', $user, null, $this->snapshot($user), $actor);

            return $user;
        });
    }

    /**
     * @param  list<Role>  $roles
     *
     * @throws AccountChangeRefused
     */
    public function update(User $user, string $name, string $email, array $roles, bool $isActive, User $actor): void
    {
        if ($user->is($actor) && ! in_array(Role::Admin, $roles, true)) {
            throw AccountChangeRefused::ownAdministratorRole();
        }

        if ($user->is($actor) && ! $isActive) {
            throw AccountChangeRefused::ownDeactivation();
        }

        DB::transaction(function () use ($user, $name, $email, $roles, $isActive, $actor): void {
            $old = $this->snapshot($user);

            $user->forceFill(['name' => $name, 'email' => $email, 'is_active' => $isActive])->save();
            $user->syncRoles($roles);

            $new = $this->snapshot($user);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record('updated', $user, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
            }
        });
    }

    public function resetPassword(User $user, string $password, User $actor): void
    {
        $user->forceFill(['password' => $password])->save();

        // The event only: never the password, hashed or not.
        $this->audit->record('password_reset', $user, null, null, $actor);
    }

    /**
     * The user's role names, read from the database so a just-synced change is included.
     *
     * @return list<string>
     */
    public function roleNames(User $user): array
    {
        return array_values(array_map(
            fn (mixed $name): string => (string) $name,
            $user->roles()->orderBy('name')->pluck('name')->all(),
        ));
    }

    /**
     * @return array{name: string, email: string, is_active: bool, roles: list<string>}
     */
    private function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $this->roleNames($user),
        ];
    }
}
```

- [ ] **Step 4: Create the shared component helpers**

Create `app/Livewire/Concerns/ActsAsSignedInUser.php`:

```php
<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait ActsAsSignedInUser
{
    /**
     * The signed-in user, recorded as the actor of every change a screen makes.
     */
    protected function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
```

Create `app/Support/SearchTerm.php`:

```php
<?php

namespace App\Support;

/**
 * LIKE patterns built from what a user typed. LIKE's own wildcards in the term are escaped, so a
 * search for "50%" or "A_1" matches those characters literally (Postgres escapes with a backslash).
 */
final class SearchTerm
{
    public static function contains(string $term): string
    {
        return '%'.self::escape(trim($term)).'%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape(trim($term)).'%';
    }

    private static function escape(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
```

- [ ] **Step 5: Create the screens**

Create `app/Livewire/Users/Index.php`:

```php
<?php

namespace App\Livewire\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    public function mount(): void
    {
        $this->authorize(Permission::UsersManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $role = Role::tryFrom($this->role);

        return User::query()
            ->with('roles')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('email', 'ilike', SearchTerm::contains($this->search))))
            ->when($role, fn ($query, Role $role) => $query->role($role))
            ->orderBy('name')
            ->paginate(25);
    }
}
```

Create `resources/views/livewire/users/index.blade.php`:

```blade
<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('users.create')" wire:navigate>{{ __('New user') }}</flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search name or email')" class="max-w-sm" />

        <flux:select wire:model.live="role" class="max-w-xs">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            @foreach (\App\Enums\Role::cases() as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell>
                        <flux:link :href="route('users.edit', $user)" wire:navigate>{{ $user->name }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $user->roles->map(fn ($role) => \App\Enums\Role::from($role->name)->label())->join(', ') }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$user->is_active ? 'green' : 'zinc'">
                            {{ $user->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

Create `app/Livewire/Users/Form.php`:

```php
<?php

namespace App\Livewire\Users;

use App\Concerns\PasswordValidationRules;
use App\Domain\Access\Exceptions\AccountChangeRefused;
use App\Domain\Access\UserAccounts;
use App\Enums\Permission;
use App\Enums\Role;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('User')]
class Form extends Component
{
    use ActsAsSignedInUser, PasswordValidationRules;

    /** The account being edited; null while creating one. */
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** @var list<string> */
    public array $roles = [];

    public bool $is_active = true;

    public function mount(?User $user = null): void
    {
        $this->authorize(Permission::UsersManage);

        // The create route has no {user}: Livewire then passes null or an unsaved model.
        if ($user?->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->roles = app(UserAccounts::class)->roleNames($user);
            $this->is_active = $user->is_active;
        }
    }

    public function save(UserAccounts $accounts): void
    {
        $this->authorize(Permission::UsersManage);

        $this->email = Str::lower(trim($this->email));

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->userId)],
            'password' => $this->userId === null
                ? $this->passwordRules()
                : ['nullable', 'string', Password::default(), 'confirmed'],
            'roles' => ['required', 'array'],
            'roles.*' => ['required', Rule::enum(Role::class)],
            'is_active' => ['boolean'],
        ]);

        $roles = array_map(fn (string $role): Role => Role::from($role), $this->roles);

        try {
            if ($this->userId === null) {
                $accounts->create($this->name, $this->email, $this->password, $roles, $this->actor());
            } else {
                $user = User::query()->findOrFail($this->userId);
                $accounts->update($user, $this->name, $this->email, $roles, $this->is_active, $this->actor());

                if ($this->password !== '') {
                    $accounts->resetPassword($user, $this->password, $this->actor());
                }
            }
        } catch (AccountChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('User saved.'));

        $this->redirectRoute('users.index', navigate: true);
    }
}
```

Create `resources/views/livewire/users/form.blade.php`:

```blade
<section class="w-full max-w-lg">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $userId === null ? __('New user') : __('Edit user') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:input wire:model="email" :label="__('Email')" type="email" required />

        <flux:input
            wire:model="password"
            :label="$userId === null ? __('Password') : __('New password (leave blank to keep the current one)')"
            type="password"
            autocomplete="new-password"
            viewable
        />
        <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" autocomplete="new-password" viewable />

        <flux:checkbox.group wire:model="roles" :label="__('Roles')">
            @foreach (\App\Enums\Role::cases() as $role)
                <flux:checkbox :value="$role->value" :label="$role->label()" />
            @endforeach
        </flux:checkbox.group>

        @if ($userId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" />
            <flux:error name="is_active" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('users.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
```

- [ ] **Step 6: Add the routes and the menu item**

Create `routes/spims.php`:

```php
<?php

use App\Livewire\Users;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPIMS screens
|--------------------------------------------------------------------------
|
| Every component authorizes its PRD §8 permission in mount() and again in
| each action that changes data, so these routes need no permission
| middleware of their own.
|
*/

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('users', Users\Index::class)->name('users.index');
    Route::livewire('users/create', Users\Form::class)->name('users.create');
    Route::livewire('users/{user}/edit', Users\Form::class)->name('users.edit');
});
```

Append this line to `routes/web.php`, after `require __DIR__.'/settings.php';`:

```php
require __DIR__.'/spims.php';
```

In `app/Support/Navigation.php`, add this row at the end of `ITEMS`:

```php
        ['group' => 'Administration', 'label' => 'Users', 'icon' => 'users', 'route' => 'users.index', 'active' => 'users.*', 'permission' => Permission::UsersManage],
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Access/UserManagementTest.php
```

Expected: PASS, 12 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/Access app/Livewire/Concerns/ActsAsSignedInUser.php app/Support/SearchTerm.php app/Livewire/Users resources/views/livewire/users routes/spims.php routes/web.php app/Support/Navigation.php tests/Feature/Access/UserManagementTest.php
git commit -m "Let System Administrators create, edit and deactivate user accounts"
```

---

## Task 4: Validate and cast every install setting

`InstallSettings::set()` stores whatever it is given (Plan 1 Task 4 review). A form posts `"6"` where the default is `6`, and `2` where the default is `2.0`. `transactionPrefix()` and `timezone()` only fail at transaction time if a saved value is malformed. This task validates every value against PRD FR-SPIMS-018 and casts it to its default's type before it is stored, whoever the caller is.

**Files:**
- Create: `app/Domain/Settings/SettingValues.php`, `tests/Feature/Settings/SettingValuesTest.php`
- Modify: `app/Domain/Settings/InstallSettings.php`

**Interfaces:**
- Consumes: `InstallSettings::DEFAULTS` (Plan 1).
- Produces:
  - `SettingValues::normalise(string $key, mixed $value): mixed`, which throws `ValidationException`.
  - `SettingValues::rules(string $key): array<string, list<mixed>>`, keyed by the setting's dotted name (with `.*` rows for list settings). Task 5 prefixes these keys with its own property name.
  - `InstallSettings::set()` now validates and casts.
  - `DEFAULTS['counting.default_tolerance_pct']` is now `0.0`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Settings/SettingValuesTest.php`:

```php
<?php

use App\Domain\Settings\InstallSettings;
use App\Domain\Settings\SettingValues;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('accepts every default under its own rules, unchanged', function () {
    foreach (InstallSettings::DEFAULTS as $key => $default) {
        if ($default !== null) {
            expect(SettingValues::normalise($key, $default))->toBe($default, $key);
        }
    }
});

it('stores form input as the type of the default', function () {
    $admin = User::factory()->create();
    $settings = app(InstallSettings::class);

    $settings->set('analysis.window_months', '12', $admin);
    $settings->set('alerts.high_consumption.multiplier', '2.5', $admin);

    expect($settings->get('analysis.window_months'))->toBe(12)
        ->and($settings->get('alerts.high_consumption.multiplier'))->toBe(2.5);
});

it('does not audit a value equal to its default in another type', function () {
    $admin = User::factory()->create();
    $settings = app(InstallSettings::class);

    $settings->set('alerts.high_consumption.multiplier', 2, $admin);
    $settings->set('counting.default_tolerance_pct', '0', $admin);
    $settings->set('analysis.window_months', '6', $admin);

    expect(AuditLog::query()->exists())->toBeFalse();
});

it('uppercases transaction number prefixes and the currency', function () {
    $admin = User::factory()->create();
    $settings = app(InstallSettings::class);

    $settings->set('numbering.prefixes', ['withdrawal' => 'iss', 'replenishment' => 'Rcv', 'adjustment' => 'ADJ', 'opening' => 'opn'], $admin);
    $settings->set('regional.currency', 'usd', $admin);

    expect($settings->transactionPrefix(TransactionType::Withdrawal))->toBe('ISS')
        ->and($settings->transactionPrefix(TransactionType::Replenishment))->toBe('RCV')
        ->and($settings->get('regional.currency'))->toBe('USD');
});

it('lowercases digest recipients and drops repeats', function () {
    app(InstallSettings::class)->set(
        'notifications.digest_recipients',
        ['Stores@Microtech.test', 'stores@microtech.test', 'plant.manager@microtech.test'],
        User::factory()->create(),
    );

    expect(app(InstallSettings::class)->get('notifications.digest_recipients'))
        ->toBe(['stores@microtech.test', 'plant.manager@microtech.test']);
});

it('refuses values outside the rules of PRD FR-SPIMS-018', function (string $key, mixed $value) {
    expect(fn () => app(InstallSettings::class)->set($key, $value, User::factory()->create()))
        ->toThrow(ValidationException::class);
})->with([
    'an unknown time zone' => ['regional.timezone', 'Mars/Olympus_Mons'],
    'a currency that is not three letters' => ['regional.currency', 'PESO'],
    'a prefix with a hyphen' => ['numbering.prefixes', ['withdrawal' => 'W-D', 'replenishment' => 'RP', 'adjustment' => 'AJ', 'opening' => 'OP']],
    'a prefix with a digit' => ['numbering.prefixes', ['withdrawal' => 'WD1', 'replenishment' => 'RP', 'adjustment' => 'AJ', 'opening' => 'OP']],
    'two types sharing a prefix' => ['numbering.prefixes', ['withdrawal' => 'WD', 'replenishment' => 'wd', 'adjustment' => 'AJ', 'opening' => 'OP']],
    'a missing prefix' => ['numbering.prefixes', ['withdrawal' => 'WD', 'replenishment' => 'RP', 'adjustment' => 'AJ']],
    'a prefix for a type that does not exist' => ['numbering.prefixes', ['withdrawal' => 'WD', 'replenishment' => 'RP', 'adjustment' => 'AJ', 'opening' => 'OP', 'count' => 'CT']],
    'a zero analysis window' => ['analysis.window_months', 0],
    'a fractional number of days' => ['backdating.limit_days', '2.5'],
    'a zero multiplier' => ['alerts.high_consumption.multiplier', 0],
    'a negative count tolerance' => ['counting.default_tolerance_pct', -1],
    'a top percent above 100' => ['movement_class.fast_moving_top_percent', 101],
    'a recipient that is not an email' => ['notifications.digest_recipients', ['stores at microtech']],
    'text where a number belongs' => ['prediction.default_lead_time_days', 'fourteen'],
]);

it('allows a count tolerance of zero', function () {
    app(InstallSettings::class)->set('counting.default_tolerance_pct', 0, User::factory()->create());

    expect(app(InstallSettings::class)->get('counting.default_tolerance_pct'))->toBe(0.0);
});

it('stores blank company text as not set', function () {
    $admin = User::factory()->create();
    $settings = app(InstallSettings::class);

    $settings->set('company.address', 'Calamba, Laguna', $admin);
    $settings->set('company.address', '   ', $admin);

    expect($settings->get('company.address'))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Settings/SettingValuesTest.php
```

Expected: FAIL with `Class "App\Domain\Settings\SettingValues" not found`.

- [ ] **Step 3: Write the rules and casts**

Create `app/Domain/Settings/SettingValues.php`:

```php
<?php

namespace App\Domain\Settings;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The rules and stored type of every install setting (PRD FR-SPIMS-018). A form posts "6" where the
 * default is 6, and "wd" where WD is meant. normalise() turns both into what InstallSettings stores,
 * or refuses them, whoever the caller is.
 */
final class SettingValues
{
    /** Settings holding a whole number greater than zero. */
    private const array WHOLE_NUMBERS = [
        'analysis.window_months',
        'movement_class.dead_stock_days',
        'movement_class.slow_moving_days',
        'movement_class.fast_moving_top_percent',
        'movement_class.minimum_history_days',
        'alerts.high_consumption.minimum_events',
        'alerts.high_consumption.minimum_baseline_days',
        'withdrawal.identification_timeout_seconds',
        'backdating.limit_days',
        'prediction.default_lead_time_days',
        'prediction.warning_buffer_days',
    ];

    private const string PREFIX_TYPES = 'withdrawal,replenishment,adjustment,opening';

    /**
     * Validate a value for the setting and return it in its stored form. Blank text becomes null,
     * which InstallSettings treats as "use the default".
     *
     * @throws ValidationException
     */
    public static function normalise(string $key, mixed $value): mixed
    {
        Validator::make(Arr::undot([$key => $value]), self::rules($key))->validate();

        return self::cast($key, $value);
    }

    /**
     * Rules keyed by the setting's dotted name, with ".*" rows for list settings, so a form can
     * prefix them with its own property name.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $key): array
    {
        if (in_array($key, self::WHOLE_NUMBERS, true)) {
            $ceiling = $key === 'movement_class.fast_moving_top_percent' ? ['max:100'] : [];

            return [$key => ['required', 'integer', 'min:1', ...$ceiling]];
        }

        return match ($key) {
            'company.name' => [$key => ['nullable', 'string', 'max:255']],
            'company.address' => [$key => ['nullable', 'string', 'max:1000']],
            'company.logo_path' => [$key => ['nullable', 'string', 'max:255']],
            'regional.timezone' => [$key => ['required', 'string', 'timezone:all']],
            'regional.currency' => [$key => ['required', 'string', 'regex:/^[A-Za-z]{3}$/']],
            // Letters only (PRD FR-SPIMS-018), so a number such as WD-2026-000001 reads one way only.
            'numbering.prefixes' => [
                $key => ['required', 'array:'.self::PREFIX_TYPES, 'required_array_keys:'.self::PREFIX_TYPES],
                "{$key}.*" => ['required', 'string', 'max:10', 'regex:/^[A-Za-z]+$/', 'distinct:ignore_case'],
            ],
            'alerts.high_consumption.multiplier' => [$key => ['required', 'numeric', 'gt:0']],
            // The one threshold that may be zero.
            'counting.default_tolerance_pct' => [$key => ['required', 'numeric', 'min:0', 'max:100']],
            'notifications.digest_recipients' => [
                $key => ['present', 'array'],
                "{$key}.*" => ['required', 'string', 'email'],
            ],
            'notifications.instant_alert_types' => [
                $key => ['present', 'array'],
                "{$key}.*" => ['required', 'string', 'distinct'],
            ],
            default => throw new InvalidArgumentException("Unknown install setting [{$key}]."),
        };
    }

    private static function cast(string $key, mixed $value): mixed
    {
        if (in_array($key, self::WHOLE_NUMBERS, true)) {
            return (int) $value;
        }

        return match ($key) {
            'company.name', 'company.address', 'company.logo_path' => self::text($value),
            'regional.timezone' => (string) $value,
            'regional.currency' => Str::upper((string) $value),
            'numbering.prefixes' => array_map(fn (mixed $prefix): string => Str::upper((string) $prefix), (array) $value),
            'alerts.high_consumption.multiplier', 'counting.default_tolerance_pct' => (float) $value,
            'notifications.digest_recipients' => array_values(array_unique(array_map(
                fn (mixed $email): string => Str::lower(trim((string) $email)),
                (array) $value,
            ))),
            'notifications.instant_alert_types' => array_values(array_map(fn (mixed $type): string => (string) $type, (array) $value)),
            default => throw new InvalidArgumentException("Unknown install setting [{$key}]."),
        };
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
```

- [ ] **Step 4: Validate in `InstallSettings::set()`**

In `app/Domain/Settings/InstallSettings.php`:

1. In `DEFAULTS`, change `'counting.default_tolerance_pct' => 0,` to `'counting.default_tolerance_pct' => 0.0,`.
2. In `set()`, insert this line directly after `$old = $this->get($key);`:

```php
        // Validated and cast to the default's type, so "6" and 6 are the same saved value.
        $value = $value === null ? null : SettingValues::normalise($key, $value);
```

3. Extend `set()`'s docblock with `@throws \Illuminate\Validation\ValidationException when the value breaks the setting's rules (SettingValues)`.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Settings tests/Feature/Ledger/TransactionNumbererTest.php
```

Expected: PASS. `SettingValuesTest` contributes 21 tests, and Plan 1's settings and numbering tests still pass.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Settings/SettingValues.php app/Domain/Settings/InstallSettings.php tests/Feature/Settings/SettingValuesTest.php
git commit -m "Validate install settings and store them in their default's type"
```

---

## Task 5: Install Settings screen

**Files:**
- Create: `app/Livewire/Admin/InstallSettingsForm.php`, `resources/views/livewire/admin/install-settings-form.blade.php`, `app/Http/Controllers/MediaController.php`, `tests/Feature/Settings/InstallSettingsScreenTest.php`
- Modify: `routes/spims.php`, `app/Support/Navigation.php`, `resources/views/components/install-notices.blade.php`, `resources/views/components/app-logo.blade.php`

**Interfaces:**
- Consumes:
  - `InstallSettings::get()`, `set()` and `companyName()`.
  - `SettingValues::rules()` and `normalise()` (Task 4).
  - `ActsAsSignedInUser` (Task 3).
  - `InstallType` (Task 1).
- Produces:
  - Route `install-settings.edit`.
  - Route `media.show`, which serves `company/…` and `personnel/…` files from the default disk to signed-in users. Task 12 stores photos under `personnel/`.
  - The setup prompt in `<x-install-notices />`.
  - The company logo in `<x-app-logo />`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Settings/InstallSettingsScreenTest.php`:

```php
<?php

use App\Domain\Ledger\StockLedger;
use App\Domain\Settings\InstallSettings;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Livewire\Admin\InstallSettingsForm;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('lets only System Administrators configure the install', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('install-settings.edit'))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Install settings', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('---A'));

it('shows the defaults for settings not yet changed, and the install type', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->assertSet('settings.regional.timezone', 'Asia/Manila')
        ->assertSet('settings.numbering.prefixes.withdrawal', 'WD')
        ->assertSet('settings.backdating.limit_days', 7)
        ->assertSee('Production install');
});

it('saves the settings and audits each one that changed', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.company.name', 'Microtech Industrial Solutions, Inc.')
        ->set('settings.company.address', 'Calamba, Laguna')
        ->set('settings.numbering.prefixes.withdrawal', 'iss')
        ->set('settings.backdating.limit_days', '3')
        ->set('recipients', "stores@microtech.test\nplant.manager@microtech.test")
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(InstallSettings::class);

    expect($settings->companyName())->toBe('Microtech Industrial Solutions, Inc.')
        ->and($settings->transactionPrefix(TransactionType::Withdrawal))->toBe('ISS')
        ->and($settings->get('backdating.limit_days'))->toBe(3)
        ->and($settings->get('notifications.digest_recipients'))->toBe(['stores@microtech.test', 'plant.manager@microtech.test'])
        ->and(AuditLog::query()->where('auditable_type', 'setting')->orderBy('auditable_id')->pluck('auditable_id')->all())
        ->toBe(['backdating.limit_days', 'company.address', 'company.name', 'notifications.digest_recipients', 'numbering.prefixes']);
});

it('refuses a prefix that is not letters only, or that two types share', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.numbering.prefixes.withdrawal', 'W-D')
        ->set('settings.numbering.prefixes.replenishment', 'aj')
        ->call('save')
        ->assertHasErrors([
            'settings.numbering.prefixes.withdrawal',
            'settings.numbering.prefixes.replenishment',
            'settings.numbering.prefixes.adjustment',
        ]);

    expect(app(InstallSettings::class)->transactionPrefix(TransactionType::Withdrawal))->toBe('WD');
});

it('numbers new transactions with a changed prefix and leaves existing numbers alone', function () {
    configureInstall();
    partWithStock('5');
    $before = StockTransaction::query()->sole()->transaction_no;

    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.numbering.prefixes.opening', 'OPN')
        ->call('save')
        ->assertHasNoErrors();

    partWithStock('3');

    expect($before)->toStartWith('OP-')
        ->and(StockTransaction::query()->orderBy('id')->pluck('transaction_no')->all())
        ->toBe([$before, 'OPN-'.now()->setTimezone('Asia/Manila')->year.'-000001']);
});

it('asks for confirmation before regrouping figures in a new time zone', function () {
    configureInstall();
    partWithStock('5');

    $screen = Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.regional.timezone', 'Asia/Singapore')
        ->assertSee('regrouped in the new time zone')
        ->call('save')
        ->assertHasErrors('confirmRegrouping');

    expect(app(InstallSettings::class)->timezone())->toBe('Asia/Manila');

    $screen->set('confirmRegrouping', true)->call('save')->assertHasNoErrors();

    expect(app(InstallSettings::class)->timezone())->toBe('Asia/Singapore');
});

it('changes the time zone without asking before any stock is recorded', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.regional.timezone', 'Asia/Singapore')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(InstallSettings::class)->timezone())->toBe('Asia/Singapore');
});

it('asks for confirmation before changing the currency of recorded unit costs', function () {
    configureInstall();
    $receipt = StockTransaction::factory()->replenishment()->withLines([[Part::factory()->create(), '2']])->create();
    $receipt->lines()->update(['unit_cost' => '125.5000']);
    app(StockLedger::class)->complete($receipt, $receipt->custodian);

    $screen = Livewire::actingAs(userWithRoles(Role::Admin))->test(InstallSettingsForm::class)
        ->set('settings.regional.currency', 'USD')
        ->call('save')
        ->assertHasErrors('confirmCurrency');

    $screen->set('confirmCurrency', true)->call('save')->assertHasNoErrors();

    expect(app(InstallSettings::class)->get('regional.currency'))->toBe('USD');
});

it('stores an uploaded logo and shows it beside the company name', function () {
    Storage::fake();
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(InstallSettingsForm::class)
        ->set('logo', UploadedFile::fake()->create('logo.png', 20, 'image/png'))
        ->call('save')
        ->assertHasNoErrors();

    $path = app(InstallSettings::class)->get('company.logo_path');

    expect($path)->toStartWith('company/');
    Storage::assertExists($path);

    $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('media.show', ['path' => $path]), false);
});

it('serves uploaded files to signed-in users only, and only from the upload folders', function () {
    Storage::fake();
    Storage::put('company/logo123.png', 'png-bytes');

    $this->get(route('media.show', ['path' => 'company/logo123.png']))->assertRedirect(route('login'));

    $this->actingAs(userWithRoles(Role::Custodian));
    $this->get(route('media.show', ['path' => 'company/logo123.png']))->assertOk();
    $this->get('/media/company/../.env')->assertNotFound();
});

it('prompts System Administrators to finish setup until the company name is set', function () {
    $admin = userWithRoles(Role::Admin);

    $this->actingAs($admin)->get(route('dashboard'))->assertSee('Finish setting up this install');
    $this->actingAs(userWithRoles(Role::Supervisor))->get(route('dashboard'))->assertDontSee('Finish setting up this install');

    configureInstall();

    $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('Finish setting up this install');
});

it('ticks the setup checklist from what the install holds', function () {
    configureInstall();
    $admin = userWithRoles(Role::Admin);
    $checklist = fn (): array => collect(Livewire::actingAs($admin)->test(InstallSettingsForm::class)->instance()->checklist())
        ->pluck('done', 'label')
        ->all();

    expect($checklist())->toBe([
        'Company profile' => true,
        'Withdrawal purposes' => true,
        'Storage locations' => false,
        'Equipment' => false,
        'Spare parts' => false,
        'Opening balances' => false,
        'Personnel and badges' => false,
    ]);

    partWithStock('5', ['default_location_id' => StorageLocation::factory()->create()->id]);
    Equipment::factory()->create();
    Personnel::factory()->withBadge('A123456789')->create();

    expect($checklist())->each->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Settings/InstallSettingsScreenTest.php
```

Expected: FAIL with `Route [install-settings.edit] not defined.`

- [ ] **Step 3: Create the screen**

Create `app/Livewire/Admin/InstallSettingsForm.php`:

```php
<?php

namespace App\Livewire\Admin;

use App\Domain\Settings\InstallSettings;
use App\Domain\Settings\SettingValues;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Enums\TransactionStatus;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\StorageLocation;
use App\Models\WithdrawalPurpose;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * PRD FR-SPIMS-018 and §12.8: every setting that differs between companies, grouped as in the PRD,
 * with the setup checklist and the install type.
 */
#[Title('Install settings')]
class InstallSettingsForm extends Component
{
    use ActsAsSignedInUser, WithFileUploads;

    /** The thresholds group, in screen order, with their labels. */
    public const array THRESHOLDS = [
        'analysis.window_months' => 'Analysis window (months)',
        'movement_class.dead_stock_days' => 'Dead stock after (days without a withdrawal)',
        'movement_class.slow_moving_days' => 'Slow moving after (days without a withdrawal)',
        'movement_class.fast_moving_top_percent' => 'Fast moving: top percent of parts by withdrawals',
        'movement_class.minimum_history_days' => 'History needed before a part is classed (days)',
        'alerts.high_consumption.multiplier' => 'High consumption: multiple of the usual rate',
        'alerts.high_consumption.minimum_events' => 'High consumption: minimum withdrawals',
        'alerts.high_consumption.minimum_baseline_days' => 'High consumption: minimum baseline (days)',
        'prediction.default_lead_time_days' => 'Default lead time (days)',
        'prediction.warning_buffer_days' => 'Out-of-stock warning buffer (days)',
        'withdrawal.identification_timeout_seconds' => 'Badge identification time limit (seconds)',
        'backdating.limit_days' => 'Backdating limit (days)',
        'counting.default_tolerance_pct' => 'Default count tolerance (%)',
    ];

    /** The transaction types whose number prefix is set here, with their labels. */
    public const array PREFIXES = [
        'withdrawal' => 'Withdrawal',
        'replenishment' => 'Replenishment',
        'adjustment' => 'Adjustment',
        'opening' => 'Opening balance',
    ];

    /**
     * Every setting on this screen except the logo and the digest recipients, nested by the dots in
     * its key, so an input binds to wire:model="settings.company.name". Per-alert-type instant emails
     * are edited with the alerts in Plan 5.
     *
     * @var array<string, mixed>
     */
    public array $settings = [];

    /** Digest recipients, one email address per line. */
    public string $recipients = '';

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public bool $confirmRegrouping = false;

    public bool $confirmCurrency = false;

    public function mount(InstallSettings $installSettings): void
    {
        $this->authorize(Permission::SettingsManage);

        $this->fillFrom($installSettings);
    }

    public function save(InstallSettings $installSettings): void
    {
        $this->authorize(Permission::SettingsManage);

        $this->validate();

        $recipients = $this->recipientList();

        try {
            SettingValues::normalise('notifications.digest_recipients', $recipients);
        } catch (ValidationException) {
            $this->addError('recipients', __('Enter one valid email address per line.'));

            return;
        }

        if ($this->needsRegroupingConfirmation() && ! $this->confirmRegrouping) {
            $this->addError('confirmRegrouping', __('Confirm that figures will be regrouped in the new time zone.'));

            return;
        }

        if ($this->needsCurrencyConfirmation() && ! $this->confirmCurrency) {
            $this->addError('confirmCurrency', __('Confirm that recorded unit costs will not be converted.'));

            return;
        }

        $actor = $this->actor();

        DB::transaction(function () use ($installSettings, $recipients, $actor): void {
            foreach (self::keys() as $key) {
                $installSettings->set($key, data_get($this->settings, $key), $actor);
            }

            $installSettings->set('notifications.digest_recipients', $recipients, $actor);

            if ($this->logo !== null) {
                $path = $this->logo->store('company');

                if ($path === false) {
                    throw new RuntimeException('The logo could not be stored.');
                }

                $installSettings->set('company.logo_path', $path, $actor);
            }
        });

        $this->reset('logo', 'confirmRegrouping', 'confirmCurrency');
        $this->fillFrom($installSettings);

        Flux::toast(variant: 'success', text: __('Install settings saved.'));
    }

    /**
     * PRD FR-SPIMS-018: once stock is recorded, a new time zone regroups every daily, weekly and
     * monthly figure, so the change needs confirming.
     */
    #[Computed]
    public function needsRegroupingConfirmation(): bool
    {
        return data_get($this->settings, 'regional.timezone') !== app(InstallSettings::class)->timezone()
            && StockTransaction::query()->whereIn('status', self::postedStatuses())->exists();
    }

    /**
     * PRD FR-SPIMS-018: unit costs already recorded are not converted to a new currency.
     */
    #[Computed]
    public function needsCurrencyConfirmation(): bool
    {
        return strtoupper((string) data_get($this->settings, 'regional.currency')) !== app(InstallSettings::class)->get('regional.currency')
            && StockTransactionLine::query()
                ->whereNotNull('unit_cost')
                ->whereHas('transaction', fn ($query) => $query->whereIn('status', self::postedStatuses()))
                ->exists();
    }

    /**
     * PRD §12.8 setup checklist, one item per provisioning step (design §3.6). The backup item is
     * added with the backup job in Plan 7.
     *
     * @return list<array{label: string, done: bool}>
     */
    #[Computed]
    public function checklist(): array
    {
        return [
            ['label' => 'Company profile', 'done' => app(InstallSettings::class)->companyName() !== null],
            ['label' => 'Withdrawal purposes', 'done' => WithdrawalPurpose::query()->where('is_active', true)->exists()],
            ['label' => 'Storage locations', 'done' => StorageLocation::query()->where('is_active', true)->exists()],
            ['label' => 'Equipment', 'done' => Equipment::query()->where('is_active', true)->exists()],
            ['label' => 'Spare parts', 'done' => Part::query()->exists()],
            ['label' => 'Opening balances', 'done' => StockMovement::query()->where('movement_type', MovementType::Opening->value)->exists()],
            ['label' => 'Personnel and badges', 'done' => Personnel::query()->where('is_active', true)->whereNotNull('badge_uid_hash')->exists()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [];

        foreach (self::keys() as $key) {
            foreach (SettingValues::rules($key) as $field => $fieldRules) {
                $rules["settings.{$field}"] = $fieldRules;
            }
        }

        return [
            ...$rules,
            'recipients' => ['nullable', 'string'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'settings.company.name' => 'company name',
            'settings.company.address' => 'address',
            'settings.regional.timezone' => 'time zone',
            'settings.regional.currency' => 'currency',
        ];

        foreach (self::PREFIXES as $type => $label) {
            $attributes["settings.numbering.prefixes.{$type}"] = "{$label} prefix";
        }

        foreach (self::THRESHOLDS as $key => $label) {
            $attributes["settings.{$key}"] = $label;
        }

        return $attributes;
    }

    /**
     * The settings edited through $settings.
     *
     * @return list<string>
     */
    private static function keys(): array
    {
        return ['company.name', 'company.address', 'regional.timezone', 'regional.currency', 'numbering.prefixes', ...array_keys(self::THRESHOLDS)];
    }

    /**
     * @return list<string>
     */
    private static function postedStatuses(): array
    {
        return [TransactionStatus::Completed->value, TransactionStatus::Reversed->value];
    }

    private function fillFrom(InstallSettings $installSettings): void
    {
        $values = [];

        foreach (self::keys() as $key) {
            data_set($values, $key, $installSettings->get($key));
        }

        $this->settings = $values;

        $recipients = $installSettings->get('notifications.digest_recipients');
        $this->recipients = is_array($recipients) ? implode("\n", $recipients) : '';
    }

    /**
     * @return list<string>
     */
    private function recipientList(): array
    {
        return array_values(array_filter(
            preg_split('/[\s,;]+/', $this->recipients) ?: [],
            fn (string $email): bool => $email !== '',
        ));
    }
}
```

Create `resources/views/livewire/admin/install-settings-form.blade.php`:

```blade
<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Install settings') }}</flux:heading>
        <flux:badge :color="\App\Enums\InstallType::current() === \App\Enums\InstallType::Demo ? 'yellow' : 'zinc'">
            {{ __(\App\Enums\InstallType::current()->label()) }}
        </flux:badge>
    </div>

    <flux:card class="mb-8">
        <flux:heading size="lg">{{ __('Setup checklist') }}</flux:heading>

        <ul class="mt-4 space-y-2">
            @foreach ($this->checklist as $item)
                <li class="flex items-center gap-2">
                    <flux:icon :name="$item['done'] ? 'check-circle' : 'minus-circle'" variant="mini" />
                    <flux:text>{{ __($item['label']) }}</flux:text>
                    <span class="sr-only">{{ $item['done'] ? __('done') : __('to do') }}</span>
                </li>
            @endforeach
        </ul>
    </flux:card>

    <form wire:submit="save" class="space-y-10">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Company profile') }}</flux:heading>
            <flux:input wire:model="settings.company.name" :label="__('Company name')" :description="__('Required before stock can be recorded.')" />
            <flux:textarea wire:model="settings.company.address" :label="__('Address')" rows="3" />
            <flux:input type="file" wire:model="logo" :label="__('Logo')" accept="image/png,image/jpeg,image/webp" />
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Regional') }}</flux:heading>

            <flux:select wire:model.live="settings.regional.timezone" :label="__('Time zone')">
                @foreach (\DateTimeZone::listIdentifiers() as $zone)
                    <flux:select.option :value="$zone">{{ $zone }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->needsRegroupingConfirmation)
                <flux:checkbox wire:model="confirmRegrouping" :label="__('I understand that daily, weekly and monthly figures will be regrouped in the new time zone.')" />
                <flux:error name="confirmRegrouping" />
            @endif

            <flux:input wire:model.live.debounce.500ms="settings.regional.currency" :label="__('Currency for unit costs')" :description="__('Three-letter code, such as PHP.')" maxlength="3" />

            @if ($this->needsCurrencyConfirmation)
                <flux:checkbox wire:model="confirmCurrency" :label="__('I understand that unit costs already recorded are not converted.')" />
                <flux:error name="confirmCurrency" />
            @endif
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Transaction numbering') }}</flux:heading>
            <flux:text>{{ __('Letters only. New transactions use a new prefix; existing numbers keep theirs.') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (\App\Livewire\Admin\InstallSettingsForm::PREFIXES as $type => $label)
                    <flux:input wire:model="settings.numbering.prefixes.{{ $type }}" :label="__($label)" maxlength="10" />
                @endforeach
            </div>
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Thresholds') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (\App\Livewire\Admin\InstallSettingsForm::THRESHOLDS as $key => $label)
                    <flux:input wire:model="settings.{{ $key }}" :label="__($label)" type="number" step="any" min="0" />
                @endforeach
            </div>
        </div>

        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Notifications') }}</flux:heading>
            <flux:textarea wire:model="recipients" :label="__('Daily alert and exceptions summary recipients')" :description="__('One email address per line.')" rows="4" />
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save settings') }}</flux:button>
    </form>
</section>
```

- [ ] **Step 4: Serve uploaded files to signed-in users**

Create `app/Http/Controllers/MediaController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company logos and personnel photos, served from the configured disk (design §3.6 rule 3) to
 * signed-in users only. The route admits one generated file name inside an upload folder, so no
 * other file on the disk can be reached.
 */
final class MediaController
{
    public function __invoke(string $path): StreamedResponse
    {
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path);
    }
}
```

In `routes/spims.php`, add `use App\Http\Controllers\MediaController;` and `use App\Livewire\Admin;` to the imports, and add these routes inside the group:

```php
    Route::livewire('install-settings', Admin\InstallSettingsForm::class)->name('install-settings.edit');

    Route::get('media/{path}', MediaController::class)
        ->where('path', '(company|personnel)/[A-Za-z0-9]+\.[A-Za-z0-9]+')
        ->name('media.show');
```

In `app/Support/Navigation.php`, add this row after the `Users` row:

```php
        ['group' => 'Administration', 'label' => 'Install settings', 'icon' => 'cog-6-tooth', 'route' => 'install-settings.edit', 'active' => 'install-settings.*', 'permission' => Permission::SettingsManage],
```

- [ ] **Step 5: Add the setup prompt and the logo**

Replace `resources/views/components/install-notices.blade.php` with:

```blade
{{-- PRD FR-SPIMS-019: a demo install shows a Demo label on every screen. --}}
@if (\App\Enums\InstallType::current() === \App\Enums\InstallType::Demo)
    <flux:callout variant="warning" icon="beaker" class="mb-6" data-test="demo-label">
        <flux:callout.heading>{{ __('Demo install') }}</flux:callout.heading>
        <flux:callout.text>{{ __('Sample data for demonstrations. Nothing here is real stock.') }}</flux:callout.text>
    </flux:callout>
@endif

{{-- PRD FR-SPIMS-018: until the company name is set, System Administrators are prompted to set it. --}}
@auth
    @if (auth()->user()->can(\App\Enums\Permission::SettingsManage) && app(\App\Domain\Settings\InstallSettings::class)->companyName() === null)
        <flux:callout icon="cog-6-tooth" class="mb-6" data-test="setup-prompt">
            <flux:callout.heading>{{ __('Finish setting up this install') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Stock cannot be recorded until the company name is set.') }}</flux:callout.text>

            <x-slot name="actions">
                <flux:button size="sm" :href="route('install-settings.edit')" wire:navigate>{{ __('Open install settings') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif
@endauth
```

Replace `resources/views/components/app-logo.blade.php` with:

```blade
@props([
    'sidebar' => false,
])

@php
    $settings = app(\App\Domain\Settings\InstallSettings::class);
    $companyName = $settings->companyName() ?? config('app.name', 'SPIMS');
    $logoPath = $settings->get('company.logo_path');
    $logoUrl = is_string($logoPath) ? route('media.show', ['path' => $logoPath]) : null;
@endphp

@if ($logoUrl !== null)
    @if ($sidebar)
        <flux:sidebar.brand :name="$companyName" :logo="$logoUrl" {{ $attributes }} />
    @else
        <flux:brand :name="$companyName" :logo="$logoUrl" {{ $attributes }} />
    @endif
@elseif ($sidebar)
    <flux:sidebar.brand :name="$companyName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$companyName" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:brand>
@endif
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Settings/InstallSettingsScreenTest.php tests/Feature/Shell
```

Expected: PASS. The new file contributes 15 tests, and the Task 1 shell tests still pass.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Livewire/Admin/InstallSettingsForm.php resources/views/livewire/admin/install-settings-form.blade.php app/Http/Controllers/MediaController.php routes/spims.php app/Support/Navigation.php resources/views/components/install-notices.blade.php resources/views/components/app-logo.blade.php tests/Feature/Settings/InstallSettingsScreenTest.php
git commit -m "Add the Install Settings screen with its setup checklist and prompt"
```

---

## Task 6: Withdrawal purposes

**Files:**
- Create: `app/Domain/Settings/PurposeCatalogue.php`, `app/Domain/Settings/Exceptions/PurposeChangeRefused.php`, `app/Livewire/Admin/WithdrawalPurposes.php`, `resources/views/livewire/admin/withdrawal-purposes.blade.php`, `tests/Feature/Settings/WithdrawalPurposesTest.php`
- Modify: `routes/spims.php`, `app/Support/Navigation.php`

**Interfaces:**
- Consumes: `WithdrawalPurpose` and its seeded rows (Plan 1), `AuditLogger`, `ActsAsSignedInUser`.
- Produces:
  - `PurposeCatalogue` methods:
    - `add(string $name, bool $requiresRemarks, User $actor): WithdrawalPurpose`
    - `update(WithdrawalPurpose $purpose, string $name, bool $requiresRemarks, bool $isActive, User $actor): void`
    - `remove(WithdrawalPurpose $purpose, User $actor): void`
  - `PurposeChangeRefused`, with `$field` set to `name`, `is_active` or `purposes`.
  - Route `withdrawal-purposes.index`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Settings/WithdrawalPurposesTest.php`:

```php
<?php

use App\Enums\Role;
use App\Livewire\Admin\WithdrawalPurposes;
use App\Models\AuditLog;
use App\Models\StockTransaction;
use App\Models\WithdrawalPurpose;
use Livewire\Livewire;

it('lets only System Administrators manage withdrawal purposes', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);

    $this->actingAs($user)->get(route('withdrawal-purposes.index'))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Withdrawal purposes', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('---A'));

it('adds a purpose at the end of the list, audited', function () {
    $admin = userWithRoles(Role::Admin);

    Livewire::actingAs($admin)->test(WithdrawalPurposes::class)
        ->call('create')
        ->set('name', 'Calibration')
        ->set('requires_remarks', true)
        ->call('save')
        ->assertHasNoErrors();

    $purpose = WithdrawalPurpose::query()->where('name', 'Calibration')->sole();

    expect($purpose->code)->toBe('calibration')
        ->and($purpose->requires_remarks)->toBeTrue()
        ->and($purpose->sort_order)->toBe(70)
        ->and(AuditLog::query()->where('auditable_type', 'withdrawal_purpose')->where('event', 'created')->sole())
        ->auditable_id->toBe((string) $purpose->id)
        ->user_id->toBe($admin->id);
});

it('renames and deactivates a purpose, recording old and new values', function () {
    $purpose = WithdrawalPurpose::query()->where('code', 'breakdown_repair')->sole();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('edit', $purpose->id)
        ->set('name', 'Emergency Breakdown')
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($purpose->refresh())
        ->name->toBe('Emergency Breakdown')
        ->is_active->toBeFalse()
        ->and(AuditLog::query()->where('event', 'updated')->sole())
        ->old_values->toBe(['name' => 'Breakdown Repair', 'is_active' => true])
        ->new_values->toBe(['name' => 'Emergency Breakdown', 'is_active' => false]);
});

it('refuses a name another purpose holds, in any letter case', function () {
    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('create')
        ->set('name', 'breakdown repair')
        ->call('save')
        ->assertHasErrors('name');
});

it('keeps at least one purpose Active', function () {
    WithdrawalPurpose::query()->where('code', '!=', 'other')->update(['is_active' => false]);
    $last = WithdrawalPurpose::query()->where('code', 'other')->sole();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('edit', $last->id)
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('is_active');

    expect($last->refresh()->is_active)->toBeTrue();
});

it('deletes a purpose no transaction has used', function () {
    $unused = WithdrawalPurpose::query()->where('code', 'machine_modification')->sole();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('remove', $unused->id)
        ->assertHasNoErrors();

    expect(WithdrawalPurpose::query()->find($unused->id))->toBeNull()
        ->and(AuditLog::query()->where('event', 'deleted')->sole())
        ->auditable_id->toBe((string) $unused->id)
        ->old_values->toMatchArray(['name' => 'Machine Modification']);
});

it('never deletes a purpose a transaction has used', function () {
    $used = WithdrawalPurpose::query()->where('code', 'preventive_maintenance')->sole();
    StockTransaction::factory()->create(['purpose_id' => $used->id]);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(WithdrawalPurposes::class)
        ->call('remove', $used->id)
        ->assertHasErrors('purposes');

    expect(WithdrawalPurpose::query()->find($used->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Settings/WithdrawalPurposesTest.php
```

Expected: FAIL with `Route [withdrawal-purposes.index] not defined.`

- [ ] **Step 3: Create the purpose catalogue**

Create `app/Domain/Settings/Exceptions/PurposeChangeRefused.php`:

```php
<?php

namespace App\Domain\Settings\Exceptions;

use App\Models\WithdrawalPurpose;
use DomainException;

/**
 * A change to the withdrawal purposes that PRD FR-SPIMS-018 forbids. $field names the input the
 * refusal is about.
 */
final class PurposeChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function duplicateName(string $name): self
    {
        return new self("A withdrawal purpose named {$name} already exists.", 'name');
    }

    public static function lastActive(): self
    {
        return new self('At least one withdrawal purpose must stay Active.', 'is_active');
    }

    public static function used(WithdrawalPurpose $purpose): self
    {
        return new self("{$purpose->name} has been used on transactions. Rename it or set it to Inactive instead.", 'purposes');
    }
}
```

Create `app/Domain/Settings/PurposeCatalogue.php`:

```php
<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\Exceptions\PurposeChangeRefused;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The withdrawal purposes offered at the station (PRD FR-SPIMS-018). A purpose a transaction has
 * used is renamed or set to Inactive, never deleted, and at least one purpose always stays Active.
 */
final readonly class PurposeCatalogue
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws PurposeChangeRefused
     */
    public function add(string $name, bool $requiresRemarks, User $actor): WithdrawalPurpose
    {
        $name = trim($name);
        $this->guardUniqueName($name, null);

        return DB::transaction(function () use ($name, $requiresRemarks, $actor): WithdrawalPurpose {
            $purpose = WithdrawalPurpose::query()->create([
                'code' => $this->codeFor($name),
                'name' => $name,
                'requires_remarks' => $requiresRemarks,
                'sort_order' => (int) WithdrawalPurpose::query()->max('sort_order') + 10,
                'is_active' => true,
            ]);

            $this->audit->record('created', $purpose, null, $this->snapshot($purpose), $actor);

            return $purpose;
        });
    }

    /**
     * @throws PurposeChangeRefused
     */
    public function update(WithdrawalPurpose $purpose, string $name, bool $requiresRemarks, bool $isActive, User $actor): void
    {
        $name = trim($name);
        $this->guardUniqueName($name, $purpose);

        DB::transaction(function () use ($purpose, $name, $requiresRemarks, $isActive, $actor): void {
            if ($purpose->is_active && ! $isActive) {
                $this->guardAnotherActive($purpose);
            }

            $old = $this->snapshot($purpose);
            $purpose->forceFill(['name' => $name, 'requires_remarks' => $requiresRemarks, 'is_active' => $isActive])->save();
            $new = $this->snapshot($purpose);

            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record('updated', $purpose, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
            }
        });
    }

    /**
     * Delete a purpose no transaction has used, such as one added by mistake.
     *
     * @throws PurposeChangeRefused
     */
    public function remove(WithdrawalPurpose $purpose, User $actor): void
    {
        DB::transaction(function () use ($purpose, $actor): void {
            if ($this->isUsed($purpose)) {
                throw PurposeChangeRefused::used($purpose);
            }

            if ($purpose->is_active) {
                $this->guardAnotherActive($purpose);
            }

            $old = $this->snapshot($purpose);
            $purpose->delete();

            $this->audit->record('deleted', $purpose, $old, null, $actor);
        });
    }

    /**
     * @throws PurposeChangeRefused
     */
    private function guardUniqueName(string $name, ?WithdrawalPurpose $except): void
    {
        $taken = WithdrawalPurpose::query()
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->when($except, fn ($query, WithdrawalPurpose $except) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw PurposeChangeRefused::duplicateName($name);
        }
    }

    /**
     * The Active purposes are locked first, so two administrators cannot each deactivate one of the
     * last two.
     *
     * @throws PurposeChangeRefused
     */
    private function guardAnotherActive(WithdrawalPurpose $purpose): void
    {
        $others = WithdrawalPurpose::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->filter(fn (mixed $id): bool => (int) $id !== $purpose->id);

        if ($others->isEmpty()) {
            throw PurposeChangeRefused::lastActive();
        }
    }

    private function isUsed(WithdrawalPurpose $purpose): bool
    {
        return StockTransaction::query()->where('purpose_id', $purpose->id)->exists()
            || StockTransactionLine::query()->where('purpose_id', $purpose->id)->exists();
    }

    private function codeFor(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'purpose';
        $code = $base;

        for ($suffix = 2; WithdrawalPurpose::query()->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }

    /**
     * @return array{name: string, requires_remarks: bool, is_active: bool}
     */
    private function snapshot(WithdrawalPurpose $purpose): array
    {
        return [
            'name' => $purpose->name,
            'requires_remarks' => $purpose->requires_remarks,
            'is_active' => $purpose->is_active,
        ];
    }
}
```

- [ ] **Step 4: Create the screen**

Create `app/Livewire/Admin/WithdrawalPurposes.php`:

```php
<?php

namespace App\Livewire\Admin;

use App\Domain\Settings\Exceptions\PurposeChangeRefused;
use App\Domain\Settings\PurposeCatalogue;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\WithdrawalPurpose;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Withdrawal purposes')]
class WithdrawalPurposes extends Component
{
    use ActsAsSignedInUser;

    /** The purpose open in the form; null while adding one. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public bool $requires_remarks = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage);
    }

    /**
     * @return Collection<int, WithdrawalPurpose>
     */
    #[Computed]
    public function purposes(): Collection
    {
        return WithdrawalPurpose::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function create(): void
    {
        $this->authorize(Permission::SettingsManage);

        $this->reset('editingId', 'name', 'requires_remarks', 'is_active');
        $this->resetErrorBag();

        Flux::modal('purpose-form')->show();
    }

    public function edit(int $purposeId): void
    {
        $this->authorize(Permission::SettingsManage);

        $purpose = WithdrawalPurpose::query()->findOrFail($purposeId);

        $this->editingId = $purpose->id;
        $this->name = $purpose->name;
        $this->requires_remarks = $purpose->requires_remarks;
        $this->is_active = $purpose->is_active;
        $this->resetErrorBag();

        Flux::modal('purpose-form')->show();
    }

    public function save(PurposeCatalogue $catalogue): void
    {
        $this->authorize(Permission::SettingsManage);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'requires_remarks' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        try {
            if ($this->editingId === null) {
                $catalogue->add($this->name, $this->requires_remarks, $this->actor());
            } else {
                $catalogue->update(
                    WithdrawalPurpose::query()->findOrFail($this->editingId),
                    $this->name,
                    $this->requires_remarks,
                    $this->is_active,
                    $this->actor(),
                );
            }
        } catch (PurposeChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::modal('purpose-form')->close();
        Flux::toast(variant: 'success', text: __('Withdrawal purpose saved.'));
    }

    public function remove(int $purposeId, PurposeCatalogue $catalogue): void
    {
        $this->authorize(Permission::SettingsManage);

        try {
            $catalogue->remove(WithdrawalPurpose::query()->findOrFail($purposeId), $this->actor());
        } catch (PurposeChangeRefused $refused) {
            $this->addError('purposes', $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Withdrawal purpose deleted.'));
    }
}
```

Create `resources/views/livewire/admin/withdrawal-purposes.blade.php`:

```blade
<section class="w-full max-w-3xl">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Withdrawal purposes') }}</flux:heading>
            <flux:subheading>{{ __('Offered at the station in this order. A purpose used on a transaction can be renamed or set to Inactive, never deleted.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New purpose') }}</flux:button>
    </div>

    <flux:error name="purposes" class="mb-4" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Purpose') }}</flux:table.column>
            <flux:table.column>{{ __('Remarks') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column><span class="sr-only">{{ __('Actions') }}</span></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->purposes as $purpose)
                <flux:table.row :key="$purpose->id">
                    <flux:table.cell>{{ $purpose->name }}</flux:table.cell>
                    <flux:table.cell>{{ $purpose->requires_remarks ? __('Required') : __('Optional') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$purpose->is_active ? 'green' : 'zinc'">
                            {{ $purpose->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                    <flux:table.cell class="text-end">
                        <flux:button size="sm" wire:click="edit({{ $purpose->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="remove({{ $purpose->id }})" wire:confirm="{{ __('Delete this purpose? Only a purpose no transaction has used can be deleted.') }}">
                            {{ __('Delete') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:modal name="purpose-form" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId === null ? __('New purpose') : __('Edit purpose') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:checkbox wire:model="requires_remarks" :label="__('Requires remarks')" />

            @if ($editingId !== null)
                <flux:switch wire:model="is_active" :label="__('Active')" />
                <flux:error name="is_active" />
            @endif

            <div class="flex gap-2">
                <flux:spacer />
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
```

In `routes/spims.php`, add inside the group:

```php
    Route::livewire('withdrawal-purposes', Admin\WithdrawalPurposes::class)->name('withdrawal-purposes.index');
```

In `app/Support/Navigation.php`, add this row after the `Install settings` row:

```php
        ['group' => 'Administration', 'label' => 'Withdrawal purposes', 'icon' => 'list-bullet', 'route' => 'withdrawal-purposes.index', 'active' => 'withdrawal-purposes.*', 'permission' => Permission::SettingsManage],
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Settings/WithdrawalPurposesTest.php tests/Feature/Settings/WithdrawalPurposeSeederTest.php
```

Expected: PASS. The new file contributes 10 tests.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Settings/PurposeCatalogue.php app/Domain/Settings/Exceptions/PurposeChangeRefused.php app/Livewire/Admin/WithdrawalPurposes.php resources/views/livewire/admin/withdrawal-purposes.blade.php routes/spims.php app/Support/Navigation.php tests/Feature/Settings/WithdrawalPurposesTest.php
git commit -m "Let System Administrators add, rename, deactivate and delete unused withdrawal purposes"
```

---

## Task 7: Storage locations

**Files:**
- Create: `app/Domain/MasterData/StorageLocations.php`, `app/Domain/MasterData/Exceptions/MasterDataChangeRefused.php`, `app/Livewire/MasterData/StorageLocations/Index.php`, `app/Livewire/MasterData/StorageLocations/Form.php`, `resources/views/livewire/master-data/storage-locations/index.blade.php`, `resources/views/livewire/master-data/storage-locations/form.blade.php`, `tests/Feature/MasterData/StorageLocationsTest.php`
- Modify: `app/Models/StorageLocation.php`, `routes/spims.php`, `app/Support/Navigation.php`

**Interfaces:**
- Consumes: `StorageLocation` and `Part` (Plan 1), `AuditLogger`, `ActsAsSignedInUser`, `SearchTerm` (Task 3).
- Produces:
  - `StorageLocations` methods:
    - `create(string $code, string $name, ?string $description, User $actor): StorageLocation`
    - `update(StorageLocation $location, string $code, string $name, ?string $description, bool $isActive, User $actor): void`
  - `MasterDataChangeRefused`, with `duplicateCode()` and `locationHoldsActiveParts()` and a public `$field`. Task 8 reuses `duplicateCode()`.
  - `StorageLocation::parts(): HasMany`.
  - The `StorageLocation` code mutator.
  - Routes `storage-locations.index`, `storage-locations.create` and `storage-locations.edit`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MasterData/StorageLocationsTest.php`:

```php
<?php

use App\Enums\Role;
use App\Livewire\MasterData\StorageLocations\Form;
use App\Livewire\MasterData\StorageLocations\Index;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\StorageLocation;
use Livewire\Livewire;

it('lets supervisors, planners and administrators manage storage locations', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $location = StorageLocation::factory()->create();

    $this->actingAs($user)->get(route('storage-locations.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('storage-locations.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('storage-locations.edit', $location))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Storage locations', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-SPA'));

it('creates a location with its code trimmed and uppercased, audited', function () {
    $supervisor = userWithRoles(Role::Supervisor);

    Livewire::actingAs($supervisor)->test(Form::class)
        ->set('code', ' a-01 ')
        ->set('name', 'Aisle A, bin 1')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('storage-locations.index'));

    $location = StorageLocation::query()->sole();

    expect($location->code)->toBe('A-01')
        ->and(AuditLog::query()->where('auditable_type', 'storage_location')->where('event', 'created')->sole())
        ->user_id->toBe($supervisor->id)
        ->new_values->toBe(['code' => 'A-01', 'name' => 'Aisle A, bin 1', 'description' => null, 'is_active' => true]);
});

it('refuses a code already in use, in any letter case', function () {
    StorageLocation::factory()->create(['code' => 'A-01']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('code', 'a-01')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors('code');
});

it('refuses to deactivate a location that holds Active spare parts, and names them', function () {
    $location = StorageLocation::factory()->create(['code' => 'A-01']);
    Part::factory()->create(['part_number' => 'BRG-6204', 'default_location_id' => $location->id]);
    Part::factory()->create(['part_number' => 'OLD-0001', 'default_location_id' => $location->id, 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['location' => $location])
        ->set('is_active', false)
        ->call('save')
        ->assertHasErrors('is_active')
        ->assertSee('BRG-6204')
        ->assertDontSee('OLD-0001');

    expect($location->refresh()->is_active)->toBeTrue();
});

it('deactivates a location once no Active part is kept there, recording old and new values', function () {
    $location = StorageLocation::factory()->create();
    Part::factory()->create(['default_location_id' => $location->id, 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class, ['location' => $location])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($location->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', 'storage_location')->where('event', 'updated')->sole())
        ->old_values->toBe(['is_active' => true])
        ->new_values->toBe(['is_active' => false]);
});

it('lists Active locations by default and finds them by code or name', function () {
    StorageLocation::factory()->create(['code' => 'A-01', 'name' => 'Aisle A bin 1']);
    StorageLocation::factory()->create(['code' => 'B-02', 'name' => 'Bearings cabinet']);
    StorageLocation::factory()->create(['code' => 'Z-99', 'name' => 'Old rack', 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Index::class)
        ->assertSee('A-01')
        ->assertSee('B-02')
        ->assertDontSee('Z-99')
        ->set('search', 'bearings')
        ->assertSee('B-02')
        ->assertDontSee('A-01')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('Z-99')
        ->assertDontSee('A-01');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/MasterData/StorageLocationsTest.php
```

Expected: FAIL with `Route [storage-locations.index] not defined.`

- [ ] **Step 3: Normalise codes and add the parts relation**

In `app/Models/StorageLocation.php`:

1. Add these imports: `use Illuminate\Database\Eloquent\Casts\Attribute;`, `use Illuminate\Database\Eloquent\Relations\HasMany;`, `use Illuminate\Support\Str;`.
2. Add ` * @property-read \Illuminate\Database\Eloquent\Collection<int, Part> $parts` to the class docblock.
3. Add these methods after `casts()`:

```php
    /**
     * Trimmed and uppercased: codes are unique as stored, so a-01 and A-01 must not become two racks.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::upper(trim($value)));
    }

    /**
     * The spare parts whose default location this is.
     *
     * @return HasMany<Part, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class, 'default_location_id');
    }
```

- [ ] **Step 4: Create the service**

Create `app/Domain/MasterData/Exceptions/MasterDataChangeRefused.php`:

```php
<?php

namespace App\Domain\MasterData\Exceptions;

use App\Models\StorageLocation;
use DomainException;

/**
 * A change to storage locations or equipment that PRD FR-SPIMS-003 forbids. $field names the input
 * the refusal is about.
 */
final class MasterDataChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function duplicateCode(string $code): self
    {
        return new self("Code {$code} is already in use.", 'code');
    }

    /**
     * @param  list<string>  $partNumbers
     */
    public static function locationHoldsActiveParts(StorageLocation $location, array $partNumbers): self
    {
        $shown = implode(', ', array_slice($partNumbers, 0, 10));
        $more = count($partNumbers) > 10 ? ' and '.(count($partNumbers) - 10).' more' : '';

        return new self(
            "{$location->code} holds Active spare parts: {$shown}{$more}. Move them to another location or set them to Inactive first.",
            'is_active',
        );
    }
}
```

Create `app/Domain/MasterData/StorageLocations.php`:

```php
<?php

namespace App\Domain\MasterData;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where spare parts are kept (PRD FR-SPIMS-003). A location is set to Inactive, never deleted, and
 * only once no Active spare part is assigned to it.
 */
final readonly class StorageLocations
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws MasterDataChangeRefused
     */
    public function create(string $code, string $name, ?string $description, User $actor): StorageLocation
    {
        $this->guardUniqueCode($code, null);

        return DB::transaction(function () use ($code, $name, $description, $actor): StorageLocation {
            $location = StorageLocation::query()->create([
                'code' => $code,
                'name' => trim($name),
                'description' => self::blankToNull($description),
                'is_active' => true,
            ]);

            $this->audit->record('created', $location, null, $this->snapshot($location), $actor);

            return $location;
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    public function update(StorageLocation $location, string $code, string $name, ?string $description, bool $isActive, User $actor): void
    {
        $this->guardUniqueCode($code, $location);

        DB::transaction(function () use ($location, $code, $name, $description, $isActive, $actor): void {
            if ($location->is_active && ! $isActive) {
                $this->guardNoActiveParts($location);
            }

            $old = $this->snapshot($location);

            $location->forceFill([
                'code' => $code,
                'name' => trim($name),
                'description' => self::blankToNull($description),
                'is_active' => $isActive,
            ])->save();

            $new = $this->snapshot($location);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record('updated', $location, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
            }
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    private function guardUniqueCode(string $code, ?StorageLocation $except): void
    {
        $code = Str::upper(trim($code));

        $taken = StorageLocation::query()
            ->where('code', $code)
            ->when($except, fn ($query, StorageLocation $except) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw MasterDataChangeRefused::duplicateCode($code);
        }
    }

    /**
     * PRD FR-SPIMS-003: a location still assigned to Active spare parts stays Active.
     *
     * @throws MasterDataChangeRefused
     */
    private function guardNoActiveParts(StorageLocation $location): void
    {
        $partNumbers = $location->parts()->where('is_active', true)->orderBy('part_number')->pluck('part_number');

        if ($partNumbers->isNotEmpty()) {
            throw MasterDataChangeRefused::locationHoldsActiveParts(
                $location,
                array_values($partNumbers->map(fn (mixed $number): string => (string) $number)->all()),
            );
        }
    }

    private static function blankToNull(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{code: string, name: string, description: string|null, is_active: bool}
     */
    private function snapshot(StorageLocation $location): array
    {
        return [
            'code' => $location->code,
            'name' => $location->name,
            'description' => $location->description,
            'is_active' => $location->is_active,
        ];
    }
}
```

- [ ] **Step 5: Create the screens**

Create `app/Livewire/MasterData/StorageLocations/Index.php`:

```php
<?php

namespace App\Livewire\MasterData\StorageLocations;

use App\Enums\Permission;
use App\Models\StorageLocation;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Storage locations')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** active, inactive or all */
    #[Url]
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, StorageLocation>
     */
    #[Computed]
    public function locations(): LengthAwarePaginator
    {
        return StorageLocation::query()
            ->withCount(['parts as active_parts_count' => fn ($query) => $query->where('is_active', true)])
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('name', 'ilike', SearchTerm::contains($this->search))))
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('code')
            ->paginate(25);
    }
}
```

Create `resources/views/livewire/master-data/storage-locations/index.blade.php`:

```blade
<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Storage locations') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('storage-locations.create')" wire:navigate>{{ __('New location') }}</flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search code or name')" class="max-w-sm" />

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->locations">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Active spare parts') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->locations as $location)
                <flux:table.row :key="$location->id">
                    <flux:table.cell>
                        <flux:link :href="route('storage-locations.edit', $location)" wire:navigate>{{ $location->code }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $location->name }}</flux:table.cell>
                    <flux:table.cell>{{ $location->active_parts_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$location->is_active ? 'green' : 'zinc'">
                            {{ $location->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

Create `app/Livewire/MasterData/StorageLocations/Form.php`:

```php
<?php

namespace App\Livewire\MasterData\StorageLocations;

use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Domain\MasterData\StorageLocations;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\StorageLocation;
use Flux\Flux;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Storage location')]
class Form extends Component
{
    use ActsAsSignedInUser;

    /** The location being edited; null while creating one. */
    #[Locked]
    public ?int $locationId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public bool $is_active = true;

    public function mount(?StorageLocation $location = null): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        if ($location?->exists) {
            $this->locationId = $location->id;
            $this->code = $location->code;
            $this->name = $location->name;
            $this->description = (string) $location->description;
            $this->is_active = $location->is_active;
        }
    }

    public function save(StorageLocations $locations): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        $this->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        try {
            if ($this->locationId === null) {
                $locations->create($this->code, $this->name, $this->description, $this->actor());
            } else {
                $locations->update(
                    StorageLocation::query()->findOrFail($this->locationId),
                    $this->code,
                    $this->name,
                    $this->description,
                    $this->is_active,
                    $this->actor(),
                );
            }
        } catch (MasterDataChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Storage location saved.'));

        $this->redirectRoute('storage-locations.index', navigate: true);
    }
}
```

Create `resources/views/livewire/master-data/storage-locations/form.blade.php`:

```blade
<section class="w-full max-w-lg">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $locationId === null ? __('New storage location') : __('Edit storage location') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="code" :label="__('Code')" :description="__('Stored in capitals, such as A-01.')" required />
        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:textarea wire:model="description" :label="__('Description')" rows="3" />

        @if ($locationId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" />
            <flux:error name="is_active" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('storage-locations.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
```

- [ ] **Step 6: Add the routes and the menu item**

In `routes/spims.php`, add `use App\Livewire\MasterData\StorageLocations;` to the imports and these routes inside the group:

```php
    Route::livewire('storage-locations', StorageLocations\Index::class)->name('storage-locations.index');
    Route::livewire('storage-locations/create', StorageLocations\Form::class)->name('storage-locations.create');
    Route::livewire('storage-locations/{location}/edit', StorageLocations\Form::class)->name('storage-locations.edit');
```

In `app/Support/Navigation.php`, add this row directly before the `Users` row:

```php
        ['group' => 'Master data', 'label' => 'Storage locations', 'icon' => 'map-pin', 'route' => 'storage-locations.index', 'active' => 'storage-locations.*', 'permission' => Permission::EquipmentLocationsManage],
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/MasterData
```

Expected: PASS. `StorageLocationsTest` contributes 9 tests, and Plan 1's `MasterDataSchemaTest` still passes.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/MasterData app/Livewire/MasterData/StorageLocations resources/views/livewire/master-data/storage-locations app/Models/StorageLocation.php routes/spims.php app/Support/Navigation.php tests/Feature/MasterData/StorageLocationsTest.php
git commit -m "Manage storage locations, refusing to deactivate one that holds Active parts"
```

---

## Task 8: Equipment

**Files:**
- Create: `app/Domain/MasterData/EquipmentRegister.php`, `app/Livewire/MasterData/Equipment/Index.php`, `app/Livewire/MasterData/Equipment/Form.php`, `resources/views/livewire/master-data/equipment/index.blade.php`, `resources/views/livewire/master-data/equipment/form.blade.php`, `tests/Feature/MasterData/EquipmentTest.php`
- Modify: `app/Models/Equipment.php`, `routes/spims.php`, `app/Support/Navigation.php`

**Interfaces:**
- Consumes: `Equipment` and its `parts()` relation (Plan 1), `MasterDataChangeRefused::duplicateCode()` (Task 7), `AuditLogger`, `ActsAsSignedInUser` and `SearchTerm`.
- Produces:
  - `EquipmentRegister` methods:
    - `create(string $code, string $name, ?string $area, User $actor): Equipment`
    - `update(Equipment $equipment, string $code, string $name, ?string $area, bool $isActive, User $actor): void`
  - The `Equipment` code mutator.
  - Routes `equipment.index`, `equipment.create` and `equipment.edit`.

Inactive equipment is kept with its history and disappears from new withdrawals (PRD FR-SPIMS-003). Plan 3's station offers Active equipment only, so deactivation is never blocked here.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MasterData/EquipmentTest.php`:

```php
<?php

use App\Enums\Role;
use App\Livewire\MasterData\Equipment\Form;
use App\Livewire\MasterData\Equipment\Index;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use Livewire\Livewire;

it('lets supervisors, planners and administrators manage equipment', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $equipment = Equipment::factory()->create();

    $this->actingAs($user)->get(route('equipment.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('equipment.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('equipment.edit', $equipment))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Equipment', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-SPA'));

it('registers equipment with its code trimmed and uppercased, audited', function () {
    $planner = userWithRoles(Role::Planner);

    Livewire::actingAs($planner)->test(Form::class)
        ->set('code', ' cnc-01 ')
        ->set('name', 'CNC lathe 1')
        ->set('area', 'Machine shop')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('equipment.index'));

    $equipment = Equipment::query()->sole();

    expect($equipment->code)->toBe('CNC-01')
        ->and(AuditLog::query()->where('auditable_type', 'equipment')->where('event', 'created')->sole())
        ->user_id->toBe($planner->id)
        ->new_values->toBe(['code' => 'CNC-01', 'name' => 'CNC lathe 1', 'area' => 'Machine shop', 'is_active' => true]);
});

it('refuses a code already in use, in any letter case', function () {
    Equipment::factory()->create(['code' => 'CNC-01']);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class)
        ->set('code', 'cnc-01')
        ->set('name', 'Duplicate')
        ->call('save')
        ->assertHasErrors('code');
});

it('deactivates equipment that has spare parts linked, keeping the links', function () {
    $equipment = Equipment::factory()->create();
    $part = Part::factory()->create();
    $part->equipment()->attach($equipment->id);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class, ['equipment' => $equipment])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($equipment->refresh()->is_active)->toBeFalse()
        ->and($equipment->parts()->pluck('parts.id')->all())->toBe([$part->id])
        ->and(AuditLog::query()->where('auditable_type', 'equipment')->where('event', 'updated')->sole())
        ->old_values->toBe(['is_active' => true])
        ->new_values->toBe(['is_active' => false]);
});

it('shows the spare parts linked to the equipment', function () {
    $equipment = Equipment::factory()->create();
    Part::factory()->create(['part_number' => 'BELT-A42'])->equipment()->attach($equipment->id);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class, ['equipment' => $equipment])
        ->assertSee('BELT-A42');
});

it('lists Active equipment by default and finds it by code, name or area', function () {
    Equipment::factory()->create(['code' => 'CNC-01', 'name' => 'CNC lathe 1', 'area' => 'Machine shop']);
    Equipment::factory()->create(['code' => 'BLR-01', 'name' => 'Boiler 1', 'area' => 'Utilities']);
    Equipment::factory()->create(['code' => 'OLD-01', 'name' => 'Retired press', 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Index::class)
        ->assertSee('CNC-01')
        ->assertSee('BLR-01')
        ->assertDontSee('OLD-01')
        ->set('search', 'utilities')
        ->assertSee('BLR-01')
        ->assertDontSee('CNC-01')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('OLD-01')
        ->assertDontSee('CNC-01');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/MasterData/EquipmentTest.php
```

Expected: FAIL with `Route [equipment.index] not defined.`

- [ ] **Step 3: Normalise codes**

In `app/Models/Equipment.php`, add `use Illuminate\Database\Eloquent\Casts\Attribute;` and `use Illuminate\Support\Str;` to the imports, add ` * @property-read \Illuminate\Database\Eloquent\Collection<int, Part> $parts` to the class docblock, and add this method after `casts()`:

```php
    /**
     * Trimmed and uppercased: codes are unique as stored, so cnc-01 and CNC-01 must not become two
     * machines.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::upper(trim($value)));
    }
```

- [ ] **Step 4: Create the service**

Create `app/Domain/MasterData/EquipmentRegister.php`:

```php
<?php

namespace App\Domain\MasterData;

use App\Domain\Audit\AuditLogger;
use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The machines spare parts are used on (PRD FR-SPIMS-003). Equipment is set to Inactive, never
 * deleted: it keeps its history and its part links, and leaves the withdrawal station's choices.
 */
final readonly class EquipmentRegister
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws MasterDataChangeRefused
     */
    public function create(string $code, string $name, ?string $area, User $actor): Equipment
    {
        $this->guardUniqueCode($code, null);

        return DB::transaction(function () use ($code, $name, $area, $actor): Equipment {
            $equipment = Equipment::query()->create([
                'code' => $code,
                'name' => trim($name),
                'area' => self::blankToNull($area),
                'is_active' => true,
            ]);

            $this->audit->record('created', $equipment, null, $this->snapshot($equipment), $actor);

            return $equipment;
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    public function update(Equipment $equipment, string $code, string $name, ?string $area, bool $isActive, User $actor): void
    {
        $this->guardUniqueCode($code, $equipment);

        DB::transaction(function () use ($equipment, $code, $name, $area, $isActive, $actor): void {
            $old = $this->snapshot($equipment);

            $equipment->forceFill([
                'code' => $code,
                'name' => trim($name),
                'area' => self::blankToNull($area),
                'is_active' => $isActive,
            ])->save();

            $new = $this->snapshot($equipment);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record('updated', $equipment, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
            }
        });
    }

    /**
     * @throws MasterDataChangeRefused
     */
    private function guardUniqueCode(string $code, ?Equipment $except): void
    {
        $code = Str::upper(trim($code));

        $taken = Equipment::query()
            ->where('code', $code)
            ->when($except, fn ($query, Equipment $except) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw MasterDataChangeRefused::duplicateCode($code);
        }
    }

    private static function blankToNull(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{code: string, name: string, area: string|null, is_active: bool}
     */
    private function snapshot(Equipment $equipment): array
    {
        return [
            'code' => $equipment->code,
            'name' => $equipment->name,
            'area' => $equipment->area,
            'is_active' => $equipment->is_active,
        ];
    }
}
```

- [ ] **Step 5: Create the screens**

Create `app/Livewire/MasterData/Equipment/Index.php`:

```php
<?php

namespace App\Livewire\MasterData\Equipment;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Equipment')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** active, inactive or all */
    #[Url]
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Equipment>
     */
    #[Computed]
    public function equipment(): LengthAwarePaginator
    {
        return Equipment::query()
            ->withCount('parts')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('name', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('area', 'ilike', SearchTerm::contains($this->search))))
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('code')
            ->paginate(25);
    }
}
```

Create `resources/views/livewire/master-data/equipment/index.blade.php`:

```blade
<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Equipment') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('equipment.create')" wire:navigate>{{ __('New equipment') }}</flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search code, name or area')" class="max-w-sm" />

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->equipment">
        <flux:table.columns>
            <flux:table.column>{{ __('Code') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Area') }}</flux:table.column>
            <flux:table.column>{{ __('Linked spare parts') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->equipment as $machine)
                <flux:table.row :key="$machine->id">
                    <flux:table.cell>
                        <flux:link :href="route('equipment.edit', $machine)" wire:navigate>{{ $machine->code }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $machine->name }}</flux:table.cell>
                    <flux:table.cell>{{ $machine->area }}</flux:table.cell>
                    <flux:table.cell>{{ $machine->parts_count }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$machine->is_active ? 'green' : 'zinc'">
                            {{ $machine->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

Create `app/Livewire/MasterData/Equipment/Form.php`:

```php
<?php

namespace App\Livewire\MasterData\Equipment;

use App\Domain\MasterData\EquipmentRegister;
use App\Domain\MasterData\Exceptions\MasterDataChangeRefused;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Equipment')]
class Form extends Component
{
    use ActsAsSignedInUser;

    /** The equipment being edited; null while registering new equipment. */
    #[Locked]
    public ?int $equipmentId = null;

    public string $code = '';

    public string $name = '';

    public string $area = '';

    public bool $is_active = true;

    public function mount(?Equipment $equipment = null): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        if ($equipment?->exists) {
            $this->equipmentId = $equipment->id;
            $this->code = $equipment->code;
            $this->name = $equipment->name;
            $this->area = (string) $equipment->area;
            $this->is_active = $equipment->is_active;
        }
    }

    /**
     * The spare parts linked to this equipment. Links are edited on the spare part form.
     *
     * @return Collection<int, Part>
     */
    #[Computed]
    public function linkedParts(): Collection
    {
        return $this->equipmentId === null
            ? new Collection
            : Part::query()
                ->whereHas('equipment', fn ($query) => $query->whereKey($this->equipmentId))
                ->orderBy('part_number')
                ->get();
    }

    public function save(EquipmentRegister $register): void
    {
        $this->authorize(Permission::EquipmentLocationsManage);

        $this->validate([
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        try {
            if ($this->equipmentId === null) {
                $register->create($this->code, $this->name, $this->area, $this->actor());
            } else {
                $register->update(
                    Equipment::query()->findOrFail($this->equipmentId),
                    $this->code,
                    $this->name,
                    $this->area,
                    $this->is_active,
                    $this->actor(),
                );
            }
        } catch (MasterDataChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Equipment saved.'));

        $this->redirectRoute('equipment.index', navigate: true);
    }
}
```

Create `resources/views/livewire/master-data/equipment/form.blade.php`:

```blade
<section class="w-full max-w-lg">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $equipmentId === null ? __('New equipment') : __('Edit equipment') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="code" :label="__('Code')" :description="__('Stored in capitals, such as CNC-01.')" required />
        <flux:input wire:model="name" :label="__('Name')" required />
        <flux:input wire:model="area" :label="__('Area')" />

        @if ($equipmentId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive equipment keeps its history and cannot be chosen on new withdrawals.')" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('equipment.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>

    @if ($equipmentId !== null)
        <div class="mt-10">
            <flux:heading size="lg">{{ __('Linked spare parts') }}</flux:heading>
            <flux:subheading>{{ __('Link spare parts to this equipment from each spare part\'s form.') }}</flux:subheading>

            <ul class="mt-4 space-y-1">
                @forelse ($this->linkedParts as $part)
                    <li>
                        <span data-part-number>{{ $part->part_number }}</span>
                        <flux:text class="inline">{{ $part->description }}</flux:text>
                    </li>
                @empty
                    <li><flux:text>{{ __('No spare parts linked yet.') }}</flux:text></li>
                @endforelse
            </ul>
        </div>
    @endif
</section>
```

Task 9 turns each part number into a link to the part.

- [ ] **Step 6: Add the routes and the menu item**

In `routes/spims.php`, add `use App\Livewire\MasterData\Equipment;` to the imports and these routes inside the group:

```php
    Route::livewire('equipment', Equipment\Index::class)->name('equipment.index');
    Route::livewire('equipment/create', Equipment\Form::class)->name('equipment.create');
    Route::livewire('equipment/{equipment}/edit', Equipment\Form::class)->name('equipment.edit');
```

In `app/Support/Navigation.php`, add this row after the `Storage locations` row:

```php
        ['group' => 'Master data', 'label' => 'Equipment', 'icon' => 'wrench-screwdriver', 'route' => 'equipment.index', 'active' => 'equipment.*', 'permission' => Permission::EquipmentLocationsManage],
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/MasterData
```

Expected: PASS. `EquipmentTest` contributes 9 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/MasterData/EquipmentRegister.php app/Livewire/MasterData/Equipment resources/views/livewire/master-data/equipment app/Models/Equipment.php routes/spims.php app/Support/Navigation.php tests/Feature/MasterData/EquipmentTest.php
git commit -m "Manage the equipment register"
```

---

## Task 9: Spare part search, list and detail

**Files:**
- Create: `database/migrations/2026_09_19_200002_add_part_number_prefix_index.php`, `app/Domain/Parts/PartSearch.php`, `app/Support/Quantity.php`, `app/Livewire/Parts/Index.php`, `app/Livewire/Parts/Show.php`, `resources/views/livewire/parts/index.blade.php`, `resources/views/livewire/parts/show.blade.php`, `tests/Feature/Parts/PartListTest.php`
- Modify: `routes/spims.php`, `app/Support/Navigation.php`, `resources/views/livewire/master-data/equipment/form.blade.php`

**Interfaces:**
- Consumes: `Part`, `PartStock`, `StorageLocation`, `Equipment` and `Criticality` (Plan 1), `SearchTerm` (Task 3).
- Produces:
  - `PartSearch::matching(string $term): Builder<Part>`. Plan 3's station lookup reuses it.
  - `Quantity::display(?string $quantity): string`.
  - Routes `parts.index` and `parts.show`. `{part}` is constrained to digits, so `parts/create` in Task 10 cannot be mistaken for a part.
  - The part detail page. Tasks 10 and 11 add their actions and the change history to it.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Parts/PartListTest.php`:

```php
<?php

use App\Domain\Parts\PartSearch;
use App\Enums\Criticality;
use App\Enums\Role;
use App\Livewire\Parts\Index;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lets every role search spare parts and see stock balances', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $part = Part::factory()->create();

    $this->actingAs($user)->get(route('parts.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('parts.show', $part))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Spare parts', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('CSPA'));

it('finds parts by exact or leading part number ignoring case, the exact match first', function () {
    Part::factory()->create(['part_number' => 'BRG-6204-2RS', 'description' => 'Deep groove ball bearing']);
    Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Ball bearing, open']);
    Part::factory()->create(['part_number' => 'XBRG-1', 'description' => 'Bracket']);

    expect(app(PartSearch::class)->matching('brg-6204')->pluck('part_number')->all())
        ->toBe(['BRG-6204', 'BRG-6204-2RS']);
});

it('finds parts by words anywhere in the description', function () {
    Part::factory()->create(['part_number' => 'BRG-6204-2RS', 'description' => 'Deep groove ball bearing']);
    Part::factory()->create(['part_number' => 'VB-A42', 'description' => 'V-belt A42']);

    expect(app(PartSearch::class)->matching('GROOVE BALL')->pluck('part_number')->all())->toBe(['BRG-6204-2RS']);
});

it('takes LIKE wildcards in a search literally', function () {
    Part::factory()->create(['part_number' => 'A_1', 'description' => 'Adapter']);
    Part::factory()->create(['part_number' => 'AB1', 'description' => 'Coolant, 50% glycol']);
    Part::factory()->create(['part_number' => 'AC1', 'description' => 'Coolant, 500 ml']);

    expect(app(PartSearch::class)->matching('a_')->pluck('part_number')->all())->toBe(['A_1'])
        ->and(app(PartSearch::class)->matching('50%')->pluck('part_number')->all())->toBe(['AB1']);
});

it('lets the database answer a search from indexes', function () {
    Part::factory()->count(20)->create();

    // With sequential and plain index scans ruled out, only an indexed plan avoids a Seq Scan.
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_indexscan = off');

    $search = app(PartSearch::class)->matching('brg');
    $plan = DB::selectOne('explain (format json) '.$search->toSql(), $search->getBindings())->{'QUERY PLAN'};

    expect($plan)->not->toContain('Seq Scan')->toContain('parts_description_trgm');
});

it('lists Active parts with their location and balance, filtered by criticality and location', function () {
    configureInstall();
    $rack = StorageLocation::factory()->create(['code' => 'A-01']);
    partWithStock('12.5', [
        'part_number' => 'OIL-68',
        'unit_of_measure' => 'L',
        'default_location_id' => $rack->id,
        'criticality' => Criticality::Critical,
    ]);
    Part::factory()->create(['part_number' => 'SEAL-9', 'criticality' => Criticality::Normal]);
    Part::factory()->create(['part_number' => 'OLD-1', 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Custodian))->test(Index::class)
        ->assertSee('OIL-68')
        ->assertSee('12.5')
        ->assertSee('SEAL-9')
        ->assertDontSee('OLD-1')
        ->set('criticality', 'critical')
        ->assertSee('OIL-68')
        ->assertDontSee('SEAL-9')
        ->set('criticality', '')
        ->set('location', (string) $rack->id)
        ->assertSee('OIL-68')
        ->assertDontSee('SEAL-9')
        ->set('location', '')
        ->set('status', 'inactive')
        ->assertSee('OLD-1')
        ->assertDontSee('OIL-68');
});

it('shows a part with its balance, stock levels and linked equipment', function () {
    configureInstall();
    $part = partWithStock('3', ['part_number' => 'BELT-A42', 'reorder_point' => '4', 'min_level' => '2']);
    $part->equipment()->attach(Equipment::factory()->create(['code' => 'CNC-01'])->id);

    $this->actingAs(userWithRoles(Role::Planner))
        ->get(route('parts.show', $part))
        ->assertOk()
        ->assertSee('BELT-A42')
        ->assertSee('CNC-01')
        ->assertSeeInOrder(['On hand', '3'])
        ->assertSeeInOrder(['Reorder point', '4']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Parts/PartListTest.php
```

Expected: FAIL with `Route [parts.index] not defined.`

- [ ] **Step 3: Index part-number prefixes and write the search**

Create `database/migrations/2026_09_19_200002_add_part_number_prefix_index.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Exact and leading part-number lookups (NFR-SPIMS-006: under 150 ms at p95). The unique index
        // on lower(part_number) follows the database collation, which cannot serve LIKE 'abc%'.
        DB::statement('create index parts_part_number_prefix_idx on parts (lower(part_number) text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists parts_part_number_prefix_idx');
    }
};
```

Create `app/Domain/Parts/PartSearch.php`:

```php
<?php

namespace App\Domain\Parts;

use App\Models\Part;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Spare part search (PRD FR-SPIMS-001, NFR-SPIMS-006): an exact or leading part number, or words
 * anywhere in the description, ignoring case. An exact part-number match sorts first. Served by the
 * indexes parts_part_number_prefix_idx and parts_description_trgm.
 */
final class PartSearch
{
    /**
     * @return Builder<Part>
     */
    public function matching(string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return Part::query()->orderBy('part_number');
        }

        $lower = Str::lower($term);

        return Part::query()
            ->where(fn ($query) => $query
                ->whereRaw('lower(part_number) like ?', [SearchTerm::startsWith($lower)])
                ->orWhere('description', 'ilike', SearchTerm::contains($term)))
            ->orderByRaw('lower(part_number) = ? desc', [$lower])
            ->orderBy('part_number');
    }
}
```

Create `app/Support/Quantity.php`:

```php
<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/**
 * Quantities for display: numeric(14,3) values without trailing zeros, so 5.000 reads 5 and 2.500
 * reads 2.5. Never used for arithmetic.
 */
final class Quantity
{
    public static function display(?string $quantity): string
    {
        return $quantity === null ? '' : (string) BigDecimal::of($quantity)->strippedOfTrailingZeros();
    }
}
```

- [ ] **Step 4: Create the list and detail screens**

Create `app/Livewire/Parts/Index.php`:

```php
<?php

namespace App\Livewire\Parts;

use App\Domain\Parts\PartSearch;
use App\Enums\Criticality;
use App\Enums\Permission;
use App\Models\Part;
use App\Models\StorageLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Spare parts')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** active, inactive or all */
    #[Url]
    public string $status = 'active';

    #[Url]
    public string $criticality = '';

    /** A storage location id, or empty for every location. */
    #[Url]
    public string $location = '';

    public function mount(): void
    {
        $this->authorize(Permission::PartsView);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'criticality', 'location'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Part>
     */
    #[Computed]
    public function parts(): LengthAwarePaginator
    {
        $criticality = Criticality::tryFrom($this->criticality);

        return app(PartSearch::class)->matching($this->search)
            ->with(['stock', 'defaultLocation'])
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when($criticality, fn ($query, Criticality $criticality) => $query->where('criticality', $criticality->value))
            ->when($this->location !== '', fn ($query) => $query->where('default_location_id', (int) $this->location))
            ->paginate(25);
    }

    /**
     * @return Collection<int, StorageLocation>
     */
    #[Computed]
    public function locations(): Collection
    {
        return StorageLocation::query()->orderBy('code')->get();
    }
}
```

Create `resources/views/livewire/parts/index.blade.php`:

```blade
<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Spare parts') }}</flux:heading>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Part number or description')" class="max-w-sm" autofocus />

        <flux:select wire:model.live="criticality" class="max-w-xs">
            <flux:select.option value="">{{ __('Any criticality') }}</flux:select.option>
            @foreach (\App\Enums\Criticality::cases() as $option)
                <flux:select.option :value="$option->value">{{ __(ucfirst($option->value)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="location" class="max-w-xs">
            <flux:select.option value="">{{ __('Any location') }}</flux:select.option>
            @foreach ($this->locations as $option)
                <flux:select.option :value="$option->id">{{ $option->code }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->parts">
        <flux:table.columns>
            <flux:table.column>{{ __('Part number') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Location') }}</flux:table.column>
            <flux:table.column align="end">{{ __('On hand') }}</flux:table.column>
            <flux:table.column>{{ __('Criticality') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->parts as $part)
                <flux:table.row :key="$part->id">
                    <flux:table.cell>
                        <flux:link :href="route('parts.show', $part)" wire:navigate>{{ $part->part_number }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-normal">{{ $part->description }}</flux:table.cell>
                    <flux:table.cell>{{ $part->defaultLocation?->code }}</flux:table.cell>
                    <flux:table.cell align="end">
                        {{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}
                    </flux:table.cell>
                    <flux:table.cell>{{ __(ucfirst($part->criticality->value)) }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$part->is_active ? 'green' : 'zinc'">
                            {{ $part->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

Create `app/Livewire/Parts/Show.php`:

```php
<?php

namespace App\Livewire\Parts;

use App\Enums\Permission;
use App\Models\Part;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Spare part')]
class Show extends Component
{
    #[Locked]
    public int $partId;

    public function mount(Part $part): void
    {
        $this->authorize(Permission::PartsView);

        $this->partId = $part->id;
    }

    #[Computed]
    public function part(): Part
    {
        return Part::query()
            ->with(['stock', 'defaultLocation', 'equipment' => fn ($query) => $query->orderBy('code')])
            ->findOrFail($this->partId);
    }
}
```

Create `resources/views/livewire/parts/show.blade.php`:

```blade
@php($part = $this->part)

<section class="w-full max-w-4xl">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $part->part_number }}</flux:heading>
            <flux:subheading>{{ $part->description }}</flux:subheading>
            <div class="mt-2 flex gap-2">
                <flux:badge size="sm" :color="$part->is_active ? 'green' : 'zinc'">{{ $part->is_active ? __('Active') : __('Inactive') }}</flux:badge>
                <flux:badge size="sm">{{ __(ucfirst($part->criticality->value)) }}</flux:badge>
            </div>
        </div>

        <div class="flex gap-2" data-part-actions>
        </div>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        <flux:card>
            <flux:heading size="lg">{{ __('Stock') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-2 gap-y-2">
                <dt><flux:text>{{ __('On hand') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->stock?->on_hand) }} {{ $part->unit_of_measure }}</dd>
                <dt><flux:text>{{ __('Unit of measure') }}</flux:text></dt>
                <dd>{{ $part->unit_of_measure }}</dd>
                <dt><flux:text>{{ __('Storage location') }}</flux:text></dt>
                <dd>{{ $part->defaultLocation ? $part->defaultLocation->code.' — '.$part->defaultLocation->name : __('Not assigned') }}</dd>
            </dl>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Stock levels') }}</flux:heading>
            <dl class="mt-4 grid grid-cols-2 gap-y-2">
                <dt><flux:text>{{ __('Reorder point') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->reorder_point) }}</dd>
                <dt><flux:text>{{ __('Minimum level') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->min_level) }}</dd>
                <dt><flux:text>{{ __('Critical level') }}</flux:text></dt>
                <dd>{{ \App\Support\Quantity::display($part->critical_level) }}</dd>
                <dt><flux:text>{{ __('Maximum level') }}</flux:text></dt>
                <dd>{{ $part->max_level === null ? __('Not set') : \App\Support\Quantity::display($part->max_level) }}</dd>
                <dt><flux:text>{{ __('Lead time') }}</flux:text></dt>
                <dd>{{ $part->lead_time_days === null ? __('Not set') : trans_choice(':count day|:count days', $part->lead_time_days) }}</dd>
            </dl>
        </flux:card>
    </div>

    <flux:card class="mt-6">
        <flux:heading size="lg">{{ __('Used on equipment') }}</flux:heading>
        <ul class="mt-4 space-y-1">
            @forelse ($part->equipment as $machine)
                <li>{{ $machine->code }} <flux:text class="inline">{{ $machine->name }}</flux:text></li>
            @empty
                <li><flux:text>{{ __('No equipment linked yet.') }}</flux:text></li>
            @endforelse
        </ul>
    </flux:card>
</section>
```

The empty `data-part-actions` block is where Tasks 10 and 11 add their buttons.

In `resources/views/livewire/master-data/equipment/form.blade.php`, replace `<span data-part-number>{{ $part->part_number }}</span>` with:

```blade
                        <flux:link :href="route('parts.show', $part)" wire:navigate>{{ $part->part_number }}</flux:link>
```

- [ ] **Step 5: Add the routes and the menu item**

In `routes/spims.php`, add `use App\Livewire\Parts;` to the imports and these routes inside the group:

```php
    Route::livewire('parts', Parts\Index::class)->name('parts.index');
    Route::livewire('parts/{part}', Parts\Show::class)->whereNumber('part')->name('parts.show');
```

In `app/Support/Navigation.php`, add this row directly after the `Dashboard` row:

```php
        ['group' => 'Storeroom', 'label' => 'Spare parts', 'icon' => 'cube', 'route' => 'parts.index', 'active' => 'parts.*', 'permission' => Permission::PartsView],
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan migrate
php artisan test tests/Feature/Parts/PartListTest.php tests/Feature/MasterData/EquipmentTest.php
```

Expected: PASS. `PartListTest` contributes 10 tests.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_19_200002_add_part_number_prefix_index.php app/Domain/Parts/PartSearch.php app/Support/Quantity.php app/Livewire/Parts/Index.php app/Livewire/Parts/Show.php resources/views/livewire/parts/index.blade.php resources/views/livewire/parts/show.blade.php resources/views/livewire/master-data/equipment/form.blade.php routes/spims.php app/Support/Navigation.php tests/Feature/Parts/PartListTest.php
git commit -m "Search, list and show spare parts with their stock balance"
```

---

## Task 10: Spare part records

**Files:**
- Create: `app/Domain/Parts/PartRecords.php`, `app/Domain/Parts/Exceptions/DuplicatePartNumber.php`, `app/Domain/Parts/Exceptions/PartChangeRefused.php`, `app/Policies/PartPolicy.php`, `app/Livewire/Parts/Form.php`, `resources/views/livewire/parts/form.blade.php`, `tests/Feature/Parts/PartRecordsTest.php`
- Modify: `routes/spims.php`, `resources/views/livewire/parts/index.blade.php`, `resources/views/livewire/parts/show.blade.php`

**Interfaces:**
- Consumes:
  - `Part`, `StorageLocation`, `Equipment` and `StockMovement` (Plan 1).
  - `Permission::PartsCreate`, `PartsEdit` and `PartsEditBasic`.
  - `AuditLogger`, `ActsAsSignedInUser` and `SearchTerm`.
- Produces:
  - `PartRecords` methods:
    - `create(array{part_number, description, unit_of_measure, default_location_id} $attributes, list<int> $equipmentIds, User $actor): Part`
    - `update(Part $part, array $changes, ?list<int> $equipmentIds, User $actor): void`. It changes only the keys given; `null` equipment leaves the links alone.
  - `DuplicatePartNumber`, with public `$existing` (a `Part`).
  - `PartChangeRefused`, with a public `$field`.
  - `PartPolicy` abilities `create`, `update` and `updateAllFields`. Laravel discovers it from the model name. Task 11 adds `manageStockLevels`.
  - Routes `parts.create` and `parts.edit`.

PRD §8 lets a custodian edit a spare part's description, unit of measure and storage location, but not its status (`PartsEditBasic`). Everything else needs `PartsEdit`. Equipment links count as "everything else".

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Parts/PartRecordsTest.php`:

```php
<?php

use App\Enums\Role;
use App\Livewire\Parts\Form;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StorageLocation;
use Livewire\Livewire;

it('lets supervisors, planners and administrators create spare parts', function (Role $role, bool $allowed) {
    $this->actingAs(userWithRoles($role))->get(route('parts.create'))->assertStatus($allowed ? 200 : 403);
})->with(roleMatrix('-SPA'));

it('lets every role open the edit form, custodians for basic fields', function (Role $role, bool $allowed) {
    $part = Part::factory()->create();

    $this->actingAs(userWithRoles($role))->get(route('parts.edit', $part))->assertStatus($allowed ? 200 : 403);
})->with(roleMatrix('CSPA'));

it('creates a part at zero stock with its equipment, audited', function () {
    $planner = userWithRoles(Role::Planner);
    $rack = StorageLocation::factory()->create(['code' => 'A-01']);
    $cnc = Equipment::factory()->create(['code' => 'CNC-01']);
    $press = Equipment::factory()->create(['code' => 'PRS-02']);

    Livewire::actingAs($planner)->test(Form::class)
        ->set('part_number', ' BRG-6204 ')
        ->set('description', 'Deep groove ball bearing')
        ->set('unit_of_measure', 'pcs')
        ->set('default_location_id', (string) $rack->id)
        ->set('equipment_ids', [(string) $cnc->id, (string) $press->id])
        ->call('save')
        ->assertHasNoErrors();

    $part = Part::query()->sole();

    expect($part->part_number)->toBe('BRG-6204')
        ->and(onHand($part))->toBe('0.000')
        ->and($part->equipment()->orderBy('code')->pluck('code')->all())->toBe(['CNC-01', 'PRS-02'])
        ->and(AuditLog::query()->where('auditable_type', 'part')->where('event', 'created')->sole())
        ->user_id->toBe($planner->id)
        ->new_values->toBe([
            'part_number' => 'BRG-6204',
            'description' => 'Deep groove ball bearing',
            'unit_of_measure' => 'pcs',
            'default_location' => 'A-01',
            'is_active' => true,
            'equipment' => ['CNC-01', 'PRS-02'],
        ]);
});

it('refuses a part number that exists in any letter case, and links to it', function () {
    $existing = Part::factory()->create(['part_number' => 'BRG-6204']);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class)
        ->set('part_number', 'brg-6204')
        ->set('description', 'Duplicate')
        ->set('unit_of_measure', 'pcs')
        ->call('save')
        ->assertHasErrors('part_number')
        ->assertSet('duplicateOfId', $existing->id)
        ->assertSee(route('parts.show', $existing));
});

it('refuses an Inactive storage location', function () {
    $closed = StorageLocation::factory()->create(['is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class)
        ->set('part_number', 'SEAL-9')
        ->set('description', 'Oil seal')
        ->set('unit_of_measure', 'pcs')
        ->set('default_location_id', (string) $closed->id)
        ->call('save')
        ->assertHasErrors('default_location_id');
});

it('fixes the unit of measure once the part has stock movements', function () {
    configureInstall();
    $moved = partWithStock('5', ['unit_of_measure' => 'L']);
    $unmoved = Part::factory()->create(['unit_of_measure' => 'L']);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $moved])
        ->set('unit_of_measure', 'mL')
        ->call('save')
        ->assertHasErrors('unit_of_measure');

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $unmoved])
        ->set('unit_of_measure', 'mL')
        ->call('save')
        ->assertHasNoErrors();

    expect($moved->refresh()->unit_of_measure)->toBe('L')
        ->and($unmoved->refresh()->unit_of_measure)->toBe('mL');
});

it('asks for confirmation before deactivating a part that still has stock', function () {
    configureInstall();
    $part = partWithStock('2');

    $screen = Livewire::actingAs(userWithRoles(Role::Planner))->test(Form::class, ['part' => $part])
        ->set('is_active', false)
        ->assertSee('no longer be available for withdrawal')
        ->call('save')
        ->assertHasErrors('confirmDeactivation');

    expect($part->refresh()->is_active)->toBeTrue();

    $screen->set('confirmDeactivation', true)->call('save')->assertHasNoErrors();

    expect($part->refresh()->is_active)->toBeFalse();
});

it('lets a custodian change only the description, unit of measure and location', function () {
    $part = Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Bearing']);
    $rack = StorageLocation::factory()->create();
    $machine = Equipment::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Custodian))->test(Form::class, ['part' => $part])
        ->set('part_number', 'CHANGED')
        ->set('is_active', false)
        ->set('equipment_ids', [(string) $machine->id])
        ->set('description', 'Deep groove ball bearing')
        ->set('default_location_id', (string) $rack->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($part->refresh())
        ->part_number->toBe('BRG-6204')
        ->is_active->toBeTrue()
        ->description->toBe('Deep groove ball bearing')
        ->default_location_id->toBe($rack->id)
        ->and($part->equipment()->exists())->toBeFalse();
});

it('records the old and new values of an edit, equipment links included', function () {
    $old = Equipment::factory()->create(['code' => 'CNC-01']);
    $new = Equipment::factory()->create(['code' => 'PRS-02']);
    $part = Part::factory()->create(['description' => 'Bearing']);
    $part->equipment()->attach($old->id);

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['part' => $part])
        ->set('description', 'Deep groove ball bearing')
        ->set('equipment_ids', [(string) $new->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(AuditLog::query()->where('auditable_type', 'part')->where('event', 'updated')->sole())
        ->old_values->toBe(['description' => 'Bearing', 'equipment' => ['CNC-01']])
        ->new_values->toBe(['description' => 'Deep groove ball bearing', 'equipment' => ['PRS-02']]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Parts/PartRecordsTest.php
```

Expected: FAIL with `Route [parts.create] not defined.`

- [ ] **Step 3: Create the policy**

Create `app/Policies/PartPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Part;
use App\Models\User;

/**
 * PRD §8 for spare part records. A custodian creates parts only when receiving them (Plan 3,
 * PartsCreateOnReceipt) and edits the basic fields only (PartsEditBasic).
 */
final class PartPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::PartsCreate);
    }

    public function update(User $user, Part $part): bool
    {
        return $user->can(Permission::PartsEdit) || $user->can(Permission::PartsEditBasic);
    }

    /**
     * Part number, status and equipment links, beyond the basic fields.
     */
    public function updateAllFields(User $user, Part $part): bool
    {
        return $user->can(Permission::PartsEdit);
    }
}
```

- [ ] **Step 4: Create the service**

Create `app/Domain/Parts/Exceptions/DuplicatePartNumber.php`:

```php
<?php

namespace App\Domain\Parts\Exceptions;

use App\Models\Part;
use DomainException;

/**
 * PRD FR-SPIMS-001: a part number that already exists, ignoring case, blocks creation and links to
 * the existing record.
 */
final class DuplicatePartNumber extends DomainException
{
    public function __construct(public readonly Part $existing)
    {
        parent::__construct("Part number {$existing->part_number} already exists.");
    }
}
```

Create `app/Domain/Parts/Exceptions/PartChangeRefused.php`:

```php
<?php

namespace App\Domain\Parts\Exceptions;

use DomainException;

/**
 * A change to a spare part record that PRD FR-SPIMS-001 forbids. $field names the input the refusal
 * is about.
 */
final class PartChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function inactiveLocation(): self
    {
        return new self('Choose an Active storage location.', 'default_location_id');
    }

    public static function unitOfMeasureLocked(): self
    {
        return new self(
            'The unit of measure cannot change once the part has stock movements: it would change the meaning of every recorded quantity.',
            'unit_of_measure',
        );
    }
}
```

Create `app/Domain/Parts/PartRecords.php`:

```php
<?php

namespace App\Domain\Parts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Parts\Exceptions\DuplicatePartNumber;
use App\Domain\Parts\Exceptions\PartChangeRefused;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spare part records (PRD FR-SPIMS-001). The stock balance is never entered here: a new part starts
 * at zero through the parts_create_stock_row trigger and changes only through stock movements.
 * Parts are set to Inactive, never deleted.
 */
final readonly class PartRecords
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{part_number: string, description: string, unit_of_measure: string, default_location_id: int|null}  $attributes
     * @param  list<int>  $equipmentIds
     *
     * @throws DuplicatePartNumber
     * @throws PartChangeRefused
     */
    public function create(array $attributes, array $equipmentIds, User $actor): Part
    {
        $partNumber = trim($attributes['part_number']);
        $this->guardUniquePartNumber($partNumber, null);
        $this->guardActiveLocation($attributes['default_location_id'], null);

        return DB::transaction(function () use ($attributes, $partNumber, $equipmentIds, $actor): Part {
            $part = Part::query()->create([
                'part_number' => $partNumber,
                'description' => trim($attributes['description']),
                'unit_of_measure' => trim($attributes['unit_of_measure']),
                'default_location_id' => $attributes['default_location_id'],
                'is_active' => true,
            ]);

            // Plain ids: every row gets the same pivot keys (Plan 1 Task 5 review).
            $part->equipment()->sync($equipmentIds);

            $this->audit->record('created', $part, null, $this->snapshot($part), $actor);

            return $part;
        });
    }

    /**
     * Change only the fields given, so a custodian's save cannot touch the others.
     *
     * @param  array{part_number?: string, description?: string, unit_of_measure?: string, default_location_id?: int|null, is_active?: bool}  $changes
     * @param  list<int>|null  $equipmentIds  null leaves the equipment links as they are
     *
     * @throws DuplicatePartNumber
     * @throws PartChangeRefused
     */
    public function update(Part $part, array $changes, ?array $equipmentIds, User $actor): void
    {
        foreach (['part_number', 'description', 'unit_of_measure'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = trim($changes[$field]);
            }
        }

        if (isset($changes['part_number'])) {
            $this->guardUniquePartNumber($changes['part_number'], $part);
        }

        if (array_key_exists('default_location_id', $changes)) {
            $this->guardActiveLocation($changes['default_location_id'], $part);
        }

        if (isset($changes['unit_of_measure']) && $changes['unit_of_measure'] !== $part->unit_of_measure) {
            $this->guardUnitOfMeasureUnlocked($part);
        }

        DB::transaction(function () use ($part, $changes, $equipmentIds, $actor): void {
            $old = $this->snapshot($part);

            $part->forceFill($changes)->save();

            if ($equipmentIds !== null) {
                $part->equipment()->sync($equipmentIds);
            }

            $new = $this->snapshot($part);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record('updated', $part, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
            }
        });
    }

    /**
     * @throws DuplicatePartNumber
     */
    private function guardUniquePartNumber(string $partNumber, ?Part $except): void
    {
        $existing = Part::query()
            ->whereRaw('lower(part_number) = ?', [Str::lower($partNumber)])
            ->when($except, fn ($query, Part $except) => $query->whereKeyNot($except->id))
            ->first();

        if ($existing !== null) {
            throw new DuplicatePartNumber($existing);
        }
    }

    /**
     * PRD FR-SPIMS-003: Inactive locations cannot be assigned. A part may keep the location it already has.
     *
     * @throws PartChangeRefused
     */
    private function guardActiveLocation(?int $locationId, ?Part $part): void
    {
        if ($locationId === null || $locationId === $part?->default_location_id) {
            return;
        }

        if (! StorageLocation::query()->whereKey($locationId)->where('is_active', true)->exists()) {
            throw PartChangeRefused::inactiveLocation();
        }
    }

    /**
     * @throws PartChangeRefused
     */
    private function guardUnitOfMeasureUnlocked(Part $part): void
    {
        if (StockMovement::query()->where('part_id', $part->id)->exists()) {
            throw PartChangeRefused::unitOfMeasureLocked();
        }
    }

    /**
     * What an audit entry shows: codes rather than ids, read fresh from the database.
     *
     * @return array{part_number: string, description: string, unit_of_measure: string, default_location: string|null, is_active: bool, equipment: list<string>}
     */
    private function snapshot(Part $part): array
    {
        return [
            'part_number' => $part->part_number,
            'description' => $part->description,
            'unit_of_measure' => $part->unit_of_measure,
            'default_location' => $part->default_location_id === null
                ? null
                : (string) StorageLocation::query()->whereKey($part->default_location_id)->value('code'),
            'is_active' => $part->is_active,
            'equipment' => array_values(array_map(
                fn (mixed $code): string => (string) $code,
                $part->equipment()->orderBy('code')->pluck('code')->all(),
            )),
        ];
    }
}
```

- [ ] **Step 5: Create the form**

Create `app/Livewire/Parts/Form.php`:

```php
<?php

namespace App\Livewire\Parts;

use App\Domain\Parts\Exceptions\DuplicatePartNumber;
use App\Domain\Parts\Exceptions\PartChangeRefused;
use App\Domain\Parts\PartRecords;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Support\SearchTerm;
use Brick\Math\BigDecimal;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Spare part')]
class Form extends Component
{
    use ActsAsSignedInUser;

    /** The part being edited; null while creating one. */
    #[Locked]
    public ?int $partId = null;

    /** The existing part a refused part number belongs to, for the "open it" link. */
    #[Locked]
    public ?int $duplicateOfId = null;

    public string $part_number = '';

    public string $description = '';

    public string $unit_of_measure = 'pcs';

    /** A storage location id, or empty for none. */
    public string $default_location_id = '';

    /** @var list<string> */
    public array $equipment_ids = [];

    public string $equipmentFilter = '';

    public bool $is_active = true;

    public bool $confirmDeactivation = false;

    public function mount(?Part $part = null): void
    {
        if (! $part?->exists) {
            $this->authorize('create', Part::class);

            return;
        }

        $this->authorize('update', $part);

        $this->partId = $part->id;
        $this->part_number = $part->part_number;
        $this->description = $part->description;
        $this->unit_of_measure = $part->unit_of_measure;
        $this->default_location_id = (string) $part->default_location_id;
        $this->equipment_ids = array_values(array_map(
            fn (mixed $id): string => (string) $id,
            $part->equipment()->pluck('equipment.id')->all(),
        ));
        $this->is_active = $part->is_active;
    }

    public function save(PartRecords $records): void
    {
        $part = $this->partId === null ? null : Part::query()->findOrFail($this->partId);
        $this->authorize($part === null ? 'create' : 'update', $part ?? Part::class);
        $allFields = $this->canEditAllFields();

        $this->validate([
            'part_number' => $allFields ? ['required', 'string', 'max:100'] : [],
            'description' => ['required', 'string', 'max:500'],
            'unit_of_measure' => ['required', 'string', 'max:20'],
            'default_location_id' => ['nullable', 'integer', Rule::exists('storage_locations', 'id')],
            'equipment_ids' => ['array'],
            'equipment_ids.*' => ['integer', Rule::exists('equipment', 'id')],
            'is_active' => ['boolean'],
        ]);

        $locationId = $this->default_location_id === '' ? null : (int) $this->default_location_id;
        $equipmentIds = array_map(fn (string $id): int => (int) $id, $this->equipment_ids);

        // PRD FR-SPIMS-001: warn, then save once confirmed.
        if ($part !== null && $allFields && $part->is_active && ! $this->is_active && $this->holdsStock() && ! $this->confirmDeactivation) {
            $this->addError('confirmDeactivation', __('This part still has stock. Confirm that it will no longer be available for withdrawal.'));

            return;
        }

        try {
            if ($part === null) {
                $part = $records->create([
                    'part_number' => $this->part_number,
                    'description' => $this->description,
                    'unit_of_measure' => $this->unit_of_measure,
                    'default_location_id' => $locationId,
                ], $equipmentIds, $this->actor());
            } else {
                $changes = [
                    'description' => $this->description,
                    'unit_of_measure' => $this->unit_of_measure,
                    'default_location_id' => $locationId,
                ];

                if ($allFields) {
                    $changes['part_number'] = $this->part_number;
                    $changes['is_active'] = $this->is_active;
                }

                $records->update($part, $changes, $allFields ? $equipmentIds : null, $this->actor());
            }
        } catch (DuplicatePartNumber $duplicate) {
            $this->duplicateOfId = $duplicate->existing->id;
            $this->addError('part_number', $duplicate->getMessage());

            return;
        } catch (PartChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Spare part saved.'));

        $this->redirectRoute('parts.show', ['part' => $part], navigate: true);
    }

    /**
     * Part number, status and equipment links are for PartsEdit holders; a custodian sees them
     * read-only.
     */
    #[Computed]
    public function canEditAllFields(): bool
    {
        return $this->partId === null
            || $this->actor()->can('updateAllFields', Part::query()->findOrFail($this->partId));
    }

    #[Computed]
    public function holdsStock(): bool
    {
        $onHand = $this->partId === null ? null : Part::query()->findOrFail($this->partId)->stock?->on_hand;

        return $onHand !== null && BigDecimal::of($onHand)->isPositive();
    }

    /**
     * Active locations, plus the part's current one if it has since been deactivated.
     *
     * @return Collection<int, StorageLocation>
     */
    #[Computed]
    public function locationOptions(): Collection
    {
        return StorageLocation::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($this->default_location_id !== '', fn ($query) => $query->orWhere('id', (int) $this->default_location_id)))
            ->orderBy('code')
            ->get();
    }

    /**
     * Active equipment, plus any already linked, narrowed by the filter.
     *
     * @return Collection<int, Equipment>
     */
    #[Computed]
    public function equipmentOptions(): Collection
    {
        $linked = array_map(fn (string $id): int => (int) $id, $this->equipment_ids);

        return Equipment::query()
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $linked))
            ->when(trim($this->equipmentFilter) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'ilike', SearchTerm::contains($this->equipmentFilter))
                ->orWhere('name', 'ilike', SearchTerm::contains($this->equipmentFilter))))
            ->orderBy('code')
            ->limit(200)
            ->get();
    }
}
```

Create `resources/views/livewire/parts/form.blade.php`:

```blade
<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $partId === null ? __('New spare part') : __('Edit spare part') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-2">
            <flux:input wire:model="part_number" :label="__('Part number')" :disabled="! $this->canEditAllFields" required />
            @if ($duplicateOfId !== null)
                <flux:link :href="route('parts.show', $duplicateOfId)" wire:navigate class="text-sm">{{ __('Open the existing spare part') }}</flux:link>
            @endif
        </div>

        <flux:input wire:model="description" :label="__('Description')" required />
        <flux:input wire:model="unit_of_measure" :label="__('Unit of measure')" :description="__('Such as pcs, m, L or kg. Fixed once the part has stock movements.')" required />

        <flux:select wire:model="default_location_id" :label="__('Storage location')">
            <flux:select.option value="">{{ __('Not assigned') }}</flux:select.option>
            @foreach ($this->locationOptions as $location)
                <flux:select.option :value="$location->id">{{ $location->code }} — {{ $location->name }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($this->canEditAllFields)
            <div class="space-y-3">
                <flux:input wire:model.live.debounce.300ms="equipmentFilter" icon="magnifying-glass" :label="__('Used on equipment')" :placeholder="__('Filter equipment')" />

                <flux:checkbox.group wire:model="equipment_ids">
                    @foreach ($this->equipmentOptions as $machine)
                        <flux:checkbox :value="(string) $machine->id" :label="$machine->code.' — '.$machine->name" />
                    @endforeach
                </flux:checkbox.group>
            </div>

            @if ($partId !== null)
                <flux:switch wire:model.live="is_active" :label="__('Active')" />

                @if (! $is_active && $this->holdsStock)
                    <flux:checkbox wire:model="confirmDeactivation" :label="__('This part still has stock. I understand it will no longer be available for withdrawal.')" />
                    <flux:error name="confirmDeactivation" />
                @endif
            @endif
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="$partId === null ? route('parts.index') : route('parts.show', $partId)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
```

- [ ] **Step 6: Add the routes and the entry points**

In `routes/spims.php`, add these routes inside the group:

```php
    Route::livewire('parts/create', Parts\Form::class)->name('parts.create');
    Route::livewire('parts/{part}/edit', Parts\Form::class)->whereNumber('part')->name('parts.edit');
```

In `resources/views/livewire/parts/index.blade.php`, add this button after the `Spare parts` heading:

```blade
        @can('create', \App\Models\Part::class)
            <flux:button variant="primary" icon="plus" :href="route('parts.create')" wire:navigate>{{ __('New spare part') }}</flux:button>
        @endcan
```

In `resources/views/livewire/parts/show.blade.php`, put this button inside the `data-part-actions` block:

```blade
            @can('update', $part)
                <flux:button icon="pencil-square" :href="route('parts.edit', $part)" wire:navigate>{{ __('Edit') }}</flux:button>
            @endcan
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Parts
```

Expected: PASS. `PartRecordsTest` contributes 15 tests.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/Parts/PartRecords.php app/Domain/Parts/Exceptions app/Policies/PartPolicy.php app/Livewire/Parts/Form.php resources/views/livewire/parts/form.blade.php resources/views/livewire/parts/index.blade.php resources/views/livewire/parts/show.blade.php routes/spims.php tests/Feature/Parts/PartRecordsTest.php
git commit -m "Create and edit spare part records, custodians limited to the basic fields"
```

---

## Task 11: Stock levels, criticality and the change history

**Files:**
- Create: `app/Domain/Parts/StockLevels.php`, `app/Support/AuditValue.php`, `app/Livewire/Parts/StockLevelsForm.php`, `resources/views/livewire/parts/stock-levels-form.blade.php`, `tests/Feature/Parts/StockLevelsTest.php`
- Modify: `app/Policies/PartPolicy.php`, `app/Livewire/Parts/Show.php`, `resources/views/livewire/parts/show.blade.php`, `routes/spims.php`

**Interfaces:**
- Consumes:
  - `Part` and its `parts_levels_ordered` and `parts_levels_non_negative` CHECKs (Plan 1).
  - `Criticality` and `AuditLog`.
  - `Permission::StockLevelsManage` and `PartAuditView`.
  - `PartPolicy` (Task 10) and `Quantity` (Task 9).
- Produces:
  - `StockLevels` methods:
    - `warnings(array{reorder_point, min_level, critical_level, max_level} $levels): list<string>`
    - `update(Part $part, array $levels, User $actor): void`
  - `PartPolicy::manageStockLevels()`.
  - `AuditValue::display(mixed): string`, which Plan 6's audit views reuse.
  - Route `parts.stock-levels`.
  - The part's change history on `parts.show`.

The rule, restated from Decision 2 above:

- **Refused:** critical level above the minimum, or maximum below the minimum (PRD FR-SPIMS-002, and the database CHECK).
- **Warned, then saved once confirmed:** reorder point below the minimum, or above the maximum (design §4.4, open question Q8).
- **Plan 5:** re-evaluating alerts after a change.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Parts/StockLevelsTest.php`:

```php
<?php

use App\Enums\Criticality;
use App\Enums\Role;
use App\Livewire\Parts\StockLevelsForm;
use App\Models\AuditLog;
use App\Models\Part;
use Livewire\Livewire;

it('lets only planners and administrators set stock levels', function (Role $role, bool $allowed) {
    $part = Part::factory()->create();

    $this->actingAs(userWithRoles($role))->get(route('parts.stock-levels', $part))->assertStatus($allowed ? 200 : 403);
})->with(roleMatrix('--PA'));

it('saves levels, criticality and lead time, recording old and new values', function () {
    $planner = userWithRoles(Role::Planner);
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs($planner)->test(StockLevelsForm::class, ['part' => $part])
        ->set('reorder_point', '10')
        ->set('min_level', '4')
        ->set('critical_level', '1.5')
        ->set('max_level', '30')
        ->set('criticality', 'critical')
        ->set('lead_time_days', '21')
        ->call('save')
        ->assertHasNoErrors();

    expect($part->refresh())
        ->reorder_point->toBe('10.000')
        ->min_level->toBe('4.000')
        ->critical_level->toBe('1.500')
        ->max_level->toBe('30.000')
        ->criticality->toBe(Criticality::Critical)
        ->lead_time_days->toBe(21)
        ->and(AuditLog::query()->where('auditable_type', 'part')->where('event', 'updated')->sole())
        ->user_id->toBe($planner->id)
        ->old_values->toBe([
            'reorder_point' => '0.000',
            'min_level' => '0.000',
            'critical_level' => '0.000',
            'max_level' => null,
            'criticality' => 'normal',
            'lead_time_days' => null,
        ])
        ->new_values->toBe([
            'reorder_point' => '10.000',
            'min_level' => '4.000',
            'critical_level' => '1.500',
            'max_level' => '30.000',
            'criticality' => 'critical',
            'lead_time_days' => 21,
        ]);
});

it('refuses a critical level above the minimum, and a maximum below it', function () {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '5')
        ->set('critical_level', '6')
        ->set('max_level', '4')
        ->call('save')
        ->assertHasErrors(['critical_level' => 'lte', 'max_level' => 'gte']);
});

it('warns before saving a reorder point below the minimum, then saves once confirmed', function () {
    $part = Part::factory()->create()->refresh();

    $screen = Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '5')
        ->set('reorder_point', '3')
        ->assertSee('below the minimum level')
        ->call('save')
        ->assertHasErrors('confirmWarnings');

    expect($part->refresh()->reorder_point)->toBe('0.000');

    $screen->set('confirmWarnings', true)->call('save')->assertHasNoErrors();

    expect($part->refresh()->reorder_point)->toBe('3.000');
});

it('warns about a reorder point above the maximum', function () {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '2')
        ->set('reorder_point', '12')
        ->set('max_level', '10')
        ->call('save')
        ->assertHasErrors('confirmWarnings')
        ->assertSee('above the maximum level');
});

it('accepts quantities to three decimal places and whole-day lead times only', function () {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '0.5')
        ->set('reorder_point', '0.7505')
        ->set('lead_time_days', '2.5')
        ->call('save')
        ->assertHasErrors(['reorder_point' => 'decimal', 'lead_time_days' => 'integer']);
});

it('shows the change history to supervisors, planners and administrators only', function (Role $role, bool $allowed) {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('reorder_point', '8')
        ->call('save')
        ->assertHasNoErrors();

    $response = $this->actingAs(userWithRoles($role))->get(route('parts.show', $part));

    $allowed
        ? $response->assertSee('Change history')->assertSee('8.000')
        : $response->assertDontSee('Change history');
})->with(roleMatrix('-SPA'));
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Parts/StockLevelsTest.php
```

Expected: FAIL with `Route [parts.stock-levels] not defined.`

- [ ] **Step 3: Create the service and the policy ability**

Create `app/Domain/Parts/StockLevels.php`:

```php
<?php

namespace App\Domain\Parts;

use App\Domain\Audit\AuditLogger;
use App\Enums\Criticality;
use App\Models\Part;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;

/**
 * Stock levels, criticality and lead time (PRD FR-SPIMS-002, design §4.4). A critical level above
 * the minimum, or a maximum below it, is refused; the parts_levels_ordered CHECK refuses it too. A
 * reorder point outside minimum to maximum is saved after a warning, because a company may use the
 * levels differently (open question Q8).
 */
final readonly class StockLevels
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The warnings a user must confirm before these levels are saved.
     *
     * @param  array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null}  $levels
     * @return list<string>
     */
    public function warnings(array $levels): array
    {
        $reorder = BigDecimal::of($levels['reorder_point']);
        $warnings = [];

        if ($reorder->isLessThan($levels['min_level'])) {
            $warnings[] = 'The reorder point is below the minimum level, so replenishment would be requested only after stock falls below safety stock.';
        }

        if ($levels['max_level'] !== null && $reorder->isGreaterThan($levels['max_level'])) {
            $warnings[] = 'The reorder point is above the maximum level, so a replenishment could never lift stock above it.';
        }

        return $warnings;
    }

    /**
     * @param  array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: Criticality, lead_time_days: int|null}  $levels
     */
    public function update(Part $part, array $levels, User $actor): void
    {
        $old = $this->snapshot($part);

        $part->forceFill($levels)->save();
        // Read back as numeric(14,3) stores them, so "4" and "4.000" are the same value.
        $part->refresh();

        $new = $this->snapshot($part);
        $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $this->audit->record('updated', $part, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
        }
    }

    /**
     * @return array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: string, lead_time_days: int|null}
     */
    private function snapshot(Part $part): array
    {
        return [
            'reorder_point' => $part->reorder_point,
            'min_level' => $part->min_level,
            'critical_level' => $part->critical_level,
            'max_level' => $part->max_level,
            'criticality' => $part->criticality->value,
            'lead_time_days' => $part->lead_time_days,
        ];
    }
}
```

Add this method to `app/Policies/PartPolicy.php`:

```php
    public function manageStockLevels(User $user, Part $part): bool
    {
        return $user->can(Permission::StockLevelsManage);
    }
```

Create `app/Support/AuditValue.php`:

```php
<?php

namespace App\Support;

/**
 * A value from an audit entry's old_values or new_values, as a person reads it.
 */
final class AuditValue
{
    public static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', array_map(self::display(...), $value)),
            default => (string) $value,
        };
    }
}
```

- [ ] **Step 4: Create the form**

Create `app/Livewire/Parts/StockLevelsForm.php`:

```php
<?php

namespace App\Livewire\Parts;

use App\Domain\Parts\StockLevels;
use App\Enums\Criticality;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Part;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Stock levels')]
class StockLevelsForm extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $partId;

    public string $reorder_point = '0';

    public string $min_level = '0';

    public string $critical_level = '0';

    /** Empty when no maximum is set. */
    public string $max_level = '';

    public string $criticality = 'normal';

    /** Empty when no lead time is recorded. */
    public string $lead_time_days = '';

    public bool $confirmWarnings = false;

    public function mount(Part $part): void
    {
        $this->authorize('manageStockLevels', $part);

        $this->partId = $part->id;
        $this->reorder_point = Quantity::display($part->reorder_point);
        $this->min_level = Quantity::display($part->min_level);
        $this->critical_level = Quantity::display($part->critical_level);
        $this->max_level = Quantity::display($part->max_level);
        $this->criticality = $part->criticality->value;
        $this->lead_time_days = (string) $part->lead_time_days;
    }

    #[Computed]
    public function part(): Part
    {
        return Part::query()->findOrFail($this->partId);
    }

    /**
     * Warnings for the levels as typed, shown before saving. None while a value is not yet a number.
     *
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        foreach ($this->levels() as $value) {
            if ($value !== null && ! is_numeric($value)) {
                return [];
            }
        }

        return app(StockLevels::class)->warnings($this->levels());
    }

    public function save(StockLevels $stockLevels): void
    {
        $part = Part::query()->findOrFail($this->partId);
        $this->authorize('manageStockLevels', $part);

        $this->validate();

        if ($stockLevels->warnings($this->levels()) !== [] && ! $this->confirmWarnings) {
            $this->addError('confirmWarnings', __('Confirm the warning to save these levels.'));

            return;
        }

        $stockLevels->update($part, [
            ...$this->levels(),
            'criticality' => Criticality::from($this->criticality),
            'lead_time_days' => $this->lead_time_days === '' ? null : (int) $this->lead_time_days,
        ], $this->actor());

        Flux::toast(variant: 'success', text: __('Stock levels saved.'));

        $this->redirectRoute('parts.show', ['part' => $part], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $quantity = ['numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'];

        return [
            'reorder_point' => ['required', ...$quantity],
            'min_level' => ['required', ...$quantity],
            // PRD FR-SPIMS-002: blocked outright, as the parts_levels_ordered CHECK blocks it.
            'critical_level' => ['required', ...$quantity, 'lte:min_level'],
            'max_level' => ['nullable', ...$quantity, 'gte:min_level'],
            'criticality' => ['required', Rule::enum(Criticality::class)],
            'lead_time_days' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'critical_level.lte' => 'The critical level cannot be above the minimum level.',
            'max_level.gte' => 'The maximum level cannot be below the minimum level.',
        ];
    }

    /**
     * @return array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null}
     */
    private function levels(): array
    {
        return [
            'reorder_point' => $this->reorder_point,
            'min_level' => $this->min_level,
            'critical_level' => $this->critical_level,
            'max_level' => $this->max_level === '' ? null : $this->max_level,
        ];
    }
}
```

Create `resources/views/livewire/parts/stock-levels-form.blade.php`:

```blade
<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('Stock levels') }}</flux:heading>
    <flux:subheading class="mb-6">
        {{ $this->part->part_number }} — {{ $this->part->description }} ({{ $this->part->unit_of_measure }})
    </flux:subheading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model.live.blur="reorder_point" :label="__('Reorder point')" :description="__('Request replenishment at this level.')" inputmode="decimal" />
            <flux:input wire:model.live.blur="min_level" :label="__('Minimum level')" :description="__('Safety stock. Reaching it means replenishment is overdue.')" inputmode="decimal" />
            <flux:input wire:model.live.blur="critical_level" :label="__('Critical level')" :description="__('Equipment downtime risk is imminent at this level.')" inputmode="decimal" />
            <flux:input wire:model.live.blur="max_level" :label="__('Maximum level')" :description="__('Optional. The ceiling for a replenishment.')" inputmode="decimal" />
        </div>

        <flux:select wire:model="criticality" :label="__('Criticality')">
            @foreach (\App\Enums\Criticality::cases() as $option)
                <flux:select.option :value="$option->value">{{ __(ucfirst($option->value)) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="lead_time_days" type="number" min="1" step="1" :label="__('Lead time (days)')" :description="__('Optional. Typical days from order to receipt.')" />

        @foreach ($this->warnings as $warning)
            <flux:callout variant="warning" icon="exclamation-triangle" :text="__($warning)" />
        @endforeach

        @if ($this->warnings !== [])
            <flux:checkbox wire:model="confirmWarnings" :label="__('Save these levels anyway')" />
            <flux:error name="confirmWarnings" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('parts.show', $partId)" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
```

- [ ] **Step 5: Add the route, the button and the change history**

In `routes/spims.php`, add inside the group:

```php
    Route::livewire('parts/{part}/stock-levels', Parts\StockLevelsForm::class)->whereNumber('part')->name('parts.stock-levels');
```

In `app/Livewire/Parts/Show.php`, add `use App\Models\AuditLog;` and `use Illuminate\Database\Eloquent\Collection;` to the imports, and add this method:

```php
    /**
     * Changes to this spare part and its stock levels, newest first. PRD §8 shows them to
     * PartAuditView holders; the view checks that.
     *
     * @return Collection<int, AuditLog>
     */
    #[Computed]
    public function history(): Collection
    {
        return AuditLog::query()
            ->with('user')
            ->where('auditable_type', (new Part)->getMorphClass())
            ->where('auditable_id', (string) $this->partId)
            ->latest('id')
            ->limit(50)
            ->get();
    }
```

In `resources/views/livewire/parts/show.blade.php`, add this button inside the `data-part-actions` block, after the `Edit` button:

```blade
            @can('manageStockLevels', $part)
                <flux:button icon="adjustments-horizontal" :href="route('parts.stock-levels', $part)" wire:navigate>{{ __('Stock levels') }}</flux:button>
            @endcan
```

Then add this card before the closing `</section>`:

```blade
    @can(\App\Enums\Permission::PartAuditView)
        <flux:card class="mt-6">
            <flux:heading size="lg">{{ __('Change history') }}</flux:heading>

            <flux:table class="mt-4">
                <flux:table.columns>
                    <flux:table.column>{{ __('When') }}</flux:table.column>
                    <flux:table.column>{{ __('Who') }}</flux:table.column>
                    <flux:table.column>{{ __('Change') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->history as $entry)
                        <flux:table.row :key="$entry->id">
                            <flux:table.cell>
                                {{ $entry->created_at->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone())->format('Y-m-d H:i') }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $entry->user?->name }}</flux:table.cell>
                            <flux:table.cell class="whitespace-normal">
                                <div>{{ __(ucfirst(str_replace('_', ' ', $entry->event))) }}</div>
                                @foreach ($entry->new_values ?? [] as $field => $value)
                                    <div>
                                        <flux:text class="inline">{{ __(str_replace('_', ' ', $field)) }}:</flux:text>
                                        {{ \App\Support\AuditValue::display($entry->old_values[$field] ?? null) }}
                                        → {{ \App\Support\AuditValue::display($value) }}
                                    </div>
                                @endforeach
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endcan
```

Times are shown in the install time zone and stored in UTC (Global Constraints).

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Parts
```

Expected: PASS. `StockLevelsTest` contributes 13 tests.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Parts/StockLevels.php app/Support/AuditValue.php app/Livewire/Parts/StockLevelsForm.php resources/views/livewire/parts/stock-levels-form.blade.php app/Policies/PartPolicy.php app/Livewire/Parts/Show.php resources/views/livewire/parts/show.blade.php routes/spims.php tests/Feature/Parts/StockLevelsTest.php
git commit -m "Set stock levels and criticality, warning about an unusual reorder point, and show part history"
```

---

## Task 12: Personnel and badges

**Files:**
- Create: `app/Domain/Identity/PersonnelRegistry.php`, `app/Domain/Identity/Exceptions/DuplicateEmployeeNumber.php`, `app/Livewire/Personnel/Index.php`, `app/Livewire/Personnel/Form.php`, `resources/views/livewire/personnel/index.blade.php`, `resources/views/livewire/personnel/form.blade.php`, `tests/Feature/Personnel/PersonnelTest.php`
- Modify: `app/Models/Personnel.php`, `routes/spims.php`, `app/Support/Navigation.php`

**Interfaces:**
- Consumes:
  - `Personnel`, `PersonnelBadges::enroll()` and `identify()`, `BadgeAlreadyEnrolled` and `InvalidBadgeInput` (Plan 1).
  - `Permission::PersonnelManage` and `BadgesViewFull`.
  - The `media.show` route (Task 5), `AuditLogger`, `ActsAsSignedInUser` and `SearchTerm`.
- Produces:
  - `PersonnelRegistry` methods:
    - `register(string $employeeNo, string $fullName, ?string $department, ?string $photoPath, User $actor): Personnel`
    - `update(Personnel $personnel, string $employeeNo, string $fullName, ?string $department, ?string $newPhotoPath, bool $isActive, User $actor): void`. A `null` photo keeps the current one.
  - `DuplicateEmployeeNumber`.
  - The `Personnel` employee number mutator.
  - Routes `personnel.index`, `personnel.create` and `personnel.edit`.

Badges are only ever enrolled through Plan 1's `PersonnelBadges`, which audits `badge_enrolled`, `badge_replaced` and `badge_released`. The keyboard-wedge reader types the UID into the focused scan field and presses Enter.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Personnel/PersonnelTest.php`:

```php
<?php

use App\Domain\Identity\PersonnelBadges;
use App\Enums\Role;
use App\Livewire\Personnel\Form;
use App\Livewire\Personnel\Index;
use App\Models\AuditLog;
use App\Models\Personnel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('lets supervisors and administrators register personnel and enroll badges', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $person = Personnel::factory()->create();

    $this->actingAs($user)->get(route('personnel.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('personnel.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('personnel.edit', $person))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Personnel', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-S-A'));

it('registers a person with the employee number normalised, audited, then asks for the badge', function () {
    $supervisor = userWithRoles(Role::Supervisor);

    $screen = Livewire::actingAs($supervisor)->test(Form::class)
        ->set('employee_no', ' emp-00042 ')
        ->set('full_name', 'Jose Rizal')
        ->set('department', 'Maintenance')
        ->call('save')
        ->assertHasNoErrors();

    $person = Personnel::query()->sole();
    $screen->assertRedirect(route('personnel.edit', $person));

    expect($person->employee_no)->toBe('EMP-00042')
        ->and(AuditLog::query()->where('auditable_type', 'personnel')->where('event', 'created')->sole())
        ->user_id->toBe($supervisor->id)
        ->new_values->toBe([
            'employee_no' => 'EMP-00042',
            'full_name' => 'Jose Rizal',
            'department' => 'Maintenance',
            'photo_path' => null,
            'is_active' => true,
        ]);
});

it('refuses an employee number already registered, in any letter case', function () {
    Personnel::factory()->create(['employee_no' => 'EMP-00042']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class)
        ->set('employee_no', 'emp-00042')
        ->set('full_name', 'Someone Else')
        ->call('save')
        ->assertHasErrors('employee_no');
});

it('enrolls a scanned badge and shows it masked', function () {
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', "a1234 5678\n")
        ->call('enrollBadge')
        ->assertHasNoErrors()
        ->assertSet('badgeScan', '')
        ->assertSee('A1234****')
        ->assertDontSee('A12345678');

    expect(app(PersonnelBadges::class)->identify('A12345678')?->is($person))->toBeTrue();
});

it('refuses a badge an Active person holds, naming them', function () {
    Personnel::factory()->withBadge('A12345678')->create(['full_name' => 'Andres Bonifacio', 'employee_no' => 'EMP-1']);
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasErrors('badgeScan')
        ->assertSee('Andres Bonifacio')
        ->assertSee('A1234****');

    expect($person->refresh()->badge_uid_hash)->toBeNull();
});

it('takes a badge back from an Inactive person', function () {
    $former = Personnel::factory()->inactive()->withBadge('A12345678')->create();
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasNoErrors();

    expect($former->refresh()->badge_uid_hash)->toBeNull()
        ->and(app(PersonnelBadges::class)->identify('A12345678')?->is($person))->toBeTrue();
});

it('reports a badge enrolled elsewhere at the same moment as already enrolled', function () {
    $person = Personnel::factory()->create();
    $rival = Personnel::factory()->create();

    // The race from Plan 1's Task 6 review: another supervisor's enrollment of the same badge lands
    // between this enrollment's holder check and its save, so the unique index refuses the save.
    Personnel::updating(function (Personnel $saving) use ($rival): void {
        if (! $saving->is($rival) && $saving->badge_uid_hash !== null) {
            Personnel::query()->whereKey($rival->id)->update(['badge_uid_hash' => $saving->badge_uid_hash]);
        }
    });

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasErrors('badgeScan')
        ->assertSee('already enrolled');

    expect($person->refresh()->badge_uid_hash)->toBeNull();
});

it('deactivates a person, so their badge stops confirming withdrawals, audited', function () {
    $person = Personnel::factory()->withBadge('A12345678')->create();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class, ['personnel' => $person])
        ->set('is_active', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(PersonnelBadges::class)->identify('A12345678'))->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', 'personnel')->where('event', 'updated')->sole())
        ->old_values->toBe(['is_active' => true])
        ->new_values->toBe(['is_active' => false]);
});

it('shows full badge identifiers to System Administrators only', function () {
    $person = Personnel::factory()->withBadge('A12345678')->create();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Form::class, ['personnel' => $person])
        ->call('revealBadge')
        ->assertSee('A12345678');

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->assertDontSee('Show full badge')
        ->call('revealBadge')
        ->assertForbidden();
});

it('lists personnel with masked badges only, and finds them by number or name', function () {
    Personnel::factory()->withBadge('A12345678')->create(['employee_no' => 'EMP-1', 'full_name' => 'Jose Rizal']);
    Personnel::factory()->create(['employee_no' => 'EMP-2', 'full_name' => 'Apolinario Mabini']);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Index::class)
        ->assertSee('A1234****')
        ->assertDontSee('A12345678')
        ->set('search', 'mabini')
        ->assertSee('EMP-2')
        ->assertDontSee('EMP-1');
});

it('stores an uploaded photo', function () {
    Storage::fake();
    $person = Personnel::factory()->create();

    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Form::class, ['personnel' => $person])
        ->set('photo', UploadedFile::fake()->create('face.jpg', 50, 'image/jpeg'))
        ->call('save')
        ->assertHasNoErrors();

    expect($person->refresh()->photo_path)->toStartWith('personnel/');
    Storage::assertExists($person->photo_path);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Personnel/PersonnelTest.php
```

Expected: FAIL with `Route [personnel.index] not defined.`

- [ ] **Step 3: Normalise employee numbers**

In `app/Models/Personnel.php`, add `use Illuminate\Database\Eloquent\Casts\Attribute;` and `use Illuminate\Support\Str;` to the imports, and add this method after `casts()`:

```php
    /**
     * Trimmed and uppercased: employee numbers are unique as stored, so emp-1 and EMP-1 must not
     * become two people.
     *
     * @return Attribute<string, string>
     */
    protected function employeeNo(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Str::upper(trim($value)));
    }
```

- [ ] **Step 4: Create the registry**

Create `app/Domain/Identity/Exceptions/DuplicateEmployeeNumber.php`:

```php
<?php

namespace App\Domain\Identity\Exceptions;

use App\Models\Personnel;
use DomainException;

final class DuplicateEmployeeNumber extends DomainException
{
    public function __construct(public readonly Personnel $holder)
    {
        parent::__construct("Employee number {$holder->employee_no} is already registered to {$holder->full_name}.");
    }
}
```

Create `app/Domain/Identity/PersonnelRegistry.php`:

```php
<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Exceptions\DuplicateEmployeeNumber;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The people who receive spare parts (PRD FR-SPIMS-004). They never sign in. A person who leaves is
 * set to Inactive: their badge stops confirming withdrawals and their history is kept. Badges are
 * enrolled through PersonnelBadges, never here.
 */
final readonly class PersonnelRegistry
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws DuplicateEmployeeNumber
     */
    public function register(string $employeeNo, string $fullName, ?string $department, ?string $photoPath, User $actor): Personnel
    {
        $this->guardUniqueEmployeeNumber($employeeNo, null);

        return DB::transaction(function () use ($employeeNo, $fullName, $department, $photoPath, $actor): Personnel {
            $personnel = Personnel::query()->create([
                'employee_no' => $employeeNo,
                'full_name' => trim($fullName),
                'department' => self::blankToNull($department),
                'photo_path' => $photoPath,
                'is_active' => true,
            ]);

            $this->audit->record('created', $personnel, null, $this->snapshot($personnel), $actor);

            return $personnel;
        });
    }

    /**
     * @param  string|null  $newPhotoPath  null keeps the current photo
     *
     * @throws DuplicateEmployeeNumber
     */
    public function update(
        Personnel $personnel,
        string $employeeNo,
        string $fullName,
        ?string $department,
        ?string $newPhotoPath,
        bool $isActive,
        User $actor,
    ): void {
        $this->guardUniqueEmployeeNumber($employeeNo, $personnel);

        DB::transaction(function () use ($personnel, $employeeNo, $fullName, $department, $newPhotoPath, $isActive, $actor): void {
            $old = $this->snapshot($personnel);

            $personnel->forceFill([
                'employee_no' => $employeeNo,
                'full_name' => trim($fullName),
                'department' => self::blankToNull($department),
                'photo_path' => $newPhotoPath ?? $personnel->photo_path,
                'is_active' => $isActive,
            ])->save();

            $new = $this->snapshot($personnel);
            $changed = array_keys(array_filter($new, fn (mixed $value, string $key): bool => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record('updated', $personnel, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
            }
        });
    }

    /**
     * @throws DuplicateEmployeeNumber
     */
    private function guardUniqueEmployeeNumber(string $employeeNo, ?Personnel $except): void
    {
        $holder = Personnel::query()
            ->where('employee_no', Str::upper(trim($employeeNo)))
            ->when($except, fn ($query, Personnel $except) => $query->whereKeyNot($except->id))
            ->first();

        if ($holder !== null) {
            throw new DuplicateEmployeeNumber($holder);
        }
    }

    private static function blankToNull(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{employee_no: string, full_name: string, department: string|null, photo_path: string|null, is_active: bool}
     */
    private function snapshot(Personnel $personnel): array
    {
        return [
            'employee_no' => $personnel->employee_no,
            'full_name' => $personnel->full_name,
            'department' => $personnel->department,
            'photo_path' => $personnel->photo_path,
            'is_active' => $personnel->is_active,
        ];
    }
}
```

- [ ] **Step 5: Create the screens**

Create `app/Livewire/Personnel/Index.php`:

```php
<?php

namespace App\Livewire\Personnel;

use App\Enums\Permission;
use App\Models\Personnel;
use App\Support\SearchTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Personnel')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** active, inactive or all */
    #[Url]
    public string $status = 'active';

    public function mount(): void
    {
        $this->authorize(Permission::PersonnelManage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Personnel>
     */
    #[Computed]
    public function personnel(): LengthAwarePaginator
    {
        return Personnel::query()
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('employee_no', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('full_name', 'ilike', SearchTerm::contains($this->search))
                ->orWhere('department', 'ilike', SearchTerm::contains($this->search))))
            ->when($this->status !== 'all', fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('full_name')
            ->paginate(25);
    }
}
```

Create `resources/views/livewire/personnel/index.blade.php`:

```blade
<section class="w-full">
    <div class="mb-6 flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Personnel') }}</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('personnel.create')" wire:navigate>{{ __('Register personnel') }}</flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Employee number, name or department')" class="max-w-sm" />

        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->personnel">
        <flux:table.columns>
            <flux:table.column>{{ __('Employee number') }}</flux:table.column>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Badge') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->personnel as $person)
                <flux:table.row :key="$person->id">
                    <flux:table.cell>
                        <flux:link :href="route('personnel.edit', $person)" wire:navigate>{{ $person->employee_no }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $person->full_name }}</flux:table.cell>
                    <flux:table.cell>{{ $person->department }}</flux:table.cell>
                    <flux:table.cell>{{ $person->badge_uid_masked ?? __('Not enrolled') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$person->is_active ? 'green' : 'zinc'">
                            {{ $person->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

Create `app/Livewire/Personnel/Form.php`:

```php
<?php

namespace App\Livewire\Personnel;

use App\Domain\Identity\Exceptions\BadgeAlreadyEnrolled;
use App\Domain\Identity\Exceptions\DuplicateEmployeeNumber;
use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Domain\Identity\PersonnelBadges;
use App\Domain\Identity\PersonnelRegistry;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Personnel;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

#[Title('Personnel')]
class Form extends Component
{
    use ActsAsSignedInUser, WithFileUploads;

    /** The person being edited; null while registering one. */
    #[Locked]
    public ?int $personnelId = null;

    public string $employee_no = '';

    public string $full_name = '';

    public string $department = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public bool $is_active = true;

    /** What the badge reader typed into the scan field. */
    public string $badgeScan = '';

    /** The full badge identifier, once a System Administrator asks for it. */
    #[Locked]
    public ?string $revealedBadge = null;

    public function mount(?Personnel $personnel = null): void
    {
        $this->authorize(Permission::PersonnelManage);

        if ($personnel?->exists) {
            $this->personnelId = $personnel->id;
            $this->employee_no = $personnel->employee_no;
            $this->full_name = $personnel->full_name;
            $this->department = (string) $personnel->department;
            $this->is_active = $personnel->is_active;
        }
    }

    public function save(PersonnelRegistry $registry): void
    {
        $this->authorize(Permission::PersonnelManage);

        $this->validate([
            'employee_no' => ['required', 'string', 'max:50'],
            'full_name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_active' => ['boolean'],
        ]);

        $photoPath = null;

        if ($this->photo !== null) {
            $photoPath = $this->photo->store('personnel') ?: throw new RuntimeException('The photo could not be stored.');
        }

        try {
            if ($this->personnelId === null) {
                $personnel = $registry->register($this->employee_no, $this->full_name, $this->department, $photoPath, $this->actor());

                Flux::toast(variant: 'success', text: __('Registered. Scan the badge to enroll it.'));
                $this->redirectRoute('personnel.edit', ['personnel' => $personnel], navigate: true);

                return;
            }

            $registry->update(
                Personnel::query()->findOrFail($this->personnelId),
                $this->employee_no,
                $this->full_name,
                $this->department,
                $photoPath,
                $this->is_active,
                $this->actor(),
            );
        } catch (DuplicateEmployeeNumber $duplicate) {
            $this->addError('employee_no', $duplicate->getMessage());

            return;
        }

        $this->photo = null;

        Flux::toast(variant: 'success', text: __('Personnel saved.'));
    }

    /**
     * The reader types the badge UID into the scan field and presses Enter (design §10).
     */
    public function enrollBadge(PersonnelBadges $badges): void
    {
        $this->authorize(Permission::PersonnelManage);

        $personnel = Personnel::query()->findOrFail($this->personnelId);

        try {
            $badges->enroll($personnel, $this->badgeScan, $this->actor());
        } catch (InvalidBadgeInput|BadgeAlreadyEnrolled $refused) {
            $this->addError('badgeScan', $refused->getMessage());

            return;
        } catch (UniqueConstraintViolationException) {
            // Enrolled to someone else at the same moment (Plan 1 Task 6 review).
            $this->addError('badgeScan', __('This badge is already enrolled to another person. Scan it again to see who holds it.'));

            return;
        } finally {
            $this->badgeScan = '';
        }

        $this->revealedBadge = null;

        Flux::toast(variant: 'success', text: __('Badge enrolled.'));
    }

    public function revealBadge(): void
    {
        $this->authorize(Permission::BadgesViewFull);

        $this->revealedBadge = Personnel::query()->findOrFail($this->personnelId)->badge_uid_encrypted;
    }

    #[Computed]
    public function badgeMasked(): ?string
    {
        return $this->personnelId === null ? null : Personnel::query()->findOrFail($this->personnelId)->badge_uid_masked;
    }

    #[Computed]
    public function photoUrl(): ?string
    {
        $path = $this->personnelId === null ? null : Personnel::query()->findOrFail($this->personnelId)->photo_path;

        return $path === null ? null : route('media.show', ['path' => $path]);
    }
}
```

Create `resources/views/livewire/personnel/form.blade.php`:

```blade
<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $personnelId === null ? __('Register personnel') : __('Edit personnel') }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="employee_no" :label="__('Employee number')" required />
        <flux:input wire:model="full_name" :label="__('Full name')" required />
        <flux:input wire:model="department" :label="__('Department')" />

        <div class="flex items-center gap-4">
            @if ($this->photoUrl !== null)
                <img src="{{ $this->photoUrl }}" alt="{{ __('Photo of :name', ['name' => $full_name]) }}" class="size-20 rounded object-cover" />
            @endif
            <flux:input type="file" wire:model="photo" :label="__('Photo')" accept="image/jpeg,image/png,image/webp" />
        </div>

        @if ($personnelId !== null)
            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('An Inactive person\'s badge no longer confirms withdrawals. Their history is kept.')" />
        @endif

        <div class="flex gap-4">
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            <flux:button :href="route('personnel.index')" wire:navigate>{{ __('Back to personnel') }}</flux:button>
        </div>
    </form>

    @if ($personnelId !== null)
        <flux:card class="mt-10 space-y-4">
            <flux:heading size="lg">{{ __('Badge') }}</flux:heading>

            <flux:text>
                {{ $this->badgeMasked === null ? __('No badge enrolled.') : __('Enrolled badge: :badge', ['badge' => $this->badgeMasked]) }}
            </flux:text>

            @if ($revealedBadge !== null)
                <flux:text>{{ __('Full badge identifier: :badge', ['badge' => $revealedBadge]) }}</flux:text>
            @elseif ($this->badgeMasked !== null)
                @can(\App\Enums\Permission::BadgesViewFull)
                    <flux:button size="sm" wire:click="revealBadge">{{ __('Show full badge') }}</flux:button>
                @endcan
            @endif

            <flux:input
                wire:model="badgeScan"
                wire:keydown.enter.prevent="enrollBadge"
                :label="$this->badgeMasked === null ? __('Scan the badge to enroll it') : __('Scan a new badge to replace it')"
                autocomplete="off"
            />
            <flux:button wire:click="enrollBadge">{{ __('Enroll badge') }}</flux:button>
        </flux:card>
    @endif
</section>
```

- [ ] **Step 6: Add the routes and the menu item**

In `routes/spims.php`, add `use App\Livewire\Personnel;` to the imports and these routes inside the group:

```php
    Route::livewire('personnel', Personnel\Index::class)->name('personnel.index');
    Route::livewire('personnel/create', Personnel\Form::class)->name('personnel.create');
    Route::livewire('personnel/{personnel}/edit', Personnel\Form::class)->name('personnel.edit');
```

In `app/Support/Navigation.php`, add this row after the `Equipment` row:

```php
        ['group' => 'Master data', 'label' => 'Personnel', 'icon' => 'identification', 'route' => 'personnel.index', 'active' => 'personnel.*', 'permission' => Permission::PersonnelManage],
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Personnel tests/Feature/Identity
```

Expected: PASS. `PersonnelTest` contributes 14 tests, and Plan 1's `PersonnelBadgesTest` still passes.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Domain/Identity/PersonnelRegistry.php app/Domain/Identity/Exceptions/DuplicateEmployeeNumber.php app/Livewire/Personnel resources/views/livewire/personnel app/Models/Personnel.php routes/spims.php app/Support/Navigation.php tests/Feature/Personnel/PersonnelTest.php
git commit -m "Register personnel and enroll badges by scan, masked everywhere"
```

---

## Task 13: Screen access matrix, provisioning walkthrough and design amendments

Each task tested its own screens by role. This task adds two things:

- **One table of every Plan 2 screen against PRD §8**, in the shape of Plan 1's `RolePermissionMatrixTest`, so a reviewer checks access in one place. It also covers guests and a signed-in user with no role.
- **A walkthrough of the provisioning checklist** (design §3.6) through the screens alone. It covers steps 4, 5, 6 and 8. Step 7 arrives with the import in Plan 2b.

**Files:**
- Create: `tests/Feature/Access/ScreenAccessMatrixTest.php`, `tests/Feature/ProvisioningWalkthroughTest.php`
- Modify: `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`

**Interfaces:**
- Consumes: every route and component from Tasks 1–12, `spims:create-admin` (Plan 1).
- Produces: nothing new in code.

- [ ] **Step 1: Write the tests**

Create `tests/Feature/Access/ScreenAccessMatrixTest.php`:

```php
<?php

use App\Enums\Role;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StorageLocation;
use App\Models\User;

// Every Plan 2 screen and the roles PRD §8 lets open it. Holders read as in RolePermissionMatrixTest:
// C Custodian, S Supervisor, P Planner, A System Administrator. Route parameters are built lazily,
// inside each test's database transaction.
$screens = [
    'Users' => ['users.index', null, '---A'],
    'New user' => ['users.create', null, '---A'],
    'Install settings' => ['install-settings.edit', null, '---A'],
    'Withdrawal purposes' => ['withdrawal-purposes.index', null, '---A'],
    'Storage locations' => ['storage-locations.index', null, '-SPA'],
    'New storage location' => ['storage-locations.create', null, '-SPA'],
    'Edit storage location' => ['storage-locations.edit', fn () => ['location' => StorageLocation::factory()->create()], '-SPA'],
    'Equipment' => ['equipment.index', null, '-SPA'],
    'New equipment' => ['equipment.create', null, '-SPA'],
    'Edit equipment' => ['equipment.edit', fn () => ['equipment' => Equipment::factory()->create()], '-SPA'],
    'Spare parts' => ['parts.index', null, 'CSPA'],
    'Spare part' => ['parts.show', fn () => ['part' => Part::factory()->create()], 'CSPA'],
    'New spare part' => ['parts.create', null, '-SPA'],
    'Edit spare part' => ['parts.edit', fn () => ['part' => Part::factory()->create()], 'CSPA'],
    'Stock levels' => ['parts.stock-levels', fn () => ['part' => Part::factory()->create()], '--PA'],
    'Personnel' => ['personnel.index', null, '-S-A'],
    'Register personnel' => ['personnel.create', null, '-S-A'],
    'Edit personnel' => ['personnel.edit', fn () => ['personnel' => Personnel::factory()->create()], '-S-A'],
];

dataset('screens', function () use ($screens) {
    foreach ($screens as $screen => [$route, $parameters]) {
        yield $screen => [$route, $parameters];
    }
});

dataset('screens by role', function () use ($screens) {
    foreach ($screens as $screen => [$route, $parameters, $holders]) {
        foreach (roleMatrix($holders) as $label => [$role, $allowed]) {
            yield "{$screen}, {$label}" => [$route, $parameters, $role, $allowed];
        }
    }
});

it('opens each screen to exactly the roles PRD §8 allows', function (string $route, ?Closure $parameters, Role $role, bool $allowed) {
    $this->actingAs(userWithRoles($role))
        ->get(route($route, $parameters === null ? [] : $parameters()))
        ->assertStatus($allowed ? 200 : 403);
})->with('screens by role');

it('sends guests to sign in', function (string $route, ?Closure $parameters) {
    $this->get(route($route, $parameters === null ? [] : $parameters()))->assertRedirect(route('login'));
})->with('screens');

it('opens nothing to a signed-in user who holds no role', function (string $route, ?Closure $parameters) {
    $this->actingAs(User::factory()->create())
        ->get(route($route, $parameters === null ? [] : $parameters()))
        ->assertForbidden();
})->with('screens');
```

The closures are typed `?Closure` in each test's signature, so Pest passes them through rather than calling them while it builds the dataset.

Create `tests/Feature/ProvisioningWalkthroughTest.php`:

```php
<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Livewire\Admin\InstallSettingsForm;
use App\Livewire\MasterData\Equipment\Form as EquipmentForm;
use App\Livewire\MasterData\StorageLocations\Form as StorageLocationForm;
use App\Livewire\Parts\Form as PartForm;
use App\Livewire\Parts\StockLevelsForm;
use App\Livewire\Personnel\Form as PersonnelForm;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StorageLocation;
use App\Models\User;
use Livewire\Livewire;

it('provisions an install through the screens alone: design §3.6 steps 4, 5, 6 and 8', function () {
    // Step 4: the first System Administrator.
    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->assertSuccessful();
    $admin = User::query()->where('email', 'it.admin@microtech.test')->sole();

    // Step 5: company profile.
    Livewire::actingAs($admin)->test(InstallSettingsForm::class)
        ->set('settings.company.name', 'Microtech Industrial Solutions, Inc.')
        ->call('save')
        ->assertHasNoErrors();

    // Step 6: storage locations and the equipment register.
    Livewire::actingAs($admin)->test(StorageLocationForm::class)
        ->set('code', 'A-01')
        ->set('name', 'Aisle A, bin 1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(EquipmentForm::class)
        ->set('code', 'CNC-01')
        ->set('name', 'CNC lathe 1')
        ->call('save')
        ->assertHasNoErrors();

    // Spare parts one at a time, until the import in Plan 2b.
    Livewire::actingAs($admin)->test(PartForm::class)
        ->set('part_number', 'BRG-6204')
        ->set('description', 'Deep groove ball bearing')
        ->set('unit_of_measure', 'pcs')
        ->set('default_location_id', (string) StorageLocation::query()->sole()->id)
        ->set('equipment_ids', [(string) Equipment::query()->sole()->id])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(StockLevelsForm::class, ['part' => Part::query()->sole()])
        ->set('reorder_point', '10')
        ->set('min_level', '4')
        ->set('critical_level', '2')
        ->set('criticality', 'critical')
        ->call('save')
        ->assertHasNoErrors();

    // Step 8: personnel and badges.
    Livewire::actingAs($admin)->test(PersonnelForm::class)
        ->set('employee_no', 'EMP-00042')
        ->set('full_name', 'Jose Rizal')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(PersonnelForm::class, ['personnel' => Personnel::query()->sole()])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasNoErrors();

    $checklist = collect(Livewire::actingAs($admin)->test(InstallSettingsForm::class)->instance()->checklist())
        ->pluck('done', 'label');

    expect(app(InstallSettings::class)->isReadyForStockTransactions())->toBeTrue()
        ->and($checklist->except('Opening balances')->every(fn (bool $done): bool => $done))->toBeTrue()
        ->and($checklist['Opening balances'])->toBeFalse()
        ->and(app(PersonnelBadges::class)->identify('A12345678')?->employee_no)->toBe('EMP-00042')
        ->and(AuditLog::query()->where('user_id', $admin->id)->distinct()->pluck('auditable_type')->sort()->values()->all())
        ->toBe(['equipment', 'part', 'personnel', 'setting', 'storage_location']);
});
```

`spims:create-admin` runs with nobody signed in, so its own `created` entry has no actor and is not in the list.

- [ ] **Step 2: Run the tests**

```bash
php artisan test tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/ProvisioningWalkthroughTest.php
```

Expected: PASS: 72 role cases, 18 guest cases, 18 no-role cases and 1 walkthrough, 109 tests in all. These tests pass on the first run, because Tasks 1–12 built what they check. Prove each one can fail: break something they depend on, watch the test fail, then undo the change.

- In `app/Livewire/Parts/StockLevelsForm.php`, change `mount()`'s ability to `'update'`. The `Stock levels, Inventory Custodian` case fails.
- Delete `$this->authorize(Permission::PersonnelManage);` from `app/Livewire/Personnel/Form.php`'s `mount()`. The no-role cases fail.

- [ ] **Step 3: Amend the design doc**

In `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`:

1. In §3.5, add this row to the table after the `Role in a withdrawal` row:

```markdown
| When they leave | Account deactivated (`users.is_active`); cannot sign in, history kept | Record set to Inactive; badge stops identifying them, history kept |
```

2. In §4.3, add these lines after `create index parts_location_idx             on parts (default_location_id);`:

```sql
-- Exact and leading part-number search (§12: under 150 ms at p95). The unique index on
-- lower(part_number) follows the database collation, which cannot serve LIKE 'abc%'.
create index parts_part_number_prefix_idx   on parts (lower(part_number) text_pattern_ops);
```

- [ ] **Step 4: Run the full check and commit**

```bash
composer test
git add tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/ProvisioningWalkthroughTest.php docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md
git commit -m "Check every Plan 2 screen against PRD §8 and walk through provisioning"
```

---

## Plan 2 Is Done When

- [ ] `composer test` is green, with Pint, PHPStan level 7 and the whole Pest suite.
- [ ] A fresh install works on a scratch database. Run `php artisan migrate:fresh --seed`, then `php artisan spims:create-admin you@microtech.test "Your Name"`. Sign in at `/login` and, in a browser:
  - Set the company name. The setup prompt disappears.
  - Create a storage location, a piece of equipment and a spare part linked to both.
  - Set the part's stock levels.
  - Register a person and enroll a badge by typing its UID into the scan field and pressing Enter.
  - The setup checklist then shows every item done except opening balances.
- [ ] With `APP_INSTALL_TYPE=demo`, the Demo label shows on the sign-in page and every screen.
- [ ] Each role sees only its own menu items:
  - Custodian: Dashboard and Spare parts.
  - Planner: adds Storage locations and Equipment.
  - Supervisor: adds Personnel.
  - Administrator: everything.

After the merge, update Plan 2's row in the roadmap, then write Plan 2b (the import).

## Notes for Later Plans

- **Plan 2b (import):**
  - Parts go through `PartRecords` for audit. Opening balances go through `StockLedger` as `opening` transactions.
  - Location and equipment codes match after trimming and uppercasing, the same normalisation as the mutators.
  - Opening balances need `company.name` set first (`InstallNotConfigured`).
  - The setup checklist's `Opening balances` item turns done on its own.
- **Plan 3 (station):**
  - Reuse `PartSearch::matching()` for the part lookup.
  - Add `PartPolicy::createOnReceipt()` on `Permission::PartsCreateOnReceipt` for creating a part while receiving it.
  - Offer only Active equipment and purposes.
  - `Navigation::ITEMS` gains the station rows.
- **Plan 5 (alerts):**
  - Re-evaluate alerts for a part after `StockLevels::update()`.
  - Add the per-alert-type instant email setting (`notifications.instant_alert_types`) to the Install Settings screen. Its rules already exist in `SettingValues`.
- **Plan 6 (reports):** `AuditValue::display()` and the part change history are the model for the audit views. PRD §8 limits planners to part and stock-level changes (`PartAuditView`).
- **Plan 7 (go-live):**
  - `media.show` streams from whichever disk `FILESYSTEM_DISK` names, S3 included. Add the backup item to the setup checklist.
  - Set `APP_INSTALL_TYPE` per install.
