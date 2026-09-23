<?php

use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\ReasonCode;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Personnel;

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
