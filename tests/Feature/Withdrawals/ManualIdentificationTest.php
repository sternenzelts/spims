<?php

use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\IdentificationMethod;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Personnel;

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
