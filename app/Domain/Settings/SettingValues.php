<?php

namespace App\Domain\Settings;

use App\Support\Text;
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
            'company.name', 'company.address', 'company.logo_path' => Text::nullIfBlank((string) $value),
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
}
