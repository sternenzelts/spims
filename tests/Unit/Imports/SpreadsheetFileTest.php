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
