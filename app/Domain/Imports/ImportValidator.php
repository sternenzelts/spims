<?php

namespace App\Domain\Imports;

use App\Domain\Parts\StockLevels;
use App\Domain\Settings\InstallSettings;
use App\Enums\Criticality;
use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checks every row of an import against PRD FR-SPIMS-005 and the rules the spare part screens apply
 * (FR-SPIMS-001 to 003), without writing anything. Existing parts, locations and equipment are
 * looked up in a few bulk queries, so checking 5,000 rows stays quick.
 */
final readonly class ImportValidator
{
    /** A quantity numeric(14,3) can hold: zero or more, at most three decimal places. */
    private const string QUANTITY = '/^\d{1,11}(\.\d{1,3})?$/';

    private const string QUANTITY_MESSAGE = 'must be a number of zero or more, with at most three decimal places.';

    /** The most data rows one import may hold below its header. */
    private const int MAX_ROWS = 10_000;

    public function __construct(
        private StockLevels $stockLevels,
        private InstallSettings $settings,
    ) {}

    public function validate(ImportBatch $batch, User $actor): ImportPreview
    {
        $rows = [];

        foreach (ImportFile::rows($batch->storage_path, $batch->format) as $rowNumber => $cells) {
            $rows[$rowNumber] = $cells;

            // The header row plus MAX_ROWS data rows: stop reading rather than hold a much larger
            // file whole, so an oversized upload becomes this message instead of a blank 500.
            if (count($rows) > self::MAX_ROWS + 1) {
                return new ImportPreview([], 0, [['row' => 0, 'message' => 'The file has more than '.number_format(self::MAX_ROWS).' rows below its header. Split it into smaller files and import each one.']], []);
            }
        }

        unset($rows[array_key_first($rows)]);

        if ($rows === []) {
            return new ImportPreview([], 0, [['row' => 0, 'message' => 'The file has no rows below its header.']], []);
        }

        $errors = [];
        $warnings = [];
        $columns = $this->permittedColumns($batch->column_map, $rows, $actor, $errors);

        $partNumbers = array_map(fn (array $cells): string => $this->cell($cells, $columns, ImportColumn::PartNumber), $rows);
        $keys = array_map(fn (string $partNumber): string => Str::lower($partNumber), $partNumbers);
        $occurrences = array_count_values(array_filter($keys, fn (string $key): bool => $key !== ''));

        $existing = Part::query()
            // array_keys turns an all-digit part number such as "12345" into an integer: cast it back.
            ->whereIn(DB::raw('lower(part_number)'), array_map(fn (int|string $key): string => (string) $key, array_keys($occurrences)))
            ->get()
            ->keyBy(fn (Part $part): string => Str::lower($part->part_number));
        $moved = array_flip(array_map(
            fn (mixed $partId): int => (int) $partId,
            StockMovement::query()->whereIn('part_id', $existing->modelKeys())->distinct()->pluck('part_id')->all(),
        ));
        $locations = StorageLocation::query()->get()->keyBy('code');
        $equipment = Equipment::query()->get()->keyBy('code');

        $valid = [];

        foreach ($rows as $rowNumber => $cells) {
            $rowErrors = [];
            $partNumber = $partNumbers[$rowNumber];
            $part = $existing->get($keys[$rowNumber]);

            if ($partNumber === '') {
                $rowErrors[] = 'Part number is required.';
            } elseif (mb_strlen($partNumber) > 100) {
                $rowErrors[] = 'Part number must be at most 100 characters.';
            } elseif ($occurrences[$keys[$rowNumber]] > 1) {
                $rowErrors[] = "Part number {$partNumber} appears more than once in the file.";
            }

            $details = [];
            $description = $this->cell($cells, $columns, ImportColumn::Description);
            $unit = $this->cell($cells, $columns, ImportColumn::UnitOfMeasure);

            if ($description === '') {
                $rowErrors[] = 'Description is required.';
            } elseif (mb_strlen($description) > 500) {
                $rowErrors[] = 'Description must be at most 500 characters.';
            } else {
                $details['description'] = $description;
            }

            if ($unit === '') {
                $rowErrors[] = 'Unit of measure is required.';
            } elseif (mb_strlen($unit) > 20) {
                $rowErrors[] = 'Unit of measure must be at most 20 characters.';
            } elseif ($part !== null && $unit !== $part->unit_of_measure && isset($moved[$part->id])) {
                $rowErrors[] = "The unit of measure of {$part->part_number} cannot change: it has stock movements.";
            } else {
                $details['unit_of_measure'] = $unit;
            }

            $locationCode = Str::upper($this->cell($cells, $columns, ImportColumn::LocationCode));

            if ($locationCode !== '') {
                $location = $locations->get($locationCode);

                if ($location === null) {
                    $rowErrors[] = "Storage location {$locationCode} does not exist.";
                } elseif (! $location->is_active && $location->id !== $part?->default_location_id) {
                    $rowErrors[] = "Storage location {$locationCode} is Inactive.";
                } else {
                    $details['default_location_id'] = $location->id;
                }
            }

            $equipmentIds = null;
            $equipmentCodes = $this->cell($cells, $columns, ImportColumn::EquipmentCodes);

            if ($equipmentCodes !== '') {
                $equipmentIds = [];

                foreach (preg_split('/[,;\n]+/', $equipmentCodes) ?: [] as $code) {
                    $code = Str::upper(trim($code));

                    if ($code === '') {
                        continue;
                    }

                    $machine = $equipment->get($code);

                    if ($machine === null) {
                        $rowErrors[] = "Equipment {$code} does not exist.";
                    } elseif (! $machine->is_active) {
                        $rowErrors[] = "Equipment {$code} is Inactive.";
                    } else {
                        $equipmentIds[] = $machine->id;
                    }
                }

                $equipmentIds = array_values(array_unique($equipmentIds));
            }

            $levels = $this->levels($cells, $columns, $part, $rowErrors);

            $openingQuantity = null;
            $opening = $this->cell($cells, $columns, ImportColumn::OpeningQuantity);

            if ($opening !== '') {
                if (preg_match(self::QUANTITY, $opening) !== 1) {
                    $rowErrors[] = 'Opening quantity '.self::QUANTITY_MESSAGE;
                } elseif (BigDecimal::of($opening)->isPositive()) {
                    if ($part !== null && isset($moved[$part->id])) {
                        $rowErrors[] = "{$part->part_number} already has stock movements, so its opening balance cannot be imported.";
                    } else {
                        $openingQuantity = (string) BigDecimal::of($opening)->toScale(3);
                    }
                }
            }

            foreach ($rowErrors as $message) {
                $errors[] = ['row' => $rowNumber, 'message' => $message];
            }

            if ($rowErrors === []) {
                if ($levels !== null) {
                    foreach ($this->stockLevels->warnings($levels) as $warning) {
                        $warnings[] = ['row' => $rowNumber, 'message' => $warning];
                    }
                }

                $valid[] = new ImportRow($rowNumber, $partNumber, $part?->id, $details, $levels, $equipmentIds, $openingQuantity);
            }
        }

        $hasOpening = array_filter($valid, fn (ImportRow $row): bool => $row->openingQuantity !== null) !== [];

        if ($hasOpening && ! $this->settings->isReadyForStockTransactions()) {
            array_unshift($errors, ['row' => 0, 'message' => 'Set the company name in Install Settings before importing opening balances.']);
        }

        return new ImportPreview($valid, count($rows), $errors, $warnings);
    }

    /**
     * The mapped columns this importer may use. A restricted column that holds a value is an error
     * for the whole file (PRD FR-SPIMS-005); left blank, it is simply ignored.
     *
     * @param  array<string, int>  $map
     * @param  array<int, list<string>>  $rows
     * @param  list<array{row: int, message: string}>  $errors
     * @return array<string, int>
     */
    private function permittedColumns(array $map, array $rows, User $actor, array &$errors): array
    {
        foreach (ImportColumn::cases() as $column) {
            $permission = $column->permission();

            if (! isset($map[$column->value]) || $permission === null || $actor->can($permission)) {
                continue;
            }

            $index = $map[$column->value];
            $filled = array_filter($rows, fn (array $cells): bool => ($cells[$index] ?? '') !== '') !== [];

            if ($filled) {
                $who = $permission === Permission::OpeningBalancesImport
                    ? 'only a System Administrator may import opening quantities.'
                    : 'only a System Administrator may import stock levels.';

                $errors[] = ['row' => 0, 'message' => "Column \"{$column->label()}\": {$who}"];
            }

            unset($map[$column->value]);
        }

        return $map;
    }

    /**
     * The part's complete stock levels with the row's values applied, or null when the row sets
     * none. Each value is checked as the stock levels screen checks it (PRD FR-SPIMS-002).
     *
     * @param  list<string>  $cells
     * @param  array<string, int>  $columns
     * @param  list<string>  $rowErrors
     * @return array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: Criticality, lead_time_days: int|null}|null
     */
    private function levels(array $cells, array $columns, ?Part $part, array &$rowErrors): ?array
    {
        $quantityColumns = [
            'reorder_point' => ImportColumn::ReorderPoint,
            'min_level' => ImportColumn::MinLevel,
            'critical_level' => ImportColumn::CriticalLevel,
            'max_level' => ImportColumn::MaxLevel,
        ];
        $quantities = array_filter(
            array_map(fn (ImportColumn $column): string => $this->cell($cells, $columns, $column), $quantityColumns),
            fn (string $value): bool => $value !== '',
        );
        $criticality = $this->cell($cells, $columns, ImportColumn::Criticality);
        $leadTime = $this->cell($cells, $columns, ImportColumn::LeadTimeDays);

        if ($quantities === [] && $criticality === '' && $leadTime === '') {
            return null;
        }

        $levels = $part === null
            ? ['reorder_point' => '0.000', 'min_level' => '0.000', 'critical_level' => '0.000', 'max_level' => null, 'criticality' => Criticality::Normal, 'lead_time_days' => null]
            : ['reorder_point' => $part->reorder_point, 'min_level' => $part->min_level, 'critical_level' => $part->critical_level, 'max_level' => $part->max_level, 'criticality' => $part->criticality, 'lead_time_days' => $part->lead_time_days];

        foreach ($quantities as $key => $value) {
            if (preg_match(self::QUANTITY, $value) === 1) {
                $levels[$key] = (string) BigDecimal::of($value)->toScale(3);
            } else {
                $rowErrors[] = $quantityColumns[$key]->label().' '.self::QUANTITY_MESSAGE;
            }
        }

        if ($criticality !== '') {
            $level = Criticality::tryFrom(Str::lower($criticality));

            if ($level === null) {
                $rowErrors[] = 'Criticality must be Critical, Important or Normal.';
            } else {
                $levels['criticality'] = $level;
            }
        }

        if ($leadTime !== '') {
            if (preg_match('/^[1-9]\d{0,4}$/', $leadTime) === 1) {
                $levels['lead_time_days'] = (int) $leadTime;
            } else {
                $rowErrors[] = 'Lead time must be a whole number of days, greater than zero.';
            }
        }

        if (BigDecimal::of($levels['critical_level'])->isGreaterThan($levels['min_level'])) {
            $rowErrors[] = 'The critical level cannot be above the minimum level.';
        }

        if ($levels['max_level'] !== null && BigDecimal::of($levels['max_level'])->isLessThan($levels['min_level'])) {
            $rowErrors[] = 'The maximum level cannot be below the minimum level.';
        }

        return $levels;
    }

    /**
     * The row's text in an import column, or an empty string when the column is not mapped.
     *
     * @param  list<string>  $cells
     * @param  array<string, int>  $columns
     */
    private function cell(array $cells, array $columns, ImportColumn $column): string
    {
        $index = $columns[$column->value] ?? null;

        return $index === null ? '' : ($cells[$index] ?? '');
    }
}
