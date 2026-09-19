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
