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
