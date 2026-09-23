<?php

namespace App\Providers;

use App\Domain\Identity\BadgeReader;
use App\Domain\Identity\KeyboardWedgeBadgeReader;
use App\Domain\Settings\InstallSettings;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\Setting;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\WithdrawalOverride;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, not singleton: saved settings are cached per request or queued job, never across them.
        $this->app->scoped(InstallSettings::class);
        $this->app->bind(BadgeReader::class, KeyboardWedgeBadgeReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Plain timestamptz (microseconds), as the design specifies. Laravel's default of 0 makes
        // Postgres round database-filled times to the second, which can cross a reporting day.
        Builder::defaultTimePrecision(null);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Stable type names in audit_logs and role assignments, so renaming a class never orphans the
     * audit trail. Every model that is audited or holds roles must be listed here.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'audit_log' => AuditLog::class,
            'setting' => Setting::class,
            'withdrawal_purpose' => WithdrawalPurpose::class,
            'storage_location' => StorageLocation::class,
            'equipment' => Equipment::class,
            'part' => Part::class,
            'personnel' => Personnel::class,
            'stock_transaction' => StockTransaction::class,
            'import_batch' => ImportBatch::class,
            'withdrawal_override' => WithdrawalOverride::class,
        ]);
    }
}
