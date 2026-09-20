<?php

use App\Domain\Imports\ImportColumn;
use App\Enums\Permission;

it('matches a header row written in the template labels', function () {
    $labels = array_map(fn (ImportColumn $column): string => $column->label(), ImportColumn::cases());

    expect(ImportColumn::guessMap($labels))->toBe(array_combine(
        array_map(fn (ImportColumn $column): string => $column->value, ImportColumn::cases()),
        array_keys($labels),
    ));
});

it('matches common spellings, ignoring case, spaces and punctuation', function () {
    expect(ImportColumn::guessMap(['Qty', 'ITEM CODE', 'Remarks', 'U.O.M.', 'Item Description', 'Location']))
        ->toBe([
            'part_number' => 1,
            'description' => 4,
            'unit_of_measure' => 3,
            'location_code' => 5,
            'opening_quantity' => 0,
        ]);
});

it('takes the first of two matching headers', function () {
    expect(ImportColumn::guessMap(['Part number', 'Part No.']))->toBe(['part_number' => 0]);
});

it('names the permission each restricted column needs', function () {
    expect(ImportColumn::MinLevel->permission())->toBe(Permission::StockLevelsManage)
        ->and(ImportColumn::Criticality->permission())->toBe(Permission::StockLevelsManage)
        ->and(ImportColumn::LeadTimeDays->permission())->toBe(Permission::StockLevelsManage)
        ->and(ImportColumn::OpeningQuantity->permission())->toBe(Permission::OpeningBalancesImport)
        ->and(ImportColumn::Description->permission())->toBeNull();
});

it('requires part number, description and unit of measure only', function () {
    expect(array_values(array_map(
        fn (ImportColumn $column): string => $column->value,
        array_filter(ImportColumn::cases(), fn (ImportColumn $column): bool => $column->isRequired()),
    )))->toBe(['part_number', 'description', 'unit_of_measure']);
});
