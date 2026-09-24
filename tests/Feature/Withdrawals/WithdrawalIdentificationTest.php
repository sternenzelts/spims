<?php

use App\Domain\Identity\BadgeIdentity;
use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\IdentificationMethod;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;
use App\Models\WithdrawalPurpose;
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

/**
 * A draft with its equipment and purpose set, so only the identification is left to check.
 */
function readyExceptIdentification(WithdrawalService $service, $draft): void
{
    $service->setContext(
        $draft,
        Equipment::factory()->create(),
        WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail(),
        null,
    );
}

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

it('clears an identification that its copy of the draft has not seen', function () {
    $stale = $this->draft->fresh();
    $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian);

    $this->identification->clear($stale);

    expect($this->draft->fresh())->identified_at->toBeNull()->personnel_id->toBeNull()
        ->and($stale->isDirty())->toBeFalse();
});

it('refuses to confirm a withdrawal that has not been identified', function () {
    readyExceptIdentification($this->service, $this->draft);

    expect(fn () => $this->service->guardReadyToConfirm($this->draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'scan');
});

it('refuses to confirm a withdrawal whose identification has expired', function () {
    readyExceptIdentification($this->service, $this->draft);
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

it('clears a scan taken in another tab when this tab changes the cart', function () {
    // This tab loaded the draft before the other tab's scan, so its copy reads as unidentified.
    $stale = $this->draft->fresh();
    $this->identification->identify($this->draft, 'A1234BCDE', $this->custodian);

    $this->service->addLine($stale, Part::factory()->create(), '1');

    expect($this->draft->fresh())->identified_at->toBeNull()->personnel_id->toBeNull();
});
