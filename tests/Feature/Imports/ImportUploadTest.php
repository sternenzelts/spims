<?php

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\PartImports;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\QueryException;
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

it('deletes the stored file when creating the batch row fails', function () {
    $unsavedActor = User::factory()->make();

    expect(inSavepoint(fn () => app(PartImports::class)->upload(
        UploadedFile::fake()->createWithContent('parts.csv', "Part number,Description,Unit of measure\nBRG-6204,Bearing,pcs\n"),
        $unsavedActor,
    )))->toThrow(QueryException::class);

    expect(Storage::allFiles('imports'))->toBe([]);
});

it('leaves no temporary copy of the file behind', function () {
    $copies = fn (): array => glob(sys_get_temp_dir().'/spims-import-*') ?: [];
    $before = $copies();

    uploadImport("Part number,Description,Unit of measure\nBRG-6204,Bearing,pcs\n", $this->admin);

    expect($copies())->toBe($before);
});
