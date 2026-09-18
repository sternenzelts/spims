<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_transaction_id
 * @property int $line_no
 * @property int $part_id
 * @property string $quantity
 * @property int $direction
 * @property int|null $equipment_id
 * @property int|null $purpose_id
 * @property string|null $unit_cost
 * @property string|null $remarks
 * @property-read StockTransaction $transaction
 * @property-read Part $part
 */
#[WithoutTimestamps]
#[Fillable(['line_no', 'part_id', 'quantity', 'direction', 'equipment_id', 'purpose_id', 'unit_cost', 'remarks'])]
class StockTransactionLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'direction' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * The quantity with its stock effect applied: negative removes stock, positive adds it.
     */
    public function signedQuantity(): BigDecimal
    {
        return BigDecimal::of($this->quantity)->multipliedBy($this->direction);
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
}
