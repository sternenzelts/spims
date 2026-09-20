<?php

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\PartImports;
use App\Domain\Settings\InstallSettings;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    configureInstall();
    $this->admin = userWithRoles(Role::Admin);
    $this->check = fn ($batch, $actor = null) => app(PartImports::class)->check($batch, $batch->column_map, $actor ?? $this->admin);
    $this->messages = fn ($batch) => array_map(fn (array $error): string => "{$error['row']}: {$error['message']}", $batch->refresh()->errors);
});

it('previews the parts to create and update and the opening balances, writing nothing', function () {
    Part::factory()->create(['part_number' => 'BRG-6204', 'unit_of_measure' => 'pcs']);
    $batch = uploadImport(
        "Part number,Description,Unit of measure,Opening quantity\n".
        "brg-6204,Deep groove ball bearing,pcs,12\n".
        "VB-A42,V-belt A42,pcs,0\n".
        "OIL-68,Hydraulic oil,L,20.5\n",
        $this->admin,
    );

    $preview = ($this->check)($batch);

    expect($preview->isValid())->toBeTrue()
        ->and($preview->creates())->toBe(2)
        ->and($preview->updates())->toBe(1)
        ->and($preview->openingBalances())->toBe(2)
        ->and($batch->refresh())
        ->status->toBe(ImportStatus::Validated)
        ->rows_total->toBe(3)
        ->rows_created->toBe(2)
        ->rows_updated->toBe(1)
        ->opening_balances->toBe(2)
        // toEqual: each preview row is a jsonb object, whose keys come back in jsonb's order.
        ->preview->toEqual([
            ['row' => 2, 'part_number' => 'brg-6204', 'action' => 'update', 'opening_quantity' => '12.000'],
            ['row' => 3, 'part_number' => 'VB-A42', 'action' => 'create', 'opening_quantity' => null],
            ['row' => 4, 'part_number' => 'OIL-68', 'action' => 'create', 'opening_quantity' => '20.500'],
        ])
        ->and(Part::query()->count())->toBe(1)
        ->and(StockMovement::query()->exists())->toBeFalse();
});

it('reports every row whose part number appears twice, ignoring case', function () {
    $batch = uploadImport(
        "Part number,Description,Unit of measure\nBRG-6204,Bearing,pcs\nVB-A42,V-belt,pcs\nbrg-6204,Bearing again,pcs\n",
        $this->admin,
    );

    ($this->check)($batch);

    expect(($this->messages)($batch))->toBe([
        '2: Part number BRG-6204 appears more than once in the file.',
        '4: Part number brg-6204 appears more than once in the file.',
    ])->and($batch)
        ->status->toBe(ImportStatus::Failed)
        ->rows_failed->toBe(2);
});

it('requires part number, description and unit of measure on every row', function () {
    $batch = uploadImport("Part number,Description,Unit of measure\n,Bearing,pcs\nVB-A42,,\n", $this->admin);

    ($this->check)($batch);

    expect(($this->messages)($batch))->toBe([
        '2: Part number is required.',
        '3: Description is required.',
        '3: Unit of measure is required.',
    ]);
});

it('refuses an opening balance, or a new unit of measure, for a part that already has stock movements', function () {
    partWithStock('5', ['part_number' => 'OIL-68', 'unit_of_measure' => 'L']);
    $batch = uploadImport("Part number,Description,Unit of measure,Opening quantity\nOIL-68,Hydraulic oil,mL,3\n", $this->admin);

    ($this->check)($batch);

    expect(($this->messages)($batch))->toBe([
        '2: The unit of measure of OIL-68 cannot change: it has stock movements.',
        '2: OIL-68 already has stock movements, so its opening balance cannot be imported.',
    ]);
});

it('matches codes as the screens store them, and refuses unknown or Inactive ones', function () {
    StorageLocation::factory()->create(['code' => 'A-01']);
    StorageLocation::factory()->create(['code' => 'Z-99', 'is_active' => false]);
    Equipment::factory()->create(['code' => 'CNC-01']);
    Equipment::factory()->create(['code' => 'OLD-01', 'is_active' => false]);
    $batch = uploadImport(
        "Part number,Description,Unit of measure,Storage location code,Equipment codes\n".
        "BRG-6204,Bearing,pcs, a-01 ,cnc-01\n".
        "VB-A42,V-belt,pcs,Z-99,\n".
        "OIL-68,Oil,L,B-02,CNC-01; PRS-9; old-01\n",
        $this->admin,
    );

    ($this->check)($batch);

    expect(($this->messages)($batch))->toBe([
        '3: Storage location Z-99 is Inactive.',
        '4: Storage location B-02 does not exist.',
        '4: Equipment PRS-9 does not exist.',
        '4: Equipment OLD-01 is Inactive.',
    ]);
});

