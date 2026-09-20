<?php

namespace App\Domain\Imports;

use App\Enums\Permission;

/**
 * The columns of the spare part import (PRD FR-SPIMS-005). Until Microtech's starting parts list is
 * known (open question Q18), the template holds the spare part fields plus codes and the opening
 * quantity, and a file laid out differently is matched column by column before it is checked.
 */
enum ImportColumn: string
{
    case PartNumber = 'part_number';
    case Description = 'description';
    case UnitOfMeasure = 'unit_of_measure';
    case LocationCode = 'location_code';
    case EquipmentCodes = 'equipment_codes';
    case Criticality = 'criticality';
    case ReorderPoint = 'reorder_point';
    case MinLevel = 'min_level';
    case CriticalLevel = 'critical_level';
    case MaxLevel = 'max_level';
    case LeadTimeDays = 'lead_time_days';
    case OpeningQuantity = 'opening_quantity';

    public function label(): string
    {
        return match ($this) {
            self::PartNumber => 'Part number',
            self::Description => 'Description',
            self::UnitOfMeasure => 'Unit of measure',
            self::LocationCode => 'Storage location code',
            self::EquipmentCodes => 'Equipment codes',
            self::Criticality => 'Criticality',
            self::ReorderPoint => 'Reorder point',
            self::MinLevel => 'Minimum level',
            self::CriticalLevel => 'Critical level',
            self::MaxLevel => 'Maximum level',
            self::LeadTimeDays => 'Lead time (days)',
            self::OpeningQuantity => 'Opening quantity',
        };
    }

    /**
     * PRD FR-SPIMS-005: part number, description and unit of measure are required on every row.
     */
    public function isRequired(): bool
    {
        return in_array($this, [self::PartNumber, self::Description, self::UnitOfMeasure], true);
    }

    /**
     * The permission, beyond Permission::PartsImport, that importing a value in this column needs.
     */
    public function permission(): ?Permission
    {
        return match ($this) {
            self::Criticality, self::ReorderPoint, self::MinLevel, self::CriticalLevel, self::MaxLevel,
            self::LeadTimeDays => Permission::StockLevelsManage,
            self::OpeningQuantity => Permission::OpeningBalancesImport,
            default => null,
        };
    }

    /**
     * Other header spellings recognised when a file is matched.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::PartNumber => ['part no', 'part num', 'part code', 'item code', 'item number', 'item no'],
            self::Description => ['item description', 'part description', 'desc', 'name', 'item name'],
            self::UnitOfMeasure => ['uom', 'unit', 'units', 'unit of measurement'],
            self::LocationCode => ['location code', 'storage location', 'location', 'bin', 'rack', 'bin location'],
            self::EquipmentCodes => ['equipment code', 'equipment', 'machine', 'machines', 'machine codes', 'used on'],
            self::Criticality => ['critical class'],
            self::ReorderPoint => ['rop', 'reorder level', 're-order point'],
            self::MinLevel => ['min level', 'minimum', 'min', 'min stock', 'safety stock'],
            self::CriticalLevel => ['critical stock', 'critical qty'],
            self::MaxLevel => ['max level', 'maximum', 'max', 'max stock'],
            self::LeadTimeDays => ['lead time', 'lead time days', 'leadtime'],
            self::OpeningQuantity => ['opening balance', 'opening stock', 'quantity', 'qty', 'on hand', 'stock on hand', 'balance'],
        };
    }

    /**
     * Match a file's header row to the import columns. Each column takes the first header that reads
     * like its label, its value or one of its aliases, ignoring case, spaces and punctuation, and no
     * header is given to two columns.
     *
     * @param  list<string>  $headers
     * @return array<string, int> column value => index of the matching header
     */
    public static function guessMap(array $headers): array
    {
        $normalised = array_map(self::normalise(...), $headers);
        $map = [];

        foreach (self::cases() as $column) {
            $spellings = array_map(self::normalise(...), [$column->label(), $column->value, ...$column->aliases()]);

            foreach ($normalised as $index => $header) {
                if ($header !== '' && in_array($header, $spellings, true) && ! in_array($index, $map, true)) {
                    $map[$column->value] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    private static function normalise(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($text));
    }
}
