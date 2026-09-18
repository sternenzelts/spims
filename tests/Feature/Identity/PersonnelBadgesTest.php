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

it('records nothing when a person scans the badge they already hold', function () {
    $person = Personnel::factory()->create();

    $this->badges->enroll($person, 'A12345678', $this->admin);
    $this->badges->enroll($person->fresh(), 'A12345678', $this->admin);

    expect(AuditLog::query()->pluck('event')->all())->toBe(['badge_enrolled']);
});

it('never writes a badge UID into the audit trail', function () {
    $this->badges->enroll(Personnel::factory()->create(), 'A12345678', $this->admin);

    expect(AuditLog::query()->get()->toJson())->not->toContain('A12345678');
});
