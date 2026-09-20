# SPIMS Plan 2b: Spare Part and Opening Balance Import — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A System Administrator, or a supervisor for part data, imports spare parts and opening balances from an Excel or CSV file: download the template, upload, match columns, check every row without writing anything, then confirm. The import writes every part through the audited part services and every opening balance through `StockLedger`, all or nothing. This is design §3.6 provisioning step 7.

**Architecture:**
- `openspout/openspout` reads the first sheet of an `.xlsx` or `.csv` file row by row and writes the template.
- Domain classes in `app/Domain/Imports` do the work:
  - `ImportValidator` turns a file into checked `ImportRow`s and row-numbered errors.
  - `ImportWriter` applies checked rows through Plan 2's `PartRecords` and `StockLevels`, and posts opening balances as `opening` transactions of at most 200 lines through Plan 1's `StockLedger`.
  - `PartImports` runs a batch through its life: upload, check, confirm and cancel, recorded in `import_batches` (PRD §7.7).
- Confirmation runs as a queued job inside one database transaction, after re-checking the file.
- Three Livewire screens (list, upload, review) follow Plan 2's patterns.

**Tech Stack:** as Plan 2, plus `openspout/openspout ^5.11`. That is PHP 8.4+, Laravel 13, Livewire 4.4 with Flux 2.19 (free components), PostgreSQL 17, Pest 5, Larastan (PHPStan level 7) and Pint.

**Related documents:**
- roadmap §3b: `docs/superpowers/plans/2026-09-13-spims-microtech-roadmap.md`
- design §4.1, §4.3 and §5.7: `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`
- requirements FR-SPIMS-005 and §7.7: `docs/prd/Microtech_SPIMS_PRD_v1.0.md`
- Plan 2, for the patterns and services used here: `docs/superpowers/plans/2026-09-19-spims-plan-02-administration-master-data.md`

## Global Constraints

Plans 1 and 2 hold unchanged:

- **PHP `^8.4`** and **PostgreSQL 17** everywhere. Never SQLite.
- **`composer test` passes before every commit.** It runs Pint, PHPStan level 7 over `app/ bootstrap/app.php config/ database/ routes/`, then the whole Pest suite. Never add `@phpstan-ignore` comments or baseline entries.
  - Inside a worktree-isolated agent session the guard refuses `env -u …`, so run `composer test` plainly: its output is JSON.
  - Local PHP is capped at `memory_limit=128M`.
- **Schema conventions:**
  - `text`, never `varchar`.
  - `timestamptz` at full precision. Never pass a precision to `timestampTz()`.
  - `numeric(14,3)` for quantities.
  - `text` + `CHECK` instead of enums.
  - Explicitly named indexes.
  - `SchemaConventionsTest` enforces the first two.
- **Quantities are decimal strings in PHP**, with arithmetic in `Brick\Math\BigDecimal`. Never cast a quantity to `float` or `int`.
- **The ledger invariant:** for every part, `part_stock.on_hand = SUM(stock_movements.quantity)`. Opening balances are `opening` movements written by `StockLedger::complete()`, never direct `on_hand` writes (design §4.1).
- **No company-specific values in code.**
- **The morph map is enforced.** Every audited model is listed in `AppServiceProvider::configureMorphMap()`.
- **Every screen authorizes twice.** `mount()` and every data-changing action call `$this->authorize(Permission::…)`, and every screen has a role-by-action test built with `roleMatrix()`.
- **Nothing is deleted.** A cancelled or failed batch stays on record.
- **Normalisation matches Plan 2.**
  - Storage location codes, equipment codes and employee numbers are trimmed and uppercased.
  - Part numbers are trimmed, keep their case, and are matched ignoring case.
- **Tests:**
  - Use Pest function syntax.
  - A test that expects a database error wraps the failing call in `inSavepoint()`.
  - Audit `old_values` and `new_values` come back from `jsonb` with keys shorter first, equal lengths in byte order. A multi-key `toBe()` lists keys in that order.
- **Livewire 4 names a component called `Index` in a subfolder after the subfolder**, so `App\Livewire\Imports\Index` renders `resources/views/livewire/imports.blade.php`.
- **Commits:** one per task, with the message given in the task, staged by path. Never `git add -A` or `git add .`.

Added by this plan:

- **Every import change goes through a service the screens use.**
  - Parts go through `PartRecords::create()` and `update()`.
  - Stock levels go through `StockLevels::update()`.
  - Opening balances go through `StockLedger::complete()` on an `opening` transaction.
  - Each part change is therefore audited like a change made on its screen.
- **A confirmed import is all or nothing.** It runs in one database transaction and re-checks the file first. Any failure leaves the batch Failed with its reason, and nothing written.
- **Uploaded files** are stored on the default disk under `imports/` and never served to browsers. The reader copies a stored file to a temporary local file first, so the same code works on the S3-compatible disk in Plan 7.

## Before You Start

Read these first:

- PRD FR-SPIMS-005, the §7.7 import batch lifecycle, and the §8 rows "Import Spare Parts" and "Import Opening Balances".
- Design §4.1 (opening balances as movements), the `import_batches` table in §4.3, and §5.7.
- Plan 2's `app/Domain/Parts/PartRecords.php`, `app/Domain/Parts/StockLevels.php`, and `app/Livewire/MasterData/StorageLocations/*` (the screen pattern).
- `tests/Pest.php` for the helpers.

Work in a worktree off `master` (superpowers:using-git-worktrees). Copy the main checkout's `.env` into it, then run `composer install` and `npm install && npm run build`.

**How the code in this plan was checked.** It was written against `master` at `58a01c5`. `openspout/openspout` 5.11 was installed and its source read. A probe confirmed these behaviours on this machine:

- With empty rows preserved, the reader returns every physical row, so counting rows gives the spreadsheet row number.
- XLSX numbers come back as `int` or `float`.
- Blank rows report `isEmpty()`.
- A CSV byte-order mark is stripped.
- Cells keep their surrounding spaces, so the reader trims them.

Every file in this plan was then written into a scratch copy of the repository, along with the changes each task's "Modify" steps describe, and `composer test` was run there: Pint, PHPStan level 7 and the whole Pest suite passed, at 535 tests. Three defects that run found are already corrected in the text below:

- On Windows a spreadsheet reader keeps the file open through a reference cycle, so a temporary copy cannot be deleted until the reader is released. `SpreadsheetFile::headerRow()` and `ImportFile::headerRow()` exist for that reason — never read a header by stopping part-way through `rows()`.
- A part just created holds only the attributes it was created with, so `ImportWriter` refreshes it before applying stock levels.
- `toThrow(Throwable::class)` does not assert a class in Pest, because `Throwable` is an interface.

The scratch copy was thrown away, and the tasks below are still written test-first: write each task's tests, watch them fail, then write the code. If a result differs from the expected output, fix the code, keep the test's intent, and record the deviation with its evidence in the task report.

## Decisions Made in This Plan

1. **Spreadsheet library: `openspout/openspout` ^5.11, not `maatwebsite/excel` 4.** Openspout streams a sheet row by row and writes the template, which is all this plan needs. `maatwebsite/excel` reads through PhpSpreadsheet, which loads the workbook into memory, and local PHP is capped at 128 MB. Plan 6 chooses its export library separately.
2. **The column contract, while Microtech's starting parts list (Q18) is unknown:**
   - **The columns:** twelve columns, taking the spare part fields plus storage location code, equipment codes and opening quantity.
   - **Required columns:** part number, description and unit of measure, on every row (PRD FR-SPIMS-005).
   - **Matching:** a file's header row is matched to these columns by label or a common alias, ignoring case, spaces and punctuation. The user can re-match every column before checking, so a differently laid-out list imports without a code change.
3. **A row whose part number already exists, ignoring case, updates that part.**
   - A blank cell leaves the field as it is; an import never clears a value.
   - Status is not importable. Parts are activated and deactivated on their screen.
   - Equipment codes, when given, replace the part's equipment links.
4. **Opening quantities:**
   - A blank or zero quantity records nothing.
   - A positive quantity becomes one line of an `opening` transaction.
   - It is refused for a part that already has stock movements (PRD FR-SPIMS-005).
   - It is refused while the company name is not set (`StockLedger` would refuse too).
5. **All or nothing.**
   - Confirmation runs in one database transaction and checks the file again first, because parts or stock may have changed since the preview.
   - A failure rolls everything back and leaves the batch Failed with its reason, which matches PRD §7.7 ("nothing was imported").
   - Resuming half an import is therefore never needed.
6. **Opening transactions hold at most 200 lines each,** each numbered `OP-YYYY-NNNNNN`. Each posting locks at most 200 stock rows in one ordered statement. The rows stay locked until the import commits, but they belong to parts no station uses yet.
7. **Checking runs in the request; writing runs on the queue.** Reading and checking 5,000 rows is a few bulk queries. Writing is several audited statements per part, so it runs in the `ConfirmImport` job: one attempt, 30-minute timeout. The review screen polls until the job finishes.
8. **Column permissions (PRD FR-SPIMS-005, §8):**
   - The stock level columns (criticality, reorder point, minimum, critical and maximum levels, lead time) need `StockLevelsManage`.
   - The opening quantity column needs `OpeningBalancesImport`.
   - A restricted column is reported as an error only when it holds a value, so a supervisor can upload the full template with those columns left blank.
9. **Stock level rules match the stock levels screen.**
   - A critical level above the minimum, or a maximum below it, is an error.
   - A reorder point outside minimum to maximum is a warning shown in the preview; confirming the import confirms it.
10. **Auditing:**
    - Every part created or updated is audited by `PartRecords` and `StockLevels`.
    - Every opening transaction is audited by `StockLedger`.
    - The batch is audited when it is imported and when it is cancelled.
11. **Carried from Plan 2 (its Ruling Q):** withdrawal purpose names become unique ignoring case (Task 7).

## File Map

```
app/
  Domain/Imports/
    ImportColumn.php          the twelve columns, labels, aliases, permissions, header matching  (Task 1)
    SpreadsheetFile.php       stream the first sheet of an xlsx or csv file                      (Task 1)
    ImportTemplate.php        write the xlsx template                                            (Task 1)
    ImportFile.php            read a stored import from the default disk                         (Task 2)
    PartImports.php           upload, check, confirm and cancel a batch                          (Tasks 2–4)
    Exceptions/ImportRefused.php                                                                  (Task 2)
    ImportRow.php, ImportPreview.php, ImportValidator.php                                        (Task 3)
    ImportWriter.php          write checked rows and opening balances                            (Task 4)
  Enums/ImportStatus.php                                                                         (Task 2)
  Http/Controllers/ImportTemplateController.php                                                  (Task 5)
  Jobs/ConfirmImport.php                                                                          (Task 4)
  Livewire/Imports/{Index,Upload,Review}.php                                                      (Task 5)
  Models/ImportBatch.php                                                                          (Task 2)
database/
  factories/ImportBatchFactory.php                                                                (Task 2)
  migrations/2026_09_20_100001_create_import_batches_table.php                                    (Task 2)
  migrations/2026_09_20_100002_make_withdrawal_purpose_names_unique_ignoring_case.php            (Task 7)
resources/views/livewire/imports.blade.php, imports/{upload,review}.blade.php                    (Task 5)
tests/Unit/Imports/, tests/Feature/Imports/                                                       (Tasks 1–5)
```

---

## Task 1: Spreadsheet reading and the import template

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `app/Domain/Imports/ImportColumn.php`, `app/Domain/Imports/SpreadsheetFile.php`, `app/Domain/Imports/ImportTemplate.php`, `tests/Unit/Imports/ImportColumnTest.php`, `tests/Unit/Imports/SpreadsheetFileTest.php`

