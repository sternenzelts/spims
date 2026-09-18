<?php

namespace App\Models;

use App\Enums\MovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the stock ledger (design §4.1, §4.5). Append-only: a database trigger rejects every
 * UPDATE and DELETE. quantity is signed, and balance_after is the running sum of quantity for the
 * part in id order. Written only by App\Domain\Ledger\StockLedger, so nothing is fillable.
 *
 * @property int $id
 * @property int $part_id
 * @property int $stock_transaction_id
 * @property int|null $stock_transaction_line_id
 * @property MovementType $movement_type
 * @property string $quantity
 * @property string $balance_after
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int|null $personnel_id
 * @property int|null $equipment_id
 * @property int|null $purpose_id
 * @property int $custodian_user_id
 * @property CarbonImmutable $created_at
 * @property-read Part $part
 * @property-read StockTransaction $transaction
 */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * @return BelongsTo<StockTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
