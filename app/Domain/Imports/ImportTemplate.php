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