**Interfaces:**
- Consumes: `App\Enums\Permission` (Plan 1).
- Produces:
  - `enum ImportColumn` (twelve cases, values such as `part_number`), with these methods:
    - `label(): string`
    - `isRequired(): bool`
    - `permission(): ?Permission`
    - `aliases(): list<string>`
    - `static guessMap(list<string> $headers): array<string, int>`, mapping column value to header index
  - `SpreadsheetFile::FORMATS` (`['csv', 'xlsx']`).
  - `SpreadsheetFile::rows(string $path, string $format): Generator<int, list<string>>`: trimmed cells keyed by spreadsheet row number, with blank rows skipped.
  - `SpreadsheetFile::headerRow(string $path, string $format): ?array`: the first non-blank row, read and closed in one call.
  - `ImportTemplate::write(string $path): void`.

These classes use no Laravel services, so their tests are plain unit tests.

- [ ] **Step 1: Install openspout**

```bash
composer require openspout/openspout:^5.11
```

Expected: `openspout/openspout` 5.11 or later installed. It needs the `zip`, `xmlreader`, `dom`, `libxml` and `filter` PHP extensions, which Composer's platform check confirms.

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/Imports/ImportColumnTest.php`:

```php
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
```

Create `tests/Unit/Imports/SpreadsheetFileTest.php`:

```php
<?php

use App\Domain\Imports\ImportColumn;
use App\Domain\Imports\ImportTemplate;
use App\Domain\Imports\SpreadsheetFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

it('reads an xlsx sheet as trimmed text keyed by spreadsheet row, skipping blank rows', function () {
    $path = sys_get_temp_dir().'/'.uniqid('spims-test-', true).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Part number', 'Description', 'Opening quantity']));
    $writer->addRow(Row::fromValues(['BRG-6204', ' Bearing ', 12.5]));
    $writer->addRow(Row::fromValues([]));
    $writer->addRow(Row::fromValues(['OIL-68', null, 5]));
    $writer->close();

    expect(iterator_to_array(SpreadsheetFile::rows($path, 'xlsx')))->toBe([
        1 => ['Part number', 'Description', 'Opening quantity'],
        2 => ['BRG-6204', 'Bearing', '12.5'],
        4 => ['OIL-68', '', '5'],
    ]);
});

it('reads a csv file the same way, ignoring a byte-order mark', function () {
    $path = sys_get_temp_dir().'/'.uniqid('spims-test-', true).'.csv';
    file_put_contents($path, "\xEF\xBB\xBFPart number,Description\r\nBRG-6204, Bearing \r\n\r\n,\r\nVB-A42,V-belt\r\n");

    expect(iterator_to_array(SpreadsheetFile::rows($path, 'csv')))->toBe([
        1 => ['Part number', 'Description'],
        2 => ['BRG-6204', 'Bearing'],
        5 => ['VB-A42', 'V-belt'],
    ]);
});

it('writes a template whose header row names every import column', function () {
    $path = sys_get_temp_dir().'/'.uniqid('spims-test-', true).'.xlsx';

    ImportTemplate::write($path);

    expect(iterator_to_array(SpreadsheetFile::rows($path, 'xlsx')))->toBe([
        1 => array_map(fn (ImportColumn $column): string => $column->label(), ImportColumn::cases()),
    ]);
});

it('refuses a format it cannot read', function () {
    expect(fn () => iterator_to_array(SpreadsheetFile::rows('parts.ods', 'ods')))
        ->toThrow(InvalidArgumentException::class, 'Unsupported spreadsheet format [ods].');
});

it('reads the header row on its own', function () {
    $path = sys_get_temp_dir().'/'.uniqid('spims-test-', true).'.csv';
    file_put_contents($path, "\nPart number,Description\nBRG-6204,Bearing\n");

    expect(SpreadsheetFile::headerRow($path, 'csv'))->toBe(['Part number', 'Description']);

    unlink($path);
});

it('has no header row when every row is blank', function () {
    $path = sys_get_temp_dir().'/'.uniqid('spims-test-', true).'.csv';
    file_put_contents($path, "\n,\n");

    expect(SpreadsheetFile::headerRow($path, 'csv'))->toBeNull();

    unlink($path);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/Imports
```

Expected: FAIL with `Class "App\Domain\Imports\ImportColumn" not found`.

- [ ] **Step 4: Write the import columns**

Create `app/Domain/Imports/ImportColumn.php`:

```php
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
```

- [ ] **Step 5: Write the reader and the template**

Create `app/Domain/Imports/SpreadsheetFile.php`:

```php
<?php

namespace App\Domain\Imports;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an .xlsx or .csv file row by row, without loading it whole, so a
 * 5,000-row parts list stays well inside PHP's memory limit.
 */
final class SpreadsheetFile
{
    public const array FORMATS = ['csv', 'xlsx'];

    /**
     * Each non-blank row as trimmed text, keyed by its row number in the spreadsheet (the header is
     * row 1). Blank rows are skipped but counted, so row numbers match what the user sees in Excel.
     *
     * @return Generator<int, list<string>>
     */
    public static function rows(string $path, string $format): Generator
    {
        $reader = match ($format) {
            'csv' => new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            default => throw new InvalidArgumentException("Unsupported spreadsheet format [{$format}]."),
        };

        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $cells = self::cells($row->toArray());

                    if (implode('', $cells) !== '') {
                        yield $rowNumber => $cells;
                    }
                }

                // Only the first sheet is read.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * The row's values as trimmed text, with any gap between cells read as an empty string.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private static function cells(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $cells = [];

        for ($index = 0, $last = max(array_keys($values)); $index <= $last; $index++) {
            $cells[] = self::text($values[$index] ?? null);
        }

        return $cells;
    }

    /**
     * The file's first non-blank row, or null when it has none.
     *
     * Reading stops at that row, which leaves the reader holding the file open until this method
     * returns and its reader is released. A caller reading a temporary copy must read the header
     * through this method, never by stopping part-way through rows(): Windows cannot delete a file
     * that is still open.
     *
     * @return list<string>|null
     */
    public static function headerRow(string $path, string $format): ?array
    {
        foreach (self::rows($path, $format) as $cells) {
            return $cells;
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        $text = match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_array($value) => implode('', array_map(fn (mixed $run): string => $run instanceof TextRun ? $run->text : '', $value)),
            default => '',
        };

        return trim($text);
    }
}
```

Create `app/Domain/Imports/ImportTemplate.php`:

```php
<?php

namespace App\Domain\Imports;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The spare part import template (PRD FR-SPIMS-005): one header row naming every import column.
 */
final class ImportTemplate
{
    public static function write(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_map(fn (ImportColumn $column): string => $column->label(), ImportColumn::cases())));
        $writer->close();
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/Imports
```

Expected: PASS, 11 tests.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add composer.json composer.lock app/Domain/Imports/ImportColumn.php app/Domain/Imports/SpreadsheetFile.php app/Domain/Imports/ImportTemplate.php tests/Unit/Imports
git commit -m "Read spreadsheets row by row and write the spare part import template"
```

---

## Task 2: Import batches: upload and cancel

**Files:**
- Create:
  - `database/migrations/2026_09_20_100001_create_import_batches_table.php`
  - `app/Enums/ImportStatus.php`
  - `app/Models/ImportBatch.php`
  - `database/factories/ImportBatchFactory.php`
  - `app/Domain/Imports/ImportFile.php`
  - `app/Domain/Imports/PartImports.php`
  - `app/Domain/Imports/Exceptions/ImportRefused.php`
  - `tests/Feature/Imports/ImportUploadTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `tests/Pest.php`

**Interfaces:**
- Consumes: `SpreadsheetFile`, `ImportColumn` (Task 1), `AuditLogger`.
- Produces:
  - The `import_batches` table.
  - `enum ImportStatus` (Pending, Validated, Completed, Failed, Cancelled), with `label(): string` and `isOpen(): bool`.
  - `ImportBatch` (morph type `import_batch`) and `ImportBatchFactory`.
  - `ImportFile::rows(string $storagePath, string $format): Generator<int, list<string>>` and `ImportFile::headerRow(string $storagePath, string $format): ?array`, reading a stored file from the default disk through a temporary local copy.
  - `PartImports` methods:
    - `upload(UploadedFile $file, User $actor): ImportBatch`
    - `cancel(ImportBatch $batch, User $actor): void`
    - a private `lockOpen()` that Tasks 3 and 4 reuse
  - `ImportRefused`, with public `$field`.
  - Test helper `uploadImport(string $csv, User $actor): ImportBatch`.

The table extends design §4.3's `import_batches` in four ways. Task 6 records the changes in the design doc:

- **For mapping:** `format`, `headers` and `column_map`.
- **For the preview:** `warnings`, `preview` and `opening_balances`.
- **For the running import:** `confirmed_at` and `updated_at`.
- **`storage_path` is required**, because every batch has its file.

- [ ] **Step 1: Write the failing tests**

Add this helper to the end of `tests/Pest.php`, and add `use App\Domain\Imports\PartImports;`, `use App\Models\ImportBatch;` and `use Illuminate\Http\UploadedFile;` to its imports:

```php
/**
 * A pending import of this CSV text, uploaded as parts.csv by the actor. Call Storage::fake() first.
 */
function uploadImport(string $csv, User $actor): ImportBatch
{
    return app(PartImports::class)->upload(UploadedFile::fake()->createWithContent('parts.csv', $csv), $actor);
}
```

Create `tests/Feature/Imports/ImportUploadTest.php`:

```php
<?php

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\PartImports;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    Storage::fake();
    $this->admin = userWithRoles(Role::Admin);
});

