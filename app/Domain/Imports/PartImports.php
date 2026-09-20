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
