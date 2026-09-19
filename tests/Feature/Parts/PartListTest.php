<?php

use App\Domain\Parts\PartSearch;
use App\Enums\Criticality;
use App\Enums\Role;
use App\Livewire\Parts\Index;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lets every role search spare parts and see stock balances', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $part = Part::factory()->create();

    $this->actingAs($user)->get(route('parts.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('parts.show', $part))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Spare parts', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('CSPA'));

it('finds parts by exact or leading part number ignoring case, the exact match first', function () {
    Part::factory()->create(['part_number' => 'BRG-6204-2RS', 'description' => 'Deep groove ball bearing']);
    Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Ball bearing, open']);
    Part::factory()->create(['part_number' => 'XBRG-1', 'description' => 'Bracket']);

    expect(app(PartSearch::class)->matching('brg-6204')->pluck('part_number')->all())
        ->toBe(['BRG-6204', 'BRG-6204-2RS']);
});

it('finds parts by words anywhere in the description', function () {
    Part::factory()->create(['part_number' => 'BRG-6204-2RS', 'description' => 'Deep groove ball bearing']);
    Part::factory()->create(['part_number' => 'VB-A42', 'description' => 'V-belt A42']);

    expect(app(PartSearch::class)->matching('GROOVE BALL')->pluck('part_number')->all())->toBe(['BRG-6204-2RS']);
});

it('takes LIKE wildcards in a search literally', function () {
    Part::factory()->create(['part_number' => 'A_1', 'description' => 'Adapter']);
    Part::factory()->create(['part_number' => 'AB1', 'description' => 'Coolant, 50% glycol']);
    Part::factory()->create(['part_number' => 'AC1', 'description' => 'Coolant, 500 ml']);

    expect(app(PartSearch::class)->matching('a_')->pluck('part_number')->all())->toBe(['A_1'])
        ->and(app(PartSearch::class)->matching('50%')->pluck('part_number')->all())->toBe(['AB1']);
});

it('lets the database answer a search from indexes', function () {
    Part::factory()->count(20)->create();

    // With sequential and plain index scans ruled out, only an indexed plan avoids a Seq Scan.
    DB::statement('set local enable_seqscan = off');
    DB::statement('set local enable_indexscan = off');

    $search = app(PartSearch::class)->matching('brg');
    $plan = DB::selectOne('explain (format json) '.$search->toSql(), $search->getBindings())->{'QUERY PLAN'};

    expect($plan)->not->toContain('Seq Scan')->toContain('parts_description_trgm');
});

it('lists Active parts with their location and balance, filtered by criticality and location', function () {
    configureInstall();
    $rack = StorageLocation::factory()->create(['code' => 'A-01']);
    partWithStock('12.5', [
        'part_number' => 'OIL-68',
        'unit_of_measure' => 'L',
        'default_location_id' => $rack->id,
        'criticality' => Criticality::Critical,
    ]);
    Part::factory()->create(['part_number' => 'SEAL-9', 'criticality' => Criticality::Normal]);
    Part::factory()->create(['part_number' => 'OLD-1', 'is_active' => false]);

    Livewire::actingAs(userWithRoles(Role::Custodian))->test(Index::class)
        ->assertSee('OIL-68')
        ->assertSee('12.5')
        ->assertSee('SEAL-9')
        ->assertDontSee('OLD-1')
        ->set('criticality', 'critical')
        ->assertSee('OIL-68')
        ->assertDontSee('SEAL-9')
        ->set('criticality', '')
        ->set('location', (string) $rack->id)
        ->assertSee('OIL-68')
        ->assertDontSee('SEAL-9')
        ->set('location', '')
        ->set('status', 'inactive')
        ->assertSee('OLD-1')
        ->assertDontSee('OIL-68');
});

it('shows a part with its balance, stock levels and linked equipment', function () {
    configureInstall();
    $part = partWithStock('3', ['part_number' => 'BELT-A42', 'reorder_point' => '4', 'min_level' => '2']);
    $part->equipment()->attach(Equipment::factory()->create(['code' => 'CNC-01'])->id);

    $this->actingAs(userWithRoles(Role::Planner))
        ->get(route('parts.show', $part))
        ->assertOk()
        ->assertSee('BELT-A42')
        ->assertSee('CNC-01')
        ->assertSeeInOrder(['On hand', '3'])
        ->assertSeeInOrder(['Reorder point', '4']);
});
