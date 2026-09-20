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

    /**
     * @param  list<string>  $labels
     */
    public static function requiredColumnsMissing(array $labels): self
    {
        return new self('Match these required fields to a column of the file: '.implode(', ', $labels).'.', 'mapping');
    }

    public static function columnUsedTwice(string $header): self
    {
        return new self("Column \"{$header}\" is matched to more than one field.", 'mapping');
    }

    public static function notValidated(): self
    {
        return new self('Check the file, and fix every error, before importing it.', 'batch');
    }
}
