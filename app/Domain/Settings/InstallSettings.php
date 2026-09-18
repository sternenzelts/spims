<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogger;
use App\Enums\TransactionType;
use App\Models\Setting;
use App\Models\User;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Per-install configuration (design §3.6 rule 1, PRD FR-SPIMS-018). A key with no saved row uses
 * its default, so a fresh install works before anyone opens Install Settings. The install type
 * (demo or production) is deliberately not a setting: it is environment configuration.
 */
final class InstallSettings
{
    public const array DEFAULTS = [
        'company.name' => null,
        'company.address' => null,
        'company.logo_path' => null,
        'regional.timezone' => 'Asia/Manila',
        'regional.currency' => 'PHP',
        'numbering.prefixes' => [
            'withdrawal' => 'WD',
            'replenishment' => 'RP',
            'adjustment' => 'AJ',
            'opening' => 'OP',
        ],
        'analysis.window_months' => 6,
        'movement_class.dead_stock_days' => 365,
        'movement_class.slow_moving_days' => 90,
        'movement_class.fast_moving_top_percent' => 20,
        'movement_class.minimum_history_days' => 90,
        'alerts.high_consumption.multiplier' => 2.0,
        'alerts.high_consumption.minimum_events' => 3,
        'alerts.high_consumption.minimum_baseline_days' => 60,
        'withdrawal.identification_timeout_seconds' => 120,
        'backdating.limit_days' => 7,
        'counting.default_tolerance_pct' => 0,
        'notifications.digest_recipients' => [],
        'notifications.instant_alert_types' => [],
        'prediction.default_lead_time_days' => 14,
        'prediction.warning_buffer_days' => 7,
    ];

    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key): mixed
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw new InvalidArgumentException("Unknown install setting [{$key}].");
        }

        $saved = $this->saved();

        return array_key_exists($key, $saved) ? $saved[$key] : self::DEFAULTS[$key];
    }

    /**
     * Save a setting and audit the old and new values. Saving null removes the saved value, so the
     * default applies again.
     */
    public function set(string $key, mixed $value, User $actor): void
    {
        $old = $this->get($key);

        if ($value === null) {
            Setting::query()->whereKey($key)->delete();
        } else {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by_user_id' => $actor->id]);
        }

        $this->saved = null;
        $new = $this->get($key);

        if ($old !== $new) {
            $this->audit->record('updated', new Setting(['key' => $key]), ['value' => $old], ['value' => $new], $actor);
        }
    }

    public function companyName(): ?string
    {
        $name = $this->get('company.name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * PRD FR-SPIMS-018: an install records no stock transactions until its company name is set.
     */
    public function isReadyForStockTransactions(): bool
    {
        return $this->companyName() !== null;
    }

    public function timezone(): string
    {
        $timezone = $this->get('regional.timezone');

        if (! is_string($timezone)) {
            throw new UnexpectedValueException('Install setting [regional.timezone] must be a string.');
        }

        return $timezone;
    }

    public function transactionPrefix(TransactionType $type): string
    {
        $prefixes = $this->get('numbering.prefixes');
        $prefix = is_array($prefixes) ? ($prefixes[$type->value] ?? null) : null;

        if (! is_string($prefix)) {
            throw new UnexpectedValueException("Install setting [numbering.prefixes] has no prefix for [{$type->value}].");
        }

        return $prefix;
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        return $this->saved ??= Setting::query()->pluck('value', 'key')->all();
    }
}
