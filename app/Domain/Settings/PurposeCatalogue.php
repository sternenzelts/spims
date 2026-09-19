<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\Exceptions\PurposeChangeRefused;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The withdrawal purposes offered at the station (PRD FR-SPIMS-018). A purpose a transaction has
 * used is renamed or set to Inactive, never deleted, and at least one purpose always stays Active.
 */
final readonly class PurposeCatalogue
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws PurposeChangeRefused
     */
    public function add(string $name, bool $requiresRemarks, User $actor): WithdrawalPurpose
    {
        $name = trim($name);
        $this->guardUniqueName($name, null);

        return DB::transaction(function () use ($name, $requiresRemarks, $actor): WithdrawalPurpose {
            $purpose = WithdrawalPurpose::query()->create([
                'code' => $this->codeFor($name),
                'name' => $name,
                'requires_remarks' => $requiresRemarks,
                'sort_order' => (int) WithdrawalPurpose::query()->max('sort_order') + 10,
                'is_active' => true,
            ]);

            $this->audit->record('created', $purpose, null, $this->snapshot($purpose), $actor);

            return $purpose;
        });
    }

    /**
     * @throws PurposeChangeRefused
     */
    public function update(WithdrawalPurpose $purpose, string $name, bool $requiresRemarks, bool $isActive, User $actor): void
    {
        $name = trim($name);
        $this->guardUniqueName($name, $purpose);

        DB::transaction(function () use ($purpose, $name, $requiresRemarks, $isActive, $actor): void {
            if ($purpose->is_active && ! $isActive) {
                $this->guardAnotherActive($purpose);
            }

            $old = $this->snapshot($purpose);
            $purpose->forceFill(['name' => $name, 'requires_remarks' => $requiresRemarks, 'is_active' => $isActive])->save();
            $new = $this->snapshot($purpose);

            $this->audit->recordChanges($purpose, $old, $new, $actor);
        });
    }

    /**
     * Delete a purpose no transaction has used, such as one added by mistake.
     *
     * @throws PurposeChangeRefused
     */
    public function remove(WithdrawalPurpose $purpose, User $actor): void
    {
        DB::transaction(function () use ($purpose, $actor): void {
            if ($this->isUsed($purpose)) {
                throw PurposeChangeRefused::used($purpose);
            }

            if ($purpose->is_active) {
                $this->guardAnotherActive($purpose);
            }

            $old = $this->snapshot($purpose);
            $purpose->delete();

            $this->audit->record('deleted', $purpose, $old, null, $actor);
        });
    }

    /**
     * @throws PurposeChangeRefused
     */
    private function guardUniqueName(string $name, ?WithdrawalPurpose $except): void
    {
        $taken = WithdrawalPurpose::query()
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->when($except, fn ($query, WithdrawalPurpose $except) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw PurposeChangeRefused::duplicateName($name);
        }
    }

    /**
     * The Active purposes are locked first, so two administrators cannot each deactivate one of the
     * last two.
     *
     * @throws PurposeChangeRefused
     */
    private function guardAnotherActive(WithdrawalPurpose $purpose): void
    {
        $others = WithdrawalPurpose::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->filter(fn (mixed $id): bool => (int) $id !== $purpose->id);

        if ($others->isEmpty()) {
            throw PurposeChangeRefused::lastActive();
        }
    }

    private function isUsed(WithdrawalPurpose $purpose): bool
    {
        return StockTransaction::query()->where('purpose_id', $purpose->id)->exists()
            || StockTransactionLine::query()->where('purpose_id', $purpose->id)->exists();
    }

    private function codeFor(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'purpose';
        $code = $base;

        for ($suffix = 2; WithdrawalPurpose::query()->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }

    /**
     * @return array{name: string, requires_remarks: bool, is_active: bool}
     */
    private function snapshot(WithdrawalPurpose $purpose): array
    {
        return [
            'name' => $purpose->name,
            'requires_remarks' => $purpose->requires_remarks,
            'is_active' => $purpose->is_active,
        ];
    }
}
