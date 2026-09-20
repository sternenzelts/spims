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
