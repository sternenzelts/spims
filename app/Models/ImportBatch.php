<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Jobs\ConfirmImport;
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
     * Confirmed and waiting for, or being written by, the ConfirmImport job.
     */
    public function isImporting(): bool
    {
        return $this->status === ImportStatus::Validated && $this->confirmed_at !== null;
    }

    /**
     * Importing, but past the job's own timeout: the job never reported back, so it cannot still
     * be running.
     */
    public function isStalled(): bool
    {
        return $this->isImporting()
            && $this->confirmed_at !== null
            && $this->confirmed_at->addSeconds(ConfirmImport::TIMEOUT)->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
