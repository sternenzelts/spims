<?php

use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StorageLocation;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('gives every new part a stock row at zero', function () {
    $part = Part::factory()->create();

    expect(PartStock::query()->find($part->id)?->on_hand)->toBe('0.000');
});

it('treats part numbers as unique regardless of letter case', function () {
    Part::factory()->create(['part_number' => 'BRG-6205-2RS']);

    expect(inSavepoint(fn () => Part::factory()->create(['part_number' => 'brg-6205-2rs'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rejects stock levels in the wrong order', function (array $levels, string $constraint) {
    expect(inSavepoint(fn () => Part::factory()->create($levels)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'critical above minimum' => [['min_level' => '5', 'critical_level' => '6'], 'parts_levels_ordered'],
    'maximum below minimum' => [['min_level' => '5', 'max_level' => '4'], 'parts_levels_ordered'],
    'negative reorder point' => [['reorder_point' => '-1'], 'parts_levels_non_negative'],
]);

it('rejects an unknown criticality', function () {
    expect(inSavepoint(fn () => DB::table('parts')->insert([
        'part_number' => 'SEAL-001',
        'description' => 'Oil seal',
        'criticality' => 'urgent',
    ])))->toThrow(QueryException::class, 'parts_criticality_check');
});

it('never lets stock go negative', function () {
    $part = Part::factory()->create();

    expect(inSavepoint(fn () => DB::table('part_stock')->where('part_id', $part->id)->update(['on_hand' => '-1'])))
        ->toThrow(QueryException::class, 'part_stock_non_negative');
});

it('keeps a part in its storage location and links it to every machine it fits', function () {
    $rack = StorageLocation::factory()->create();
    $part = Part::factory()->create(['default_location_id' => $rack->id]);
    $conveyor = Equipment::factory()->create();
    $pump = Equipment::factory()->create();

    // Every id carries the same pivot keys: attach() inserts all rows in one statement.
    $part->equipment()->attach([$conveyor->id => ['notes' => 'Drive end'], $pump->id => ['notes' => null]]);

    expect($part->defaultLocation?->is($rack))->toBeTrue()
        ->and($part->equipment()->orderBy('equipment.id')->pluck('equipment.id')->all())->toBe([$conveyor->id, $pump->id])
        ->and($conveyor->parts()->sole()->pivot->notes)->toBe('Drive end');
});
