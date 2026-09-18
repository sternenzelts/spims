<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stock cache and row-lock target for one part (design §4.1). A database trigger creates the
 * row when the part is inserted. Only App\Domain\Ledger\StockLedger changes on_hand, which must
 * always equal the sum of the part's stock movements.
 *
 * @property int $part_id
 * @property string $on_hand
 * @property CarbonImmutable $updated_at
 * @property-read Part $part
 */
#[Table(name: 'part_stock', key: 'part_id', incrementing: false)]
class PartStock extends Model
{
    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Part, $this>
     */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