it('checks stock levels as the stock levels screen does, warning about an unusual reorder point', function () {
    $batch = uploadImport(
        "Part number,Description,Unit of measure,Criticality,Reorder point,Minimum level,Critical level,Maximum level,Lead time (days)\n".
        "P-1,Good,pcs,critical,10,4,2,30,21\n".
        "P-2,Critical above minimum,pcs,,,4,5,,\n".
        "P-3,Maximum below minimum,pcs,,,4,,3,\n".
        "P-4,Reorder below minimum,pcs,Important,3,4,,,\n".
        "P-5,Bad values,pcs,vital,1.2345,,,,2.5\n",
        $this->admin,
    );

    $preview = ($this->check)($batch);

    expect(($this->messages)($batch))->toBe([
        '3: The critical level cannot be above the minimum level.',
        '4: The maximum level cannot be below the minimum level.',
        '6: Reorder point must be a number of zero or more, with at most three decimal places.',
        '6: Criticality must be Critical, Important or Normal.',
        '6: Lead time must be a whole number of days, greater than zero.',
    ])->and($batch->warnings)->toBe([
        ['row' => 5, 'message' => 'The reorder point is below the minimum level, so replenishment would be requested only after stock falls below safety stock.'],
    ])->and($preview->rows[0]->levels)->toMatchArray(['reorder_point' => '10.000', 'max_level' => '30.000', 'lead_time_days' => 21]);
});

it('refuses columns the importer may not change, unless they are blank', function () {
    $supervisor = userWithRoles(Role::Supervisor);
    $blank = uploadImport("Part number,Description,Unit of measure,Minimum level,Opening quantity\nBRG-6204,Bearing,pcs,,\n", $supervisor);
    $filled = uploadImport("Part number,Description,Unit of measure,Minimum level,Opening quantity\nBRG-6204,Bearing,pcs,4,12\n", $supervisor);

    ($this->check)($blank, $supervisor);
    ($this->check)($filled, $supervisor);

    expect($blank->refresh()->status)->toBe(ImportStatus::Validated)
        ->and(($this->messages)($filled))->toBe([
            '0: Column "Minimum level": only planners and System Administrators may import stock levels.',
            '0: Column "Opening quantity": only a System Administrator may import opening quantities.',
        ]);
});

it('refuses opening balances until the company name is set', function () {
    app(InstallSettings::class)->set('company.name', null, $this->admin);
    $batch = uploadImport("Part number,Description,Unit of measure,Opening quantity\nBRG-6204,Bearing,pcs,12\n", $this->admin);

    ($this->check)($batch);

    expect(($this->messages)($batch))->toBe(['0: Set the company name in Install Settings before importing opening balances.']);
});

it('refuses a file with a header row only', function () {
    $batch = uploadImport("Part number,Description,Unit of measure\n", $this->admin);

    ($this->check)($batch);

    expect(($this->messages)($batch))->toBe(['0: The file has no rows below its header.']);
});

it('refuses a mapping that leaves out a required column or gives one column two fields', function () {
    $batch = uploadImport("Item,Name,Unit\nBRG-6204,Bearing,pcs\n", $this->admin);
    $imports = app(PartImports::class);

    expect(fn () => $imports->check($batch, ['description' => 1, 'unit_of_measure' => 2], $this->admin))
        ->toThrow(ImportRefused::class, 'Match these required fields to a column of the file: Part number.')
        ->and(fn () => $imports->check($batch, ['part_number' => 0, 'description' => 0, 'unit_of_measure' => 2], $this->admin))
        ->toThrow(ImportRefused::class, 'Column "Item" is matched to more than one field.');

    $imports->check($batch, ['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2], $this->admin);

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Validated)
        ->column_map->toEqual(['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2]);
});
