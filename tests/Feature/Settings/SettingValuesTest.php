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
