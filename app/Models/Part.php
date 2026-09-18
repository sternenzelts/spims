<?php

namespace App\Models;

use App\Enums\Criticality;
use Carbon\CarbonImmutable;
use Database\Factories\PartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $part_number
 * @property string $description
 * @property string $unit_of_measure
 * @property int|null $default_location_id
 * @property string $min_level
 * @property string|null $max_level
 * @property string $reorder_point
 * @property string $critical_level
 * @property Criticality $criticality
 * @property int|null $lead_time_days
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StorageLocation|null $defaultLocation
 * @property-read PartStock|null $stock
 * @property-read Collection<int, Equipment> $equipment
 */
#[Fillable([
    'part_number', 'description', 'unit_of_measure', 'default_location_id', 'min_level', 'max_level',
    'reorder_point', 'critical_level', 'criticality', 'lead_time_days', 'is_active',
])]
class Part extends Model
{
    /** @use HasFactory<PartFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_level' => 'decimal:3',
            'max_level' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'critical_level' => 'decimal:3',
            'criticality' => Criticality::class,
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'default_location_id');
    }

    /**
     * @return HasOne<PartStock, $this>
     */
    public function stock(): HasOne
    {
        return $this->hasOne(PartStock::class);
    }

    /**
     * @return BelongsToMany<Equipment, $this>
     */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'part_equipment')->withPivot('notes');
    }
}