it('stores the file as a pending batch with its header row and the columns it could match', function () {
    $batch = uploadImport("Part number,Description,UOM,Remarks\nBRG-6204,Bearing,pcs,spare\n", $this->admin);

    expect($batch)
        ->status->toBe(ImportStatus::Pending)
        ->filename->toBe('parts.csv')
        ->format->toBe('csv')
        ->headers->toBe(['Part number', 'Description', 'UOM', 'Remarks'])
        // toEqual: column_map is jsonb, which stores object keys in its own order.
        ->column_map->toEqual(['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2])
        ->imported_by_user_id->toBe($this->admin->id);

    Storage::assertExists($batch->storage_path);
});

it('accepts an xlsx workbook', function () {
    $path = sys_get_temp_dir().'/'.uniqid('spims-test-', true).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Part number', 'Description', 'Unit of measure']));
    $writer->close();

    $batch = app(PartImports::class)->upload(new UploadedFile($path, 'Parts list.xlsx', null, null, true), $this->admin);

    expect($batch->format)->toBe('xlsx')
        ->and($batch->headers)->toBe(['Part number', 'Description', 'Unit of measure']);
});

it('refuses a file that is not an xlsx or csv file, or cannot be read, or is empty', function (string $name, string $content, string $message) {
    expect(fn () => app(PartImports::class)->upload(UploadedFile::fake()->createWithContent($name, $content), $this->admin))
        ->toThrow(ImportRefused::class, $message);

    expect(ImportBatch::query()->exists())->toBeFalse()
        ->and(Storage::allFiles('imports'))->toBe([]);
})->with([
    'another format' => ['parts.ods', 'content', 'Upload an .xlsx or .csv file.'],
    'a broken workbook' => ['parts.xlsx', 'not a zip archive', 'The file could not be read as a spreadsheet.'],
    'an empty file' => ['parts.csv', "\n\n", 'The file is empty: it needs a header row naming its columns.'],
]);

it('cancels an open batch, audited', function () {
    $batch = uploadImport("Part number,Description,Unit of measure\n", $this->admin);

    app(PartImports::class)->cancel($batch, $this->admin);

    expect($batch->refresh()->status)->toBe(ImportStatus::Cancelled)
        ->and(AuditLog::query()->where('auditable_type', 'import_batch')->where('event', 'cancelled')->sole())
        ->user_id->toBe($this->admin->id)
        ->new_values->toBe(['filename' => 'parts.csv']);
});

it('cannot cancel a batch that is already imported or cancelled', function (ImportStatus $status) {
    $batch = ImportBatch::factory()->create(['status' => $status]);

    expect(fn () => app(PartImports::class)->cancel($batch, $this->admin))->toThrow(ImportRefused::class);
})->with([ImportStatus::Completed, ImportStatus::Cancelled]);

it('leaves no temporary copy of the file behind', function () {
    $copies = fn (): array => glob(sys_get_temp_dir().'/spims-import-*') ?: [];
    $before = $copies();

    uploadImport("Part number,Description,Unit of measure\nBRG-6204,Bearing,pcs\n", $this->admin);

    expect($copies())->toBe($before);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Imports/ImportUploadTest.php
```

Expected: FAIL with `Class "App\Domain\Imports\PartImports" not found`.

- [ ] **Step 3: Create the table, the status and the model**

Create `database/migrations/2026_09_20_100001_create_import_batches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One uploaded spare part file, from upload to import (PRD FR-SPIMS-005, §7.7).
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->text('filename');
            $table->text('format');
            $table->text('storage_path');
            $table->text('status')->default('pending');
            $table->jsonb('headers')->default('[]');
            $table->jsonb('column_map')->default('{}');
            $table->integer('rows_total')->default(0);
            $table->integer('rows_created')->default(0);
            $table->integer('rows_updated')->default(0);
            $table->integer('rows_failed')->default(0);
            $table->integer('opening_balances')->default(0);
            $table->jsonb('errors')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('preview')->default('[]');
            $table->foreignId('imported_by_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            alter table import_batches
              add constraint import_batches_status_check
                check (status in ('pending', 'validated', 'completed', 'failed', 'cancelled')),
              add constraint import_batches_format_check check (format in ('csv', 'xlsx'));

            create index import_batches_created_idx on import_batches (created_at desc);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
```

Create `app/Enums/ImportStatus.php`:

```php
<?php

namespace App\Enums;

/**
 * PRD §7.7: an import batch is Pending until checked, Validated when every row passed, Failed when
 * any did, Completed once imported, or Cancelled.
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for a check',
            self::Validated => 'Ready to import',
            self::Completed => 'Imported',
            self::Failed => 'Needs fixing',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the batch can still be checked, imported or cancelled.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Validated, self::Failed], true);
    }
}
```

Create `app/Models/ImportBatch.php`:

```php
<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded spare part file (PRD FR-SPIMS-005). Status, counts, errors and the preview are
 * written only by App\Domain\Imports\PartImports.
 *
 * @property int $id
 * @property string $filename
 * @property string $format
 * @property string $storage_path
 * @property ImportStatus $status
 * @property list<string> $headers
 * @property array<string, int> $column_map
 * @property int $rows_total
 * @property int $rows_created
 * @property int $rows_updated
 * @property int $rows_failed
 * @property int $opening_balances
 * @property list<array{row: int, message: string}> $errors
 * @property list<array{row: int, message: string}> $warnings
 * @property list<array{row: int, part_number: string, action: string, opening_quantity: string|null}> $preview
 * @property int $imported_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $completed_at
 * @property-read User $importedBy
 */
#[Fillable(['filename', 'format', 'storage_path', 'headers', 'column_map', 'imported_by_user_id'])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'headers' => 'array',
            'column_map' => 'array',
            'rows_total' => 'integer',
            'rows_created' => 'integer',
            'rows_updated' => 'integer',
            'rows_failed' => 'integer',
            'opening_balances' => 'integer',
            'errors' => 'array',
            'warnings' => 'array',
            'preview' => 'array',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
```

Create `database/factories/ImportBatchFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A batch whose file is never read, for tests about screens and states rather than rows.
 *
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => 'parts.csv',
            'format' => 'csv',
            'storage_path' => 'imports/'.fake()->uuid().'.csv',
            'status' => ImportStatus::Pending,
            'headers' => ['Part number', 'Description', 'Unit of measure'],
            'column_map' => ['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2],
            'imported_by_user_id' => User::factory(),
        ];
    }
}
```

In `app/Providers/AppServiceProvider.php`, add `use App\Models\ImportBatch;` and add this entry to the morph map:

```php
            'import_batch' => ImportBatch::class,
```

- [ ] **Step 4: Create the stored-file reader and the service**

Create `app/Domain/Imports/ImportFile.php`:

```php
<?php

namespace App\Domain\Imports;

use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * An import's stored file, read from whichever disk FILESYSTEM_DISK names (design §3.6 rule 3).
 * The spreadsheet reader needs a local file, so the stored file is copied to a temporary one, which
 * is removed once the rows have been read.
 */
final class ImportFile
{
    /**
     * @return Generator<int, list<string>>
     */
    public static function rows(string $storagePath, string $format): Generator
    {
        $path = self::localCopy($storagePath);

        try {
            yield from SpreadsheetFile::rows($path, $format);
        } finally {
            // Read to the end, the reader has closed the file and the copy can go. A caller that
            // stops part-way leaves it open, and Windows refuses to delete an open file, so the
            // copy is left to the system temporary directory rather than failing the import.
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * The file's first non-blank row, or null when it has none (PRD FR-SPIMS-005 step 3).
     *
     * @return list<string>|null
     */
    public static function headerRow(string $storagePath, string $format): ?array
    {
        $path = self::localCopy($storagePath);

        try {
            return SpreadsheetFile::headerRow($path, $format);
        } finally {
            // Reading stopped at the header row, and the reader's objects reference each other, so
            // reference counting alone does not close the file: the cycle collector has to run
            // first. Windows refuses to delete a file that is still open.
            gc_collect_cycles();

            unlink($path);
        }
    }

    private static function localCopy(string $storagePath): string
    {
        $stream = Storage::readStream($storagePath);

        if ($stream === null) {
            throw new RuntimeException("The import file [{$storagePath}] is missing.");
        }

        $path = sys_get_temp_dir().'/'.uniqid('spims-import-', true);
        file_put_contents($path, $stream);
        fclose($stream);

        return $path;
    }
}
```

Create `app/Domain/Imports/Exceptions/ImportRefused.php`:

```php
<?php

namespace App\Domain\Imports\Exceptions;

use DomainException;

/**
 * A step of an import that cannot go ahead. $field names the input the refusal is about: file,
 * mapping or batch.
 */
final class ImportRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function unsupportedFormat(): self
    {
        return new self('Upload an .xlsx or .csv file.', 'file');
    }

    public static function unreadable(): self
    {
        return new self('The file could not be read as a spreadsheet.', 'file');
    }

    public static function empty(): self
    {
        return new self('The file is empty: it needs a header row naming its columns.', 'file');
    }

    public static function notOpen(): self
    {
        return new self('This import has been imported, cancelled or started already, and can no longer be changed.', 'batch');
    }
}
```

Create `app/Domain/Imports/PartImports.php`:

```php
<?php

namespace App\Domain\Imports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Imports\Exceptions\ImportRefused;
use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A spare part import from upload to completion (PRD FR-SPIMS-005, §7.7): upload, check, confirm
 * or cancel.
 */
final readonly class PartImports
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Store an uploaded file as a new pending batch, with its header row and a first guess at which
     * import column each file column holds. Nothing is checked yet.
     *
     * @throws ImportRefused
     */
    public function upload(UploadedFile $file, User $actor): ImportBatch
    {
        $format = strtolower($file->getClientOriginalExtension());

        if (! in_array($format, SpreadsheetFile::FORMATS, true)) {
            throw ImportRefused::unsupportedFormat();
        }

        $storagePath = $file->store('imports');

        if ($storagePath === false) {
            throw new RuntimeException('The import file could not be stored.');
        }

        try {
            $headers = ImportFile::headerRow($storagePath, $format);
        } catch (Throwable) {
            Storage::delete($storagePath);

            throw ImportRefused::unreadable();
        }

        if ($headers === null) {
            Storage::delete($storagePath);

            throw ImportRefused::empty();
        }

        $batch = ImportBatch::query()->forceCreate([
            'filename' => $file->getClientOriginalName(),
            'format' => $format,
            'storage_path' => $storagePath,
            'status' => ImportStatus::Pending,
            'headers' => $headers,
            'column_map' => ImportColumn::guessMap($headers),
            'imported_by_user_id' => $actor->id,
        ]);

        // Read back the database defaults (counts, errors, preview).
        return $batch->refresh();
    }

    /**
     * Stop an import that has not been imported. Nothing is written, and the batch stays on record.
     *
     * @throws ImportRefused
     */
    public function cancel(ImportBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch, $actor): void {
            $locked = $this->lockOpen($batch);
            $locked->forceFill(['status' => ImportStatus::Cancelled])->save();

            $this->audit->record('cancelled', $locked, null, ['filename' => $locked->filename], $actor);
        });
    }

    /**
     * The batch, locked for this transaction, provided it can still change: open, and not yet
     * confirmed for import.
     *
     * @throws ImportRefused
     */
    private function lockOpen(ImportBatch $batch): ImportBatch
    {
        $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->isOpen() || $locked->confirmed_at !== null) {
            throw ImportRefused::notOpen();
        }

        return $locked;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan migrate
php artisan test tests/Feature/Imports/ImportUploadTest.php tests/Feature/SchemaConventionsTest.php
```

Expected: PASS. `ImportUploadTest` contributes 9 tests, and `SchemaConventionsTest` accepts the new table.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_20_100001_create_import_batches_table.php app/Enums/ImportStatus.php app/Models/ImportBatch.php database/factories/ImportBatchFactory.php app/Domain/Imports/ImportFile.php app/Domain/Imports/PartImports.php app/Domain/Imports/Exceptions/ImportRefused.php app/Providers/AppServiceProvider.php tests/Pest.php tests/Feature/Imports/ImportUploadTest.php
git commit -m "Store uploaded spare part files as import batches"
```

---

## Task 3: Checking a file: column mapping, validation and the preview

**Files:**
- Create: `app/Domain/Imports/ImportRow.php`, `app/Domain/Imports/ImportPreview.php`, `app/Domain/Imports/ImportValidator.php`, `tests/Feature/Imports/ImportCheckTest.php`
- Modify: `app/Domain/Imports/PartImports.php`, `app/Domain/Imports/Exceptions/ImportRefused.php`

**Interfaces:**
- Consumes:
  - `ImportColumn`, `ImportFile` and `PartImports` (Tasks 1–2).
  - `StockLevels::warnings()` and `InstallSettings::isReadyForStockTransactions()`.
  - The models `Part`, `StockMovement`, `StorageLocation` and `Equipment`.
- Produces:
  - `ImportRow`, a checked row ready to write: `rowNumber`, `partNumber`, `partId` (null to create), `details`, `levels`, `equipmentIds` and `openingQuantity`.
  - `ImportPreview`, with fields `rows`, `rowsTotal`, `errors` and `warnings`, and methods `isValid()`, `creates()`, `updates()`, `openingBalances()`, `failedRows()` and `sample()`.
  - `ImportValidator::validate(ImportBatch $batch, User $actor): ImportPreview`, which writes nothing.
  - `PartImports::check(ImportBatch $batch, array<string, int|null> $columnMap, User $actor): ImportPreview`, which saves the mapping, validates, and records the result on the batch.
  - A private `PartImports::recordPreview()`, which Task 4 reuses.

Errors carry their spreadsheet row number, with `0` meaning the whole file. A row with any error is left out of `rows`, and a batch with any error is Failed and cannot be imported (PRD FR-SPIMS-005 blocks confirmation until a corrected file is uploaded).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Imports/ImportCheckTest.php`:

```php
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
            '0: Column “Minimum level”: only planners and System Administrators may import stock levels.',
            '0: Column “Opening quantity”: only a System Administrator may import opening quantities.',
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
        ->toThrow(ImportRefused::class, 'Column “Item” is matched to more than one field.');

    $imports->check($batch, ['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2], $this->admin);

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Validated)
        ->column_map->toEqual(['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2]);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Imports/ImportCheckTest.php
```

Expected: FAIL with `Call to undefined method App\Domain\Imports\PartImports::check()`.

- [ ] **Step 3: Create the row and preview values**

Create `app/Domain/Imports/ImportRow.php`:

```php
<?php

namespace App\Domain\Imports;

use App\Enums\Criticality;

/**
 * One row of an import that passed every check, in the form the writer applies.
 */
final readonly class ImportRow
{
    /**
     * @param  int|null  $partId  the existing part this row updates, or null to create one
     * @param  array{description?: string, unit_of_measure?: string, default_location_id?: int}  $details  the part fields the row sets
     * @param  array{reorder_point: string, min_level: string, critical_level: string, max_level: string|null, criticality: Criticality, lead_time_days: int|null}|null  $levels  the part's complete stock levels with the row's values applied, or null when the row sets none
     * @param  list<int>|null  $equipmentIds  the equipment the part is used on, or null when the row names none
     * @param  string|null  $openingQuantity  a positive quantity to three decimals, or null for no opening balance
     */
    public function __construct(
        public int $rowNumber,
        public string $partNumber,
        public ?int $partId,
        public array $details,
        public ?array $levels,
        public ?array $equipmentIds,
        public ?string $openingQuantity,
    ) {}

    public function creates(): bool
    {
        return $this->partId === null;
    }
}
```

Create `app/Domain/Imports/ImportPreview.php`:

```php
<?php

namespace App\Domain\Imports;

/**
 * The outcome of checking an import: the rows that passed, and every error and warning with its
 * spreadsheet row number (0 for the whole file).
 */
final readonly class ImportPreview
{
    /** How many passing rows the batch keeps for the preview table. */
    public const int SAMPLE_SIZE = 100;

    /**
     * @param  list<ImportRow>  $rows
     * @param  list<array{row: int, message: string}>  $errors
     * @param  list<array{row: int, message: string}>  $warnings
     */
    public function __construct(
        public array $rows,
        public int $rowsTotal,
        public array $errors,
        public array $warnings,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [] && $this->rows !== [];
    }

    public function creates(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row): bool => $row->creates()));
    }

    public function updates(): int
    {
        return count($this->rows) - $this->creates();
    }

    public function openingBalances(): int
    {
        return count(array_filter($this->rows, fn (ImportRow $row): bool => $row->openingQuantity !== null));
    }

    /**
     * The number of spreadsheet rows with at least one error.
     */
    public function failedRows(): int
    {
        return count(array_unique(array_filter(array_column($this->errors, 'row'), fn (int $row): bool => $row > 0)));
    }

    /**
     * @return list<array{row: int, part_number: string, action: string, opening_quantity: string|null}>
     */
    public function sample(): array
    {
        return array_map(fn (ImportRow $row): array => [
            'row' => $row->rowNumber,
            'part_number' => $row->partNumber,
            'action' => $row->creates() ? 'create' : 'update',
            'opening_quantity' => $row->openingQuantity,
        ], array_slice($this->rows, 0, self::SAMPLE_SIZE));
    }
}
```

- [ ] **Step 4: Create the validator**

Create `app/Domain/Imports/ImportValidator.php`:

```php
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

    public function __construct(
        private StockLevels $stockLevels,
        private InstallSettings $settings,
    ) {}

    public function validate(ImportBatch $batch, User $actor): ImportPreview
    {
        $rows = iterator_to_array(ImportFile::rows($batch->storage_path, $batch->format));
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
                    : 'only planners and System Administrators may import stock levels.';

                $errors[] = ['row' => 0, 'message' => "Column “{$column->label()}”: {$who}"];
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
```

- [ ] **Step 5: Add checking to the service**

Add these refusals to `app/Domain/Imports/Exceptions/ImportRefused.php`:

```php
    /**
     * @param  list<string>  $labels
     */
    public static function requiredColumnsMissing(array $labels): self
    {
        return new self('Match these required fields to a column of the file: '.implode(', ', $labels).'.', 'mapping');
    }

    public static function columnUsedTwice(string $header): self
    {
        return new self("Column “{$header}” is matched to more than one field.", 'mapping');
    }
```

In `app/Domain/Imports/PartImports.php`:

1. Change the constructor to:

```php
    public function __construct(
        private AuditLogger $audit,
        private ImportValidator $validator,
    ) {}
```

2. Add these methods after `upload()`:

```php
    /**
     * Save which file column holds each import column, then check every row without writing
     * anything (PRD FR-SPIMS-005 steps 3–5). The batch becomes Validated when every row passes and
     * Failed when any row does; a Failed batch can be checked again with a corrected mapping.
     *
     * @param  array<string, int|null>  $columnMap  import column value => file column index, or null when not in the file
     *
     * @throws ImportRefused
     */
    public function check(ImportBatch $batch, array $columnMap, User $actor): ImportPreview
    {
        $map = $this->validMap($batch, $columnMap);

        return DB::transaction(function () use ($batch, $map, $actor): ImportPreview {
            $locked = $this->lockOpen($batch);
            $locked->forceFill(['column_map' => $map])->save();

            $preview = $this->validator->validate($locked, $actor);
            $this->recordPreview($locked, $preview);

            return $preview;
        });
    }

    private function recordPreview(ImportBatch $batch, ImportPreview $preview): void
    {
        $batch->forceFill([
            'status' => $preview->isValid() ? ImportStatus::Validated : ImportStatus::Failed,
            'rows_total' => $preview->rowsTotal,
            'rows_created' => $preview->creates(),
            'rows_updated' => $preview->updates(),
            'rows_failed' => $preview->failedRows(),
            'opening_balances' => $preview->openingBalances(),
            'errors' => $preview->errors,
            'warnings' => $preview->warnings,
            'preview' => $preview->sample(),
        ])->save();
    }

    /**
     * The mapping with unknown fields and out-of-range columns dropped, once every required field
     * has a column and no column serves two fields.
     *
     * @param  array<string, int|null>  $columnMap
     * @return array<string, int>
     *
     * @throws ImportRefused
     */
    private function validMap(ImportBatch $batch, array $columnMap): array
    {
        $map = [];

        foreach (ImportColumn::cases() as $column) {
            $index = $columnMap[$column->value] ?? null;

            if ($index !== null && array_key_exists($index, $batch->headers)) {
                $map[$column->value] = $index;
            }
        }

        $missing = array_filter(
            ImportColumn::cases(),
            fn (ImportColumn $column): bool => $column->isRequired() && ! isset($map[$column->value]),
        );

        if ($missing !== []) {
            throw ImportRefused::requiredColumnsMissing(array_values(array_map(fn (ImportColumn $column): string => $column->label(), $missing)));
        }

        foreach (array_count_values($map) as $index => $uses) {
            if ($uses > 1) {
                throw ImportRefused::columnUsedTwice($batch->headers[$index]);
            }
        }

        return $map;
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Imports
```

Expected: PASS. `ImportCheckTest` contributes 10 tests.

- [ ] **Step 7: Run the full check and commit**

```bash
composer test
git add app/Domain/Imports/ImportRow.php app/Domain/Imports/ImportPreview.php app/Domain/Imports/ImportValidator.php app/Domain/Imports/PartImports.php app/Domain/Imports/Exceptions/ImportRefused.php tests/Feature/Imports/ImportCheckTest.php
git commit -m "Check every row of a spare part import before anything is written"
```

---

## Task 4: Confirming an import

**Files:**
- Create: `app/Domain/Imports/ImportWriter.php`, `app/Jobs/ConfirmImport.php`, `tests/Feature/Imports/ImportConfirmationTest.php`
- Modify: `app/Domain/Imports/PartImports.php`, `app/Domain/Imports/Exceptions/ImportRefused.php`, `app/Models/ImportBatch.php`

**Interfaces:**
- Consumes:
  - `PartRecords::create()` and `update()`, `StockLevels::update()` (Plan 2).
  - `StockLedger::complete()`, `StockTransaction`, `TransactionType::Opening` (Plan 1).
  - `ImportPreview`, `ImportRow`, `PartImports::check()` and `recordPreview()` (Task 3).
- Produces:
  - `ImportWriter::write(ImportPreview $preview, User $actor): array{created: int, updated: int, opening_balances: int}`, with a constructor `int $linesPerTransaction = 200`.
  - `PartImports` methods:
    - `requestConfirmation(ImportBatch $batch, User $actor): void`, which records `confirmed_at` and queues `ConfirmImport`.
    - `confirm(ImportBatch $batch, User $actor): void`, which is run by the job.
  - `ConfirmImport` with public `$batchId` and `$actorId`.
  - `ImportBatch::isImporting(): bool`, true from confirmation until the job ends.
  - The `imported` audit event on `import_batch`.

A failed import leaves its batch Failed with `confirmed_at` cleared. The user can then check it again with a corrected mapping, or upload a corrected file.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Imports/ImportConfirmationTest.php`:

```php
<?php

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\ImportWriter;
use App\Domain\Imports\PartImports;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\Exceptions\PartChangeRefused;
use App\Enums\Criticality;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Jobs\ConfirmImport;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    configureInstall();
    $this->admin = userWithRoles(Role::Admin);
    $this->checked = function (string $csv) {
        $batch = uploadImport($csv, $this->admin);
        app(PartImports::class)->check($batch, $batch->column_map, $this->admin);

        return $batch->refresh();
    };
});

it('creates and updates parts, links equipment and records opening balances in the ledger', function () {
    $location = StorageLocation::factory()->create(['code' => 'A-01']);
    $cnc = Equipment::factory()->create(['code' => 'CNC-01']);
    $existing = Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Bearing']);
    $batch = ($this->checked)(
        "Part number,Description,Unit of measure,Storage location code,Equipment codes,Criticality,Minimum level,Reorder point,Opening quantity\n".
        "BRG-6204,Deep groove ball bearing,pcs,A-01,CNC-01,critical,2,5,12\n".
        "OIL-68,Hydraulic oil,L,,,,,,20.5\n",
    );

    // The sync queue in tests runs ConfirmImport at once.
    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    $oil = Part::query()->where('part_number', 'OIL-68')->sole();

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Completed)
        ->rows_created->toBe(1)
        ->rows_updated->toBe(1)
        ->opening_balances->toBe(2)
        ->completed_at->not->toBeNull()
        ->and($existing->refresh())
        ->description->toBe('Deep groove ball bearing')
        ->default_location_id->toBe($location->id)
        ->criticality->toBe(Criticality::Critical)
        ->min_level->toBe('2.000')
        ->reorder_point->toBe('5.000')
        ->and($existing->equipment()->get()->modelKeys())->toBe([$cnc->id])
        ->and(onHand($existing))->toBe('12.000')
        ->and(onHand($oil))->toBe('20.500')
        ->and(StockMovement::query()->where('movement_type', 'opening')->count())->toBe(2)
        ->and(app(LedgerVerifier::class)->verify())->toBe([])
        ->and(AuditLog::query()->where('auditable_type', 'import_batch')->where('event', 'imported')->sole())
        ->user_id->toBe($this->admin->id);
});

it('posts opening balances in transactions of at most the configured number of lines', function () {
    app()->when(ImportWriter::class)->needs('$linesPerTransaction')->give(2);
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nP-1,One,pcs,1\nP-2,Two,pcs,2\nP-3,Three,pcs,3\n");

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    expect(StockTransaction::query()->where('type', 'opening')->withCount('lines')->orderBy('id')->pluck('lines_count')->all())
        ->toBe([2, 1])
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('checks the file again at confirmation, and writes nothing when it no longer passes', function () {
    $part = Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Bearing']);
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nBRG-6204,Deep groove ball bearing,pcs,12\n");

    // Stock is recorded for the part between the preview and the confirmation.
    $opening = StockTransaction::factory()->opening()->withLines([[$part, '3']])->create();
    app(StockLedger::class)->complete($opening, $opening->custodian);

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Failed)
        ->confirmed_at->toBeNull()
        ->errors->toBe([['row' => 2, 'message' => 'BRG-6204 already has stock movements, so its opening balance cannot be imported.']])
        ->and($part->refresh()->description)->toBe('Bearing')
        ->and(onHand($part))->toBe('3.000');
});

it('writes nothing when the import fails part-way, and records why', function (Closure $failure, string $message) {
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nP-1,One,pcs,1\nP-2,Two,pcs,2\nP-3,Three,pcs,3\n");
    $created = 0;
    Part::created(function () use (&$created, $failure): void {
        if (++$created === 2) {
            throw $failure();
        }
    });

    // Not Throwable: Pest reads an interface name as an expected message, not as a class.
    expect(fn () => app(PartImports::class)->requestConfirmation($batch, $this->admin))->toThrow(Exception::class);

    expect(Part::query()->exists())->toBeFalse()
        ->and(StockTransaction::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'imported')->exists())->toBeFalse()
        ->and($batch->refresh())
        ->status->toBe(ImportStatus::Failed)
        ->confirmed_at->toBeNull()
        ->errors->toBe([['row' => 0, 'message' => $message]]);
})->with([
    'a refusal, which explains itself' => [
        fn () => PartChangeRefused::inactiveLocation(),
        'The import stopped and nothing was saved: Choose an Active storage location.',
    ],
    'a part saved elsewhere at the same moment' => [
        fn () => new UniqueConstraintViolationException('pgsql', 'insert into "parts" …', [], new Exception('duplicate key value violates unique constraint "parts_part_number_key"')),
        'The import stopped and nothing was saved: a spare part in the file was created elsewhere at the same moment. Check the file again, and that row will update it instead.',
    ],
    'an unexpected error, which may hold SQL' => [
        fn () => new RuntimeException('SQLSTATE[08006]: connection failure during insert into "parts" …'),
        'The import stopped and nothing was saved: an unexpected error, recorded in the application log.',
    ],
]);

it('imports a batch only after it passed its check, and only once', function () {
    $imports = app(PartImports::class);
    $batch = uploadImport("Part number,Description,Unit of measure\nP-1,One,pcs\n", $this->admin);

    expect(fn () => $imports->requestConfirmation($batch, $this->admin))
        ->toThrow(ImportRefused::class, 'Check the file, and fix every error, before importing it.');

    $imports->check($batch, $batch->column_map, $this->admin);
    $imports->requestConfirmation($batch, $this->admin);

    expect($batch->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(fn () => $imports->requestConfirmation($batch, $this->admin))->toThrow(ImportRefused::class)
        ->and(fn () => $imports->cancel($batch, $this->admin))->toThrow(ImportRefused::class)
        ->and(Part::query()->count())->toBe(1);
});

it('queues the import and marks the batch as started', function () {
    Queue::fake();
    $batch = ($this->checked)("Part number,Description,Unit of measure\nP-1,One,pcs\n");

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    Queue::assertPushed(ConfirmImport::class, fn (ConfirmImport $job): bool => $job->batchId === $batch->id && $job->actorId === $this->admin->id);

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Validated)
        ->confirmed_at->not->toBeNull()
        ->and(Part::query()->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Imports/ImportConfirmationTest.php
```

Expected: FAIL with `Class "App\Domain\Imports\ImportWriter" not found`.

- [ ] **Step 3: Create the writer and the job**

Create `app/Domain/Imports/ImportWriter.php`:

```php
<?php

namespace App\Domain\Imports;

use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\PartRecords;
use App\Domain\Parts\StockLevels;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Writes the rows of a checked import through the services the screens use: each part change is
 * audited as if made on its screen, and each opening balance is an opening movement posted by
 * StockLedger (design §4.1). Runs inside the caller's database transaction.
 */
final readonly class ImportWriter
{
    /**
     * @param  positive-int  $linesPerTransaction
     */
    public function __construct(
        private PartRecords $records,
        private StockLevels $stockLevels,
        private StockLedger $ledger,
        private int $linesPerTransaction = 200,
    ) {}

    /**
     * @return array{created: int, updated: int, opening_balances: int}
     */
    public function write(ImportPreview $preview, User $actor): array
    {
        $openingBalances = [];

        foreach ($preview->rows as $row) {
            $part = $row->creates() ? $this->create($row, $actor) : $this->update($row, $actor);

            if ($row->levels !== null) {
                // refresh(): a part just created holds only the attributes it was created with,
                // and the stock level audit snapshot reads the columns the database defaulted.
                $this->stockLevels->update($part->refresh(), $row->levels, $actor);
            }

            if ($row->openingQuantity !== null) {
                $openingBalances[] = [$part->id, $row->openingQuantity];
            }
        }

        foreach (array_chunk($openingBalances, $this->linesPerTransaction) as $lines) {
            $this->postOpeningBalances($lines, $actor);
        }

        return [
            'created' => $preview->creates(),
            'updated' => $preview->updates(),
            'opening_balances' => count($openingBalances),
        ];
    }

    private function create(ImportRow $row, User $actor): Part
    {
        return $this->records->create([
            'part_number' => $row->partNumber,
            'description' => $row->details['description'] ?? '',
            'unit_of_measure' => $row->details['unit_of_measure'] ?? '',
            'default_location_id' => $row->details['default_location_id'] ?? null,
        ], $row->equipmentIds ?? [], $actor);
    }

    private function update(ImportRow $row, User $actor): Part
    {
        $part = Part::query()->findOrFail($row->partId);

        $this->records->update($part, $row->details, $row->equipmentIds, $actor);

        return $part;
    }

    /**
     * One opening transaction of up to linesPerTransaction lines, so each posting locks a bounded
     * number of stock rows in one ordered statement (design §5.2).
     *
     * @param  list<array{0: int, 1: string}>  $lines  part id and positive quantity
     */
    private function postOpeningBalances(array $lines, User $actor): void
    {
        $transaction = StockTransaction::query()->create([
            'type' => TransactionType::Opening,
            'custodian_user_id' => $actor->id,
            'occurred_at' => CarbonImmutable::now(),
            'is_backdated' => false,
        ]);

        foreach ($lines as $index => [$partId, $quantity]) {
            $transaction->lines()->create([
                'line_no' => $index + 1,
                'part_id' => $partId,
                'quantity' => $quantity,
                'direction' => 1,
            ]);
        }

        $this->ledger->complete($transaction, $actor);
    }
}
```

Create `app/Jobs/ConfirmImport.php`:

```php
<?php

namespace App\Jobs;

use App\Domain\Imports\PartImports;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a confirmed spare part import (PRD FR-SPIMS-005). Queued, because writing thousands of
 * audited parts can take minutes against a remote database; the review screen polls until it ends.
 */
class ConfirmImport implements ShouldQueue
{
    use Queueable;

    /** One attempt: an import is all or nothing, so a failed one goes back to a person, not a retry. */
    public int $tries = 1;

    /** Seconds. */
    public int $timeout = 1800;

    public function __construct(
        public int $batchId,
        public int $actorId,
    ) {}

    public function handle(PartImports $imports): void
    {
        $imports->confirm(
            ImportBatch::query()->findOrFail($this->batchId),
            User::query()->findOrFail($this->actorId),
        );
    }
}
```

- [ ] **Step 4: Add confirmation to the service**

Add this method to `app/Models/ImportBatch.php`, after `casts()`:

```php
    /**
     * Confirmed and waiting for, or being written by, the ConfirmImport job.
     */
    public function isImporting(): bool
    {
        return $this->status === ImportStatus::Validated && $this->confirmed_at !== null;
    }
```

Add this refusal to `app/Domain/Imports/Exceptions/ImportRefused.php`:

```php
    public static function notValidated(): self
    {
        return new self('Check the file, and fix every error, before importing it.', 'batch');
    }
```

In `app/Domain/Imports/PartImports.php`:

1. Add `use App\Domain\Ledger\Exceptions\StockBusy;`, `use App\Jobs\ConfirmImport;`, `use DomainException;` and `use Illuminate\Database\UniqueConstraintViolationException;` to the imports.
2. Add `private ImportWriter $writer,` to the constructor, after `$validator`.
3. Add these methods after `check()`:

```php
    /**
     * Queue the import of a checked batch (PRD FR-SPIMS-005 step 6). The confirmation is recorded
     * first, so the screen shows the import running and a second click cannot queue it twice.
     *
     * @throws ImportRefused
     */
    public function requestConfirmation(ImportBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = $this->lockOpen($batch);

            if ($locked->status !== ImportStatus::Validated) {
                throw ImportRefused::notValidated();
            }

            $locked->forceFill(['confirmed_at' => now()])->save();
        });

        ConfirmImport::dispatch($batch->id, $actor->id);
    }

    /**
     * Write a confirmed batch: every part and every opening balance, or nothing (PRD FR-SPIMS-005
     * steps 7–9). The file is checked again first, because parts or stock may have changed since
     * the preview. On any failure the batch is left Failed with the reason, and can be checked again.
     */
    public function confirm(ImportBatch $batch, User $actor): void
    {
        try {
            DB::transaction(function () use ($batch, $actor): void {
                $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

                // Already written, cancelled or never confirmed: a repeated or stray job does nothing.
                if (! $locked->isImporting()) {
                    return;
                }

                $preview = $this->validator->validate($locked, $actor);

                if (! $preview->isValid()) {
                    $this->recordPreview($locked, $preview);
                    $locked->forceFill(['confirmed_at' => null])->save();

                    return;
                }

                $written = $this->writer->write($preview, $actor);

                $locked->forceFill([
                    'status' => ImportStatus::Completed,
                    'rows_total' => $preview->rowsTotal,
                    'rows_created' => $written['created'],
                    'rows_updated' => $written['updated'],
                    'opening_balances' => $written['opening_balances'],
                    'completed_at' => now(),
                ])->save();

                $this->audit->record('imported', $locked, null, [
                    'filename' => $locked->filename,
                    'rows_total' => $preview->rowsTotal,
                    'rows_created' => $written['created'],
                    'rows_updated' => $written['updated'],
                    'opening_balances' => $written['opening_balances'],
                ], $actor);
            });
        } catch (Throwable $exception) {
            $this->markFailed($batch, $exception);

            throw $exception;
        }
    }
```

4. Add this method after `recordPreview()`:

```php
    /**
     * Record why an import stopped. Its transaction has rolled back, so this is saved on its own.
     * A refusal from a domain service explains itself; any other message may hold SQL, so it stays
     * in the application log, where the queue worker reports it.
     */
    private function markFailed(ImportBatch $batch, Throwable $exception): void
    {
        $reason = match (true) {
            $exception instanceof DomainException, $exception instanceof StockBusy => $exception->getMessage(),
            // PartRecords checks part numbers before saving, so only a part saved at the same
            // moment by a screen or another import reaches the unique index (Plan 2 carried note).
            $exception instanceof UniqueConstraintViolationException => 'a spare part in the file was created elsewhere at the same moment. Check the file again, and that row will update it instead.',
            default => 'an unexpected error, recorded in the application log.',
        };

        ImportBatch::query()->findOrFail($batch->id)->forceFill([
            'status' => ImportStatus::Failed,
            'confirmed_at' => null,
            'errors' => [['row' => 0, 'message' => "The import stopped and nothing was saved: {$reason}"]],
        ])->save();
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Imports
```

Expected: PASS. `ImportConfirmationTest` contributes 8 tests: 6 cases, one of them run three times by its dataset.

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add app/Domain/Imports/ImportWriter.php app/Jobs/ConfirmImport.php app/Domain/Imports/PartImports.php app/Domain/Imports/Exceptions/ImportRefused.php app/Models/ImportBatch.php tests/Feature/Imports/ImportConfirmationTest.php
git commit -m "Import checked spare parts and opening balances in one all-or-nothing job"
```

---

## Task 5: Import screens

**Files:**
- Create:
  - `app/Http/Controllers/ImportTemplateController.php`
  - `app/Livewire/Imports/Index.php`, `app/Livewire/Imports/Upload.php`, `app/Livewire/Imports/Review.php`
  - `resources/views/livewire/imports.blade.php`, `resources/views/livewire/imports/upload.blade.php`, `resources/views/livewire/imports/review.blade.php`
  - `tests/Feature/Imports/ImportScreensTest.php`
- Modify: `routes/spims.php`, `app/Support/Navigation.php`, `app/Enums/ImportStatus.php`

**Interfaces:**
- Consumes:
  - `PartImports::upload()`, `check()`, `requestConfirmation()` and `cancel()`, and `ImportRefused` with its `$field` (Tasks 2–4).
  - `ImportColumn` (Task 1), `ImportBatch::isImporting()` (Task 4).
  - `ActsAsSignedInUser`, `InstallSettings::timezone()`, and `Permission::PartsImport`, held by Supervisor and System Administrator.
- Produces:
  - Routes:
    - `imports.index` (`/imports`)
    - `imports.create` (`/imports/create`)
    - `imports.template` (`/imports/template`)
    - `imports.show` (`/imports/{batch}`)
  - Components:
    - `Imports\Upload`, with property `file` and action `upload`.
    - `Imports\Review`, with properties `batchId` and `columnMap` and actions `check`, `import` and `cancel`.
  - The menu item "Spare part imports" in the Master data group.
  - `ImportStatus::color(): string`.

The screens follow Plan 2's pattern:
- `authorize()` in `mount()` and in every action.
- Refusals are shown as field errors.
- Nothing on these screens decides a rule: `PartImports` does.

The review screen is the whole FR-SPIMS-005 flow after upload:
- match columns
- check
- read the preview or the errors
- import or cancel

While the job runs, the screen polls every two seconds.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Imports/ImportScreensTest.php`:

```php
<?php

use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Livewire\Imports\Index;
use App\Livewire\Imports\Review;
use App\Livewire\Imports\Upload;
use App\Models\ImportBatch;
use App\Models\Part;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake();
    configureInstall();
});

it('lets supervisors and administrators import spare parts', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $batch = ImportBatch::factory()->create();

    $this->actingAs($user)->get(route('imports.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('imports.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('imports.show', $batch))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('imports.template'))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Spare part imports', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-S-A'));

it('downloads the import template', function () {
    $this->actingAs(userWithRoles(Role::Supervisor))
        ->get(route('imports.template'))
        ->assertOk()
        ->assertDownload('spims-spare-part-import-template.xlsx');
});

it('uploads a file, matches its columns, checks it and imports it', function () {
    $admin = userWithRoles(Role::Admin);

    $upload = Livewire::actingAs($admin)->test(Upload::class)
        ->set('file', UploadedFile::fake()->createWithContent('parts.csv', "Item code,Item description,UOM,Qty\nBRG-6204,Deep groove ball bearing,pcs,12\n"))
        ->call('upload')
        ->assertHasNoErrors();

    $batch = ImportBatch::query()->sole();
    $upload->assertRedirect(route('imports.show', $batch));

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->assertSet('columnMap.part_number', '0')
        ->assertSet('columnMap.opening_quantity', '3')
        ->assertSet('columnMap.location_code', '')
        ->call('check')
        ->assertHasNoErrors()
        ->assertSee('Spare parts to create: 1')
        ->assertSee('Opening balances to record: 1')
        // The sync queue in tests runs the import at once.
        ->call('import')
        ->assertSee('1 created, 0 updated, 1 opening balances recorded.');

    expect($batch->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(onHand(Part::query()->where('part_number', 'BRG-6204')->sole()))->toBe('12.000');
});

it('refuses a file that is not an xlsx or csv file', function () {
    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Upload::class)
        ->set('file', UploadedFile::fake()->createWithContent('parts.ods', 'content'))
        ->call('upload')
        ->assertHasErrors('file')
        ->assertSee('Upload an .xlsx or .csv file.');

    expect(ImportBatch::query()->exists())->toBeFalse();
});

it('asks for a column for every required field before checking', function () {
    $admin = userWithRoles(Role::Admin);
    $batch = uploadImport("Item,Name,Unit\nBRG-6204,Bearing,pcs\n", $admin);

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->assertSet('columnMap.part_number', '')
        ->call('check')
        ->assertHasErrors('columnMap')
        ->assertSee('Match these required fields to a column of the file: Part number.')
        ->set('columnMap.part_number', '0')
        ->call('check')
        ->assertHasNoErrors();

    expect($batch->refresh()->status)->toBe(ImportStatus::Validated);
});

it('lists each error with its row number and blocks the import', function () {
    $admin = userWithRoles(Role::Admin);
    $batch = uploadImport("Part number,Description,Unit of measure\nBRG-6204,,pcs\n", $admin);

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->call('check')
        ->assertSee('Row 2: Description is required.')
        ->assertDontSeeHtml('wire:click="import"')
        ->call('import')
        ->assertHasErrors('batch');

    expect(Part::query()->exists())->toBeFalse();
});

it('shows a running import and refreshes until it ends', function () {
    Queue::fake();
    $admin = userWithRoles(Role::Admin);
    $batch = uploadImport("Part number,Description,Unit of measure\nP-1,One,pcs\n", $admin);

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->call('check')
        ->call('import')
        ->assertSee('The import is running.')
        ->assertSeeHtml('wire:poll.2s');
});

it('cancels an import at the preview, writing nothing', function () {
    $supervisor = userWithRoles(Role::Supervisor);
    $batch = uploadImport("Part number,Description,Unit of measure\nP-1,One,pcs\n", $supervisor);

    Livewire::actingAs($supervisor)->test(Review::class, ['batch' => $batch])
        ->call('check')
        ->call('cancel')
        ->assertRedirect(route('imports.index'));

    expect($batch->refresh()->status)->toBe(ImportStatus::Cancelled)
        ->and(Part::query()->exists())->toBeFalse();
});

it('lists imports newest first with their status', function () {
    ImportBatch::factory()->create(['filename' => 'first.csv', 'status' => ImportStatus::Completed]);
    ImportBatch::factory()->create(['filename' => 'second.xlsx', 'status' => ImportStatus::Failed]);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Index::class)
        ->assertSeeInOrder(['second.xlsx', 'Needs fixing', 'first.csv', 'Imported']);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Feature/Imports/ImportScreensTest.php
```

Expected: FAIL with `Route [imports.index] not defined.`

- [ ] **Step 3: Add the routes, the menu item and the status colour**

In `routes/spims.php`:

1. Add `use App\Http\Controllers\ImportTemplateController;` and `use App\Livewire\Imports;` to the imports.
2. Add these routes after the parts routes:

```php
    Route::livewire('imports', Imports\Index::class)->name('imports.index');
    Route::livewire('imports/create', Imports\Upload::class)->name('imports.create');
    Route::get('imports/template', ImportTemplateController::class)->name('imports.template');
    Route::livewire('imports/{batch}', Imports\Review::class)->whereNumber('batch')->name('imports.show');
```

In `app/Support/Navigation.php`, add this item after the Personnel item:

```php
        ['group' => 'Master data', 'label' => 'Spare part imports', 'icon' => 'arrow-up-tray', 'route' => 'imports.index', 'active' => 'imports.*', 'permission' => Permission::PartsImport],
```

Add this method to `app/Enums/ImportStatus.php`, after `label()`:

```php
    /**
     * The badge colour the screens show this status in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Cancelled => 'zinc',
            self::Validated => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
        };
    }
```

- [ ] **Step 4: Create the template download and the list**

Create `app/Http/Controllers/ImportTemplateController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Domain\Imports\ImportTemplate;
use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The spare part import template (PRD FR-SPIMS-005 step 1). It is written for each download, so it
 * always names the columns this version imports.
 */
final class ImportTemplateController
{
    public function __invoke(): BinaryFileResponse
    {
        Gate::authorize(Permission::PartsImport);

        $path = sys_get_temp_dir().'/'.uniqid('spims-template-', true).'.xlsx';
        ImportTemplate::write($path);

        return response()->download($path, 'spims-spare-part-import-template.xlsx')->deleteFileAfterSend();
    }
}
```

Create `app/Livewire/Imports/Index.php`:

```php
<?php

namespace App\Livewire\Imports;

use App\Enums\Permission;
use App\Models\ImportBatch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Spare part imports')]
class Index extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize(Permission::PartsImport);
    }

    /**
     * @return LengthAwarePaginator<int, ImportBatch>
     */
    #[Computed]
    public function batches(): LengthAwarePaginator
    {
        return ImportBatch::query()->with('importedBy')->latest('id')->paginate(25);
    }
}
```

Create `resources/views/livewire/imports.blade.php`:

```blade
@php($timezone = app(\App\Domain\Settings\InstallSettings::class)->timezone())

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Spare part imports') }}</flux:heading>

        <div class="flex gap-2">
            <flux:button icon="arrow-down-tray" :href="route('imports.template')">{{ __('Download template') }}</flux:button>
            <flux:button variant="primary" icon="arrow-up-tray" :href="route('imports.create')" wire:navigate>{{ __('New import') }}</flux:button>
        </div>
    </div>

    <flux:table :paginate="$this->batches">
        <flux:table.columns>
            <flux:table.column>{{ __('File') }}</flux:table.column>
            <flux:table.column>{{ __('Uploaded') }}</flux:table.column>
            <flux:table.column>{{ __('By') }}</flux:table.column>
            <flux:table.column>{{ __('Rows') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->batches as $batch)
                <flux:table.row :key="$batch->id">
                    <flux:table.cell>
                        <flux:link :href="route('imports.show', $batch)" wire:navigate>{{ $batch->filename }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>{{ $batch->created_at->setTimezone($timezone)->format('Y-m-d H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $batch->importedBy->name }}</flux:table.cell>
                    <flux:table.cell>{{ $batch->rows_total }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$batch->isImporting() ? 'blue' : $batch->status->color()">
                            {{ $batch->isImporting() ? __('Importing') : __($batch->status->label()) }}
                        </flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</section>
```

- [ ] **Step 5: Create the upload screen**

Create `app/Livewire/Imports/Upload.php`:

```php
<?php

namespace App\Livewire\Imports;

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\PartImports;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('New spare part import')]
class Upload extends Component
{
    use ActsAsSignedInUser, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(): void
    {
        $this->authorize(Permission::PartsImport);
    }

    public function upload(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);

        $this->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $this->file;

        // validate() has refused a missing file; this tells the type checker so.
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $batch = $imports->upload($file, $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError('file', $refused->getMessage());

            return;
        }

        $this->redirectRoute('imports.show', ['batch' => $batch], navigate: true);
    }
}
```

Create `resources/views/livewire/imports/upload.blade.php`:

```blade
<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1" class="mb-2">{{ __('New spare part import') }}</flux:heading>
    <flux:text class="mb-6">
        {{ __('Upload an .xlsx or .csv file with a header row naming its columns and one spare part per row. Nothing is saved until you check the file and confirm the import.') }}
    </flux:text>

    <form wire:submit="upload" class="space-y-6">
        <flux:input type="file" wire:model="file" :label="__('File')" accept=".xlsx,.csv" />

        <div class="flex flex-wrap gap-4">
            <flux:button variant="primary" type="submit">{{ __('Upload') }}</flux:button>
            <flux:button icon="arrow-down-tray" :href="route('imports.template')">{{ __('Download template') }}</flux:button>
            <flux:button :href="route('imports.index')" wire:navigate>{{ __('Back to imports') }}</flux:button>
        </div>
    </form>
</section>
```

- [ ] **Step 6: Create the review screen**

Create `app/Livewire/Imports/Review.php`:

```php
<?php

namespace App\Livewire\Imports;

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\ImportColumn;
use App\Domain\Imports\PartImports;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\ImportBatch;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * PRD FR-SPIMS-005 steps 3–6 for one uploaded file: match its columns, check every row, read the
 * preview or the errors, then import or cancel.
 */
#[Title('Spare part import')]
class Review extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $batchId;

    /** @var array<string, string> import column value => file column index, or '' when not in the file */
    public array $columnMap = [];

    public function mount(ImportBatch $batch): void
    {
        $this->authorize(Permission::PartsImport);

        $this->batchId = $batch->id;

        foreach (ImportColumn::cases() as $column) {
            $this->columnMap[$column->value] = (string) ($batch->column_map[$column->value] ?? '');
        }
    }

    public function check(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);
        $this->resetErrorBag();

        $map = array_map(fn (mixed $index): ?int => is_numeric($index) ? (int) $index : null, $this->columnMap);

        try {
            $preview = $imports->check($this->batch(), $map, $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError($refused->field === 'mapping' ? 'columnMap' : 'batch', $refused->getMessage());

            return;
        }

        Flux::toast(
            variant: $preview->isValid() ? 'success' : 'danger',
            text: $preview->isValid() ? __('Every row passed. Review the preview, then import.') : __('Some rows have errors. Nothing can be imported until they are fixed.'),
        );
    }

    public function import(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);

        try {
            $imports->requestConfirmation($this->batch(), $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError('batch', $refused->getMessage());
        }
    }

    public function cancel(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);

        try {
            $imports->cancel($this->batch(), $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError('batch', $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Import cancelled. Nothing was saved.'));
        $this->redirectRoute('imports.index', navigate: true);
    }

    /**
     * Read fresh on every request, so the screen shows what the import job has done.
     */
    #[Computed]
    public function batch(): ImportBatch
    {
        return ImportBatch::query()->with('importedBy')->findOrFail($this->batchId);
    }

    /**
     * The import columns to match, with whether each is required and whether this user may fill it.
     *
     * @return list<array{value: string, label: string, required: bool, permitted: bool}>
     */
    #[Computed]
    public function columns(): array
    {
        $actor = $this->actor();

        return array_map(fn (ImportColumn $column): array => [
            'value' => $column->value,
            'label' => $column->label(),
            'required' => $column->isRequired(),
            'permitted' => $column->permission() === null || $actor->can($column->permission()),
        ], ImportColumn::cases());
    }
}
```

Create `resources/views/livewire/imports/review.blade.php`:

```blade
@php($batch = $this->batch)

<section class="w-full max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ $batch->filename }}</flux:heading>
            <flux:text>
                {{ __('Uploaded by :name on :date', [
                    'name' => $batch->importedBy->name,
                    'date' => $batch->created_at->setTimezone(app(\App\Domain\Settings\InstallSettings::class)->timezone())->format('Y-m-d H:i'),
                ]) }}
            </flux:text>
        </div>

        <flux:badge :color="$batch->isImporting() ? 'blue' : $batch->status->color()">
            {{ $batch->isImporting() ? __('Importing') : __($batch->status->label()) }}
        </flux:badge>
    </div>

    <flux:error name="batch" />

    @if ($batch->isImporting())
        <div wire:poll.2s>
            <flux:callout icon="arrow-path">
                <flux:callout.heading>{{ __('The import is running.') }}</flux:callout.heading>
                <flux:callout.text>{{ __('This page updates when it finishes. You can leave it and come back.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @elseif ($batch->status === \App\Enums\ImportStatus::Completed)
        <flux:callout variant="success" icon="check-circle">
            <flux:callout.heading>{{ __('Imported') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __(':created created, :updated updated, :opening opening balances recorded.', [
                    'created' => $batch->rows_created,
                    'updated' => $batch->rows_updated,
                    'opening' => $batch->opening_balances,
                ]) }}
            </flux:callout.text>
        </flux:callout>

        <flux:button :href="route('parts.index')" wire:navigate>{{ __('Go to spare parts') }}</flux:button>
    @elseif ($batch->status === \App\Enums\ImportStatus::Cancelled)
        <flux:callout icon="x-circle">
            <flux:callout.heading>{{ __('Cancelled. Nothing was saved.') }}</flux:callout.heading>
        </flux:callout>
    @else
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Match the columns') }}</flux:heading>
            <flux:text>{{ __('Choose the column of the file that holds each field.') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($this->columns as $column)
                    <flux:select
                        wire:model="columnMap.{{ $column['value'] }}"
                        :label="__($column['label'])"
                        :description="$column['required'] ? __('Required on every row.') : ($column['permitted'] ? null : __('You may not import this field. Leave its cells blank.'))"
                    >
                        <flux:select.option value="">{{ __('Not in the file') }}</flux:select.option>
                        @foreach ($batch->headers as $index => $header)
                            <flux:select.option :value="(string) $index">{{ $header }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endforeach
            </div>

            <flux:error name="columnMap" />

            <div class="flex flex-wrap gap-4">
                <flux:button variant="primary" wire:click="check">
                    {{ $batch->status === \App\Enums\ImportStatus::Pending ? __('Check the file') : __('Check again') }}
                </flux:button>
                <flux:button wire:click="cancel" wire:confirm="{{ __('Cancel this import? Nothing will be saved.') }}">{{ __('Cancel import') }}</flux:button>
            </div>
        </flux:card>

        @if ($batch->status !== \App\Enums\ImportStatus::Pending)
            <flux:card class="space-y-4">
                <flux:heading size="lg">{{ __('Check result') }}</flux:heading>

                <ul class="space-y-1">
                    <li><flux:text>{{ __('Rows read: :count', ['count' => $batch->rows_total]) }}</flux:text></li>
                    <li><flux:text>{{ __('Spare parts to create: :count', ['count' => $batch->rows_created]) }}</flux:text></li>
                    <li><flux:text>{{ __('Spare parts to update: :count', ['count' => $batch->rows_updated]) }}</flux:text></li>
                    <li><flux:text>{{ __('Opening balances to record: :count', ['count' => $batch->opening_balances]) }}</flux:text></li>
                    @if ($batch->rows_failed > 0)
                        <li><flux:text>{{ __('Rows with errors: :count', ['count' => $batch->rows_failed]) }}</flux:text></li>
                    @endif
                </ul>

                @if ($batch->errors !== [])
                    <flux:callout variant="danger" icon="x-circle">
                        <flux:callout.heading>{{ __('Nothing can be imported while the file has errors.') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Correct the file and upload it again, or change the column matching and check again.') }}</flux:callout.text>
                    </flux:callout>

                    <ul class="space-y-1">
                        @foreach (array_slice($batch->errors, 0, 200) as $error)
                            <li>
                                <flux:text>
                                    {{ $error['row'] === 0
                                        ? __('File: :message', ['message' => $error['message']])
                                        : __('Row :row: :message', ['row' => $error['row'], 'message' => $error['message']]) }}
                                </flux:text>
                            </li>
                        @endforeach
                    </ul>

                    @if (count($batch->errors) > 200)
                        <flux:text>{{ __('Showing the first 200 of :count errors.', ['count' => count($batch->errors)]) }}</flux:text>
                    @endif
                @else
                    @if ($batch->warnings !== [])
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.heading>{{ __('Importing confirms these warnings.') }}</flux:callout.heading>
                            @foreach ($batch->warnings as $warning)
                                <flux:callout.text>{{ __('Row :row: :message', ['row' => $warning['row'], 'message' => $warning['message']]) }}</flux:callout.text>
                            @endforeach
                        </flux:callout>
                    @endif

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Row') }}</flux:table.column>
                            <flux:table.column>{{ __('Part number') }}</flux:table.column>
                            <flux:table.column>{{ __('Action') }}</flux:table.column>
                            <flux:table.column>{{ __('Opening quantity') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($batch->preview as $row)
                                <flux:table.row>
                                    <flux:table.cell>{{ $row['row'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['part_number'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['action'] === 'create' ? __('Create') : __('Update') }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['opening_quantity'] ?? '' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    @if (count($batch->preview) < $batch->rows_created + $batch->rows_updated)
                        <flux:text>{{ __('Showing the first :count rows.', ['count' => count($batch->preview)]) }}</flux:text>
                    @endif

                    <flux:button
                        variant="primary"
                        wire:click="import"
                        wire:confirm="{{ __('Import :count rows? Spare parts are created or updated and opening balances are recorded, all at once.', ['count' => $batch->rows_total]) }}"
                    >
                        {{ __('Import') }}
                    </flux:button>
                @endif
            </flux:card>
        @endif
    @endif
</section>
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Imports
```

Expected: PASS. `ImportScreensTest` contributes 12 tests: 9 cases, the first run once per role.

- [ ] **Step 8: Run the full check and commit**

```bash
composer test
git add app/Http/Controllers/ImportTemplateController.php app/Livewire/Imports resources/views/livewire/imports.blade.php resources/views/livewire/imports routes/spims.php app/Support/Navigation.php app/Enums/ImportStatus.php tests/Feature/Imports/ImportScreensTest.php
git commit -m "Add the spare part import screens: upload, match columns, preview, import"
```

---

## Task 6: The import in the project's own checks

**Files:**
- Modify:
  - `tests/Feature/Access/ScreenAccessMatrixTest.php`
  - `tests/Feature/Access/ActionReauthorizationTest.php`
  - `tests/Feature/ProvisioningWalkthroughTest.php`
  - `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`

**Interfaces:**
- Consumes: the routes, components and actions of Task 5; `ImportBatch::factory()` (Task 2).
- Produces: no application code. The three project-wide tests cover the import, and the design document matches what was built.

Plan 2 built three checks that every screen belongs to. A screen that is not in them is not covered:
- `ScreenAccessMatrixTest`: each route against each role, guests and a user with no role.
- `ActionReauthorizationTest`: each data-changing action re-checks its permission.
- `ProvisioningWalkthroughTest`: design §3.6 provisioning, through the screens only. Step 7 is the import, so the walkthrough stops creating its spare part by hand.

- [ ] **Step 1: Add the import screens to the access matrix**

In `tests/Feature/Access/ScreenAccessMatrixTest.php`:

1. Add `use App\Models\ImportBatch;` to the imports.
2. Change the opening comment's first line to `// Every Plan 2 and Plan 2b screen and the roles PRD §8 lets open it. Holders read as in RolePermissionMatrixTest:`.
3. Add these rows to the end of the `$screens` array:

```php
    'Spare part imports' => ['imports.index', null, '-S-A'],
    'New spare part import' => ['imports.create', null, '-S-A'],
    'Import template' => ['imports.template', null, '-S-A'],
    'Spare part import' => ['imports.show', fn () => ['batch' => ImportBatch::factory()->create()], '-S-A'],
```

- [ ] **Step 2: Add the import actions to the reauthorization test**

In `tests/Feature/Access/ActionReauthorizationTest.php`:

1. Add `use App\Livewire\Imports\Review as ImportReview;`, `use App\Livewire\Imports\Upload as ImportUpload;` and `use App\Models\ImportBatch;` to the imports.
2. Change the comment's first line to `// Every data-changing action in Plans 2 and 2b authorizes again, on top of mount(). Only mount() is exercised`.
3. Add these rows to the end of the `reauthorized actions` dataset:

```php
    'Imports\Upload::upload' => [
        Role::Supervisor, ImportUpload::class, fn () => [], 'upload', fn () => [],
    ],
    'Imports\Review::check' => [
        Role::Supervisor, ImportReview::class, fn () => ['batch' => ImportBatch::factory()->create()], 'check', fn () => [],
    ],
    'Imports\Review::import' => [
        Role::Supervisor, ImportReview::class, fn () => ['batch' => ImportBatch::factory()->create()], 'import', fn () => [],
    ],
    'Imports\Review::cancel' => [
        Role::Supervisor, ImportReview::class, fn () => ['batch' => ImportBatch::factory()->create()], 'cancel', fn () => [],
    ],
```

- [ ] **Step 3: Provision the install through the import**

Replace `tests/Feature/ProvisioningWalkthroughTest.php` with:

```php
<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Livewire\Admin\InstallSettingsForm;
use App\Livewire\Imports\Review as ImportReview;
use App\Livewire\Imports\Upload as ImportUpload;
use App\Livewire\MasterData\Equipment\Form as EquipmentForm;
use App\Livewire\MasterData\StorageLocations\Form as StorageLocationForm;
use App\Livewire\Personnel\Form as PersonnelForm;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('provisions an install through the screens alone: design §3.6 steps 4 to 8', function () {
    Storage::fake();

    // Step 4: the first System Administrator.
    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->assertSuccessful();
    $admin = User::query()->where('email', 'it.admin@microtech.test')->sole();

    // Step 5: company profile.
    Livewire::actingAs($admin)->test(InstallSettingsForm::class)
        ->set('settings.company.name', 'Microtech Industrial Solutions, Inc.')
        ->call('save')
        ->assertHasNoErrors();

    // Step 6: storage locations and the equipment register.
    Livewire::actingAs($admin)->test(StorageLocationForm::class)
        ->set('code', 'A-01')
        ->set('name', 'Aisle A, bin 1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(EquipmentForm::class)
        ->set('code', 'CNC-01')
        ->set('name', 'CNC lathe 1')
        ->call('save')
        ->assertHasNoErrors();

    // Step 7: spare parts, their stock levels and their opening balances, from one file.
    Livewire::actingAs($admin)->test(ImportUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent(
            'parts.csv',
            "Part number,Description,Unit of measure,Storage location code,Equipment codes,Criticality,Reorder point,Minimum level,Critical level,Opening quantity\n".
            "BRG-6204,Deep groove ball bearing,pcs,A-01,CNC-01,critical,10,4,2,12\n",
        ))
        ->call('upload')
        ->assertHasNoErrors();

    // The sync queue in tests writes the import during the import call.
    Livewire::actingAs($admin)->test(ImportReview::class, ['batch' => ImportBatch::query()->sole()])
        ->call('check')
        ->assertHasNoErrors()
        ->call('import')
        ->assertHasNoErrors();

    // Step 8: personnel and badges.
    Livewire::actingAs($admin)->test(PersonnelForm::class)
        ->set('employee_no', 'EMP-00042')
        ->set('full_name', 'Jose Rizal')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(PersonnelForm::class, ['personnel' => Personnel::query()->sole()])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasNoErrors();

    $checklist = collect(Livewire::actingAs($admin)->test(InstallSettingsForm::class)->instance()->checklist())
        ->pluck('done', 'label');
    $part = Part::query()->with(['defaultLocation', 'equipment'])->sole();

    expect(app(InstallSettings::class)->isReadyForStockTransactions())->toBeTrue()
        ->and($checklist->every(fn (bool $done): bool => $done))->toBeTrue()
        ->and($part->description)->toBe('Deep groove ball bearing')
        ->and($part->defaultLocation?->code)->toBe('A-01')
        ->and($part->equipment->pluck('code')->all())->toBe(['CNC-01'])
        ->and($part->min_level)->toBe('4.000')
        ->and(onHand($part))->toBe('12.000')
        ->and(app(PersonnelBadges::class)->identify('A12345678')?->employee_no)->toBe('EMP-00042')
        ->and(AuditLog::query()->where('user_id', $admin->id)->distinct()->pluck('auditable_type')->sort()->values()->all())
        ->toBe(['equipment', 'import_batch', 'part', 'personnel', 'setting', 'stock_transaction', 'storage_location']);
});
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Access tests/Feature/ProvisioningWalkthroughTest.php
```

Expected: PASS. The access matrix grows by 24 cases (four screens, each against four roles, a guest and a user with no role), and the reauthorization test by 4.

- [ ] **Step 5: Record what was built in the design document**

In `docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md`, §4.3, replace the `import_batches` table with the table as built:

```sql
create table import_batches (
  id                  bigserial primary key,
  filename            text not null,
  format              text not null check (format in ('csv','xlsx')),
  storage_path        text not null,
  status              text not null default 'pending'
                        check (status in ('pending','validated','completed','failed','cancelled')),
  headers             jsonb not null default '[]',  -- the file's header row
  column_map          jsonb not null default '{}',  -- import column -> file column index
  rows_total          integer not null default 0,
  rows_created        integer not null default 0,
  rows_updated        integer not null default 0,
  rows_failed         integer not null default 0,
  opening_balances    integer not null default 0,
  errors              jsonb not null default '[]',  -- [{row, message}]; row 0 is the whole file
  warnings            jsonb not null default '[]',
  preview             jsonb not null default '[]',  -- the first 100 rows that passed
  imported_by_user_id bigint not null references users(id),
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  confirmed_at        timestamptz,                  -- set from confirmation until the job ends
  completed_at        timestamptz
);
```

In §5.7, add these two paragraphs after the existing "Opening balances are written as `opening` movements" paragraph:

```markdown
Checking reads the file and writes nothing. Confirming queues one job, which checks the file again —
parts and stock may have changed since the preview — and then writes every spare part through the
part services and every opening balance through `StockLedger`, inside one database transaction. All
of it is written or none of it is, and a failure leaves the batch Failed with its reason.

Each spare part is created or updated through the same services the spare part screens use, so an
imported change is audited exactly like one made by hand. Opening balances are posted in `opening`
transactions of at most 200 lines.
```

- [ ] **Step 6: Run the full check and commit**

```bash
composer test
git add tests/Feature/Access/ScreenAccessMatrixTest.php tests/Feature/Access/ActionReauthorizationTest.php tests/Feature/ProvisioningWalkthroughTest.php docs/superpowers/specs/2026-09-12-spare-parts-inventory-design.md
git commit -m "Cover the import in the access, reauthorization and provisioning checks"
```

---

## Task 7: Withdrawal purpose names unique ignoring case

**Files:**
- Create: `database/migrations/2026_09_20_100002_make_withdrawal_purpose_names_unique_ignoring_case.php`, `tests/Feature/Settings/WithdrawalPurposeSchemaTest.php`

**Interfaces:**
- Consumes: the `withdrawal_purposes` table (Plan 1 Task 4) and `PurposeCatalogue::guardUniqueName()` (Plan 2).
- Produces: the unique index `withdrawal_purposes_name_lower_unique`.

Carried from Plan 2 (its Ruling Q). `PurposeCatalogue` already refuses a name another purpose holds in any letter case, but the table's unique index is case-sensitive, so two administrators saving "Machine repair" and "machine repair" at the same moment would both succeed, and the station would offer two purposes that read the same. The index closes that window; the service's check still produces the friendly message in every other case.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Settings/WithdrawalPurposeSchemaTest.php`:

```php
<?php

use App\Models\WithdrawalPurpose;
use Illuminate\Database\UniqueConstraintViolationException;

it('treats withdrawal purpose names as unique regardless of letter case', function () {
    WithdrawalPurpose::query()->create(['code' => 'machine_repair', 'name' => 'Machine repair']);

    expect(inSavepoint(fn () => WithdrawalPurpose::query()->create(['code' => 'machine_repair_2', 'name' => 'machine repair'])))
        ->toThrow(UniqueConstraintViolationException::class);
});
```

The seeded purposes, and the purposes the other tests add, already prove that two different names are still allowed.

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Settings/WithdrawalPurposeSchemaTest.php
```

Expected: FAIL. The first test's second insert succeeds, so no exception is thrown.

- [ ] **Step 3: Replace the index**

Create `database/migrations/2026_09_20_100002_make_withdrawal_purpose_names_unique_ignoring_case.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Two purposes whose names differ only in letter case read the same at the station, so the
        // table refuses them, as PurposeCatalogue already does (Plan 2 Ruling Q).
        DB::unprepared(<<<'SQL'
            alter table withdrawal_purposes drop constraint withdrawal_purposes_name_unique;

            create unique index withdrawal_purposes_name_lower_unique on withdrawal_purposes (lower(name));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index withdrawal_purposes_name_lower_unique;

            alter table withdrawal_purposes add constraint withdrawal_purposes_name_unique unique (name);
            SQL);
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan migrate
php artisan test tests/Feature/Settings/WithdrawalPurposeSchemaTest.php tests/Feature/Settings/WithdrawalPurposesTest.php
```

Expected: PASS, 1 new test, and the existing purposes tests still green. If the `drop constraint` fails because Plan 1's migration named the index differently, read the real name from `\d withdrawal_purposes` and use it.

- [ ] **Step 5: Run the full check and commit**

```bash
composer test
git add database/migrations/2026_09_20_100002_make_withdrawal_purpose_names_unique_ignoring_case.php tests/Feature/Settings/WithdrawalPurposeSchemaTest.php
git commit -m "Refuse two withdrawal purposes whose names differ only in letter case"
```

---

## Plan 2b Is Done When

- [ ] All seven tasks are committed, one commit each, with `composer test` green at every commit.
- [ ] The suite has grown by 79 tests, from 456 to 535: Task 1 eleven, Task 2 nine, Task 3 ten, Task 4 eight, Task 5 twelve, Task 6 twenty-eight, Task 7 one. A different number is fine when the task reports say why.
- [ ] `php artisan migrate:fresh --seed` runs clean on a scratch database, with both new migrations.
- [ ] The ledger invariant test still passes, and `LedgerVerifier::verify()` returns `[]` after an import with opening balances.
- [ ] Walked in a browser on a scratch demo install, signed in as a System Administrator:
  - [ ] Download the template; it opens in a spreadsheet program with one header row.
  - [ ] Upload a CSV holding a new part, an existing part, a duplicated part number, an unknown storage location and a bad quantity. The check reports each error with its spreadsheet row number, and no Import button is offered.
  - [ ] Upload the corrected file. The preview shows the counts and the first rows, and warns about a reorder point below the minimum.
  - [ ] Import. The parts list shows the new part at its location, its stock levels, its equipment and its opening balance; the part's history shows "created" and the stock level change; the withdrawal-station side is untouched.
  - [ ] Install settings shows "Opening balances" as done.
  - [ ] Upload another file and cancel it at the preview: it is listed as Cancelled and nothing was written.
- [ ] Signed in as an Inventory Supervisor:
  - [ ] The template downloads, and a file whose stock level and opening quantity columns are blank imports.
  - [ ] A file with values in those columns is refused, naming each column.
- [ ] Signed in as a Planner or a Custodian: no "Spare part imports" menu item, and `/imports` returns 403.

## Notes for Later Plans

Carry these to the plan named, in the ledger at `.superpowers/sdd/2026-09-20-spims-plan-02b-import/progress.md`:

- **Plan 3 (withdrawal station).** Nothing is owed. `StockLedger` gained no new posting kind: an opening balance is the `opening` transaction Plan 1 already posts.
- **Plan 6 (reports and exports).** `openspout` is installed and writes `.xlsx` (see `ImportTemplate`), so the export library question is already answered unless a report needs styling. An "download the errors as a file" button was not built; the errors are on the batch, so it is a small addition if Microtech asks for it.
- **Plan 7 (deployment).**
  - A queue worker must run for imports to be written: `php artisan queue:work`. The connection's `retry_after` must be larger than `ConfirmImport::$timeout` (1800 s), or a second worker takes the job while the first is still writing. With `$tries = 1` that second attempt is marked failed rather than run twice, so nothing is written twice, but the failure is noise in `failed_jobs`.
  - Uploaded import files stay under `imports/` on the storage disk for good. Decide their retention, and whether the backup includes them (the batch's counts, errors and preview are in the database either way).
  - `StockLedger` sets `lock_timeout` with `set local`, so from the first opening posting it applies to the rest of the import transaction as well. That is the intended behaviour, but it belongs in the operations notes.
- **Microtech (Q18).** When the starting parts list arrives, compare its header row with `ImportColumn::aliases()` and add whatever spelling it uses. Every column can be matched by hand on the screen, so a missing alias is a convenience, not a blocker.
- **Open question for Microtech.** An import never sets a part's status. A part someone set to Inactive stays Inactive even when a later file holds it. Confirm that is what they expect before go-live.
