<?php

namespace App\Models;

use App\Enums\ReasonCode;
use Carbon\CarbonImmutable;
use Database\Factories\WithdrawalOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supervisor authorisation to issue more of a part than the recorded balance holds
 * (PRD FR-SPIMS-008, design §5.3). It is attached to a Draft withdrawal; its outcome — the
 * adjustment it posted, or the time it was discarded — is written at commit by
 * App\Domain\Ledger\StockLedger.
 *
 * @property int $id
 * @property int $stock_transaction_id
 * @property int $part_id
 * @property string $verified_qty
 * @property string $recorded_on_hand
 * @property ReasonCode $reason_code
 * @property int $authorised_by_user_id
 * @property CarbonImmutable $authorised_at
 * @property int|null $adjustment_transaction_id
 * @property CarbonImmutable|null $discarded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StockTransaction $transaction
 * @property-read Part $part
 * @property-read User $authorisedBy
 * @property-read StockTransaction|null $adjustment
 */
#[Fillable([
    'part_id', 'verified_qty', 'recorded_on_hand', 'reason_code', 'authorised_by_user_id', 'authorised_at',
])]
class WithdrawalOverride extends Model
{
    /** @use HasFactory<WithdrawalOverrideFactory> */
    use HasFactory;

    /**
     * The only reasons FR-SPIMS-008 offers, and the only two the table's CHECK allows.
     *
     * @var list<ReasonCode>
     */
    public const array REASONS = [ReasonCode::FoundStock, ReasonCode::UnrecordedReceipt];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_qty' => 'decimal:3',
            'recorded_on_hand' => 'decimal:3',
            'reason_code' => ReasonCode::class,
            'authorised_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /**
     * Still waiting for the commit that posts or discards it.
     */
    public function isPending(): bool
    {
        return $this->adjustment_transaction_id === null && $this->discarded_at === null;
    }

    /**
     * @return BelongsTo<StockTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    /**
     * @return BelongsTo<StockTransaction, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'adjustment_transaction_id');
    }
}
