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
