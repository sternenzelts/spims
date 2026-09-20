<?php

namespace App\Domain\Imports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Enums\ImportStatus;
use App\Jobs\ConfirmImport;
use App\Models\ImportBatch;
use App\Models\User;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
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
    public function __construct(
        private AuditLogger $audit,
        private ImportValidator $validator,
        private ImportWriter $writer,
    ) {}

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
