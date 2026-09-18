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
