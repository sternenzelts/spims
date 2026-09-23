<?php

namespace App\Models;

use App\Enums\IdentificationMethod;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Database\Factories\StockTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A withdrawal, replenishment, adjustment or opening balance. Status, number and recorded_at are
 * lifecycle fields set only by App\Domain\Ledger\StockLedger, so they are not fillable.
 *
 * @property int $id
 * @property string|null $transaction_no
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property int $custodian_user_id
 * @property int|null $personnel_id
 * @property int|null $equipment_id
 * @property int|null $purpose_id
 * @property string|null $supplier
 * @property string|null $reference_no
 * @property ReasonCode|null $reason_code
 * @property string|null $remarks
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property bool $is_backdated
 * @property string|null $backdate_reason
 * @property int|null $reverses_transaction_id
 * @property int|null $reversed_by_user_id
 * @property string|null $reversal_reason
 * @property IdentificationMethod|null $identification_method
 * @property int|null $identification_authorised_by_user_id
 * @property string|null $identification_reason
 * @property string|null $paper_form_no
 * @property CarbonImmutable|null $identified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $custodian
 * @property-read Personnel|null $personnel
 * @property-read Equipment|null $equipment
 * @property-read WithdrawalPurpose|null $purpose
 * @property-read Collection<int, StockTransactionLine> $lines
 * @property-read Collection<int, StockMovement> $movements
 * @property-read Collection<int, WithdrawalOverride> $overrides
 */
#[Fillable([
    'type', 'custodian_user_id', 'personnel_id', 'equipment_id', 'purpose_id', 'supplier', 'reference_no',
    'reason_code', 'remarks', 'occurred_at', 'is_backdated', 'backdate_reason', 'identification_method',
    'identification_authorised_by_user_id', 'identification_reason', 'paper_form_no',
])]
class StockTransaction extends Model
{
    /** @use HasFactory<StockTransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'reason_code' => ReasonCode::class,
            'identification_method' => IdentificationMethod::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
            'identified_at' => 'datetime',
            'is_backdated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    /**
     * @return BelongsTo<Personnel, $this>
     */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<WithdrawalPurpose, $this>
     */
    public function purpose(): BelongsTo
    {
        return $this->belongsTo(WithdrawalPurpose::class, 'purpose_id');
    }

    /**
     * @return HasMany<StockTransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransactionLine::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Supervisor authorisations to issue more than the recorded balance (PRD FR-SPIMS-008).
     *
     * @return HasMany<WithdrawalOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(WithdrawalOverride::class);
    }
}
