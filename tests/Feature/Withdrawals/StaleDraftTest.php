<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Ledger\StockLedger;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\IdentificationMethod;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Decision 5 lets a second tab resume the same draft, so a request can hold a copy of a draft that
 * another request has since completed. Every change re-reads the draft under its row lock and
 * refuses unless it is still a Draft there, so nothing lands on a completed withdrawal.
 */

beforeEach(function () {
    configureInstall();
    $this->custodian = userWithRoles(Role::Custodian);
    $this->supervisor = userWithRoles(Role::Supervisor);
    $this->service = app(WithdrawalService::class);
    $this->identification = app(WithdrawalIdentification::class);
    // Exactly the line, so the completed withdrawal leaves the part short and an override would
    // otherwise be accepted.
    $this->part = partWithStock('4');
    $this->person = Personnel::factory()->create();
    app(PersonnelBadges::class)->enroll($this->person, 'A1234BCDE', $this->custodian);

    $draft = $this->service->openDraft($this->custodian);
    $this->line = $this->service->addLine($draft, $this->part, '4');
    $this->service->setContext(
        $draft,
        Equipment::factory()->create(),
        WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail(),
        null,
    );
    $this->identification->identify($draft->fresh(), 'A1234BCDE', $this->custodian);

    // The second tab's copy, taken while the draft was still a Draft.
    $this->stale = $draft->fresh();
    $this->completed = app(StockLedger::class)->completeWithOverrides($draft->fresh(), $this->custodian);
});

afterEach(fn () => RateLimiter::clear('badge-scan:'.$this->custodian->id));

it('refuses every change to a withdrawal another request has completed', function (Closure $change) {
    expect(fn () => $change($this->stale, $this->line))->toThrow(WithdrawalRefused::class, 'completed');

    $completed = $this->completed->fresh();

    expect($completed->status)->toBe(TransactionStatus::Completed)
        ->and($completed->personnel_id)->toBe($this->person->id)
        ->and($completed->identification_method)->toBe(IdentificationMethod::BadgeScan)
        ->and($completed->identified_at)->not->toBeNull()
        ->and($completed->equipment_id)->not->toBeNull()
        ->and($completed->lines()->count())->toBe(1)
        ->and($completed->lines()->sole()->quantity)->toBe('4.000')
        ->and($completed->lines()->sole()->equipment_id)->toBeNull()
        ->and($completed->overrides()->count())->toBe(0);
})->with([
    'adding a part' => [fn (StockTransaction $d) => test()->service->addLine($d, Part::factory()->create(), '1')],
    'adding to a line' => [fn (StockTransaction $d) => test()->service->addLine($d, test()->part, '1')],
    'changing a quantity' => [fn (StockTransaction $d, StockTransactionLine $l) => test()->service->setLineQuantity($d, $l, '9')],
    'removing a line' => [fn (StockTransaction $d, StockTransactionLine $l) => test()->service->removeLine($d, $l)],
    'setting the context' => [fn (StockTransaction $d) => test()->service->setContext($d, null, null, 'note')],
    'setting a line context' => [fn (StockTransaction $d, StockTransactionLine $l) => test()->service->setLineContext($d, $l, Equipment::factory()->create(), null)],
    'cancelling' => [fn (StockTransaction $d) => test()->service->cancel($d, test()->custodian)],
    'authorising an override' => [fn (StockTransaction $d) => test()->service->authoriseOverride($d, test()->part, '4', ReasonCode::FoundStock, test()->supervisor)],
    'scanning a badge' => [fn (StockTransaction $d) => test()->identification->identify($d, 'A1234BCDE', test()->custodian)],
    'identifying by hand' => [fn (StockTransaction $d) => test()->identification->identifyManually(
        $d, Personnel::factory()->create(), test()->supervisor, 'Reader failed',
    )],
    'clearing the identification' => [fn (StockTransaction $d) => test()->identification->clear($d)],
]);

it('keeps the cancelled status of a draft another request cancelled', function () {
    $draft = $this->service->openDraft($this->custodian);
    $stale = $draft->fresh();
    $this->service->cancel($draft, $this->custodian);

    expect(fn () => $this->service->addLine($stale, Part::factory()->create(), '1'))
        ->toThrow(WithdrawalRefused::class, 'cancelled');

    expect($draft->fresh()->lines()->count())->toBe(0);
});

it('brings the caller\'s copy of the draft in line with what was written', function () {
    $draft = $this->service->openDraft(User::factory()->create());

    $this->service->setContext($draft, null, null, 'Bearing seized');

    expect($draft->remarks)->toBe('Bearing seized')
        ->and($draft->isDirty())->toBeFalse();
});
