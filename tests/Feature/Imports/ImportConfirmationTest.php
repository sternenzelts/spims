<?php

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\ImportWriter;
use App\Domain\Imports\PartImports;
use App\Domain\Ledger\LedgerVerifier;
use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\Exceptions\PartChangeRefused;
use App\Enums\Criticality;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Jobs\ConfirmImport;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StorageLocation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    configureInstall();
    $this->admin = userWithRoles(Role::Admin);
    $this->checked = function (string $csv) {
        $batch = uploadImport($csv, $this->admin);
        app(PartImports::class)->check($batch, $batch->column_map, $this->admin);

        return $batch->refresh();
    };
});

it('creates and updates parts, links equipment and records opening balances in the ledger', function () {
    $location = StorageLocation::factory()->create(['code' => 'A-01']);
    $cnc = Equipment::factory()->create(['code' => 'CNC-01']);
    $existing = Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Bearing']);
    $batch = ($this->checked)(
        "Part number,Description,Unit of measure,Storage location code,Equipment codes,Criticality,Minimum level,Reorder point,Opening quantity\n".
        "BRG-6204,Deep groove ball bearing,pcs,A-01,CNC-01,critical,2,5,12\n".
        "OIL-68,Hydraulic oil,L,,,,,,20.5\n",
    );

    // The sync queue in tests runs ConfirmImport at once.
    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    $oil = Part::query()->where('part_number', 'OIL-68')->sole();

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Completed)
        ->rows_created->toBe(1)
        ->rows_updated->toBe(1)
        ->opening_balances->toBe(2)
        ->completed_at->not->toBeNull()
        ->and($existing->refresh())
        ->description->toBe('Deep groove ball bearing')
        ->default_location_id->toBe($location->id)
        ->criticality->toBe(Criticality::Critical)
        ->min_level->toBe('2.000')
        ->reorder_point->toBe('5.000')
        ->and($existing->equipment()->get()->modelKeys())->toBe([$cnc->id])
        ->and(onHand($existing))->toBe('12.000')
        ->and(onHand($oil))->toBe('20.500')
        ->and(StockMovement::query()->where('movement_type', 'opening')->count())->toBe(2)
        ->and(app(LedgerVerifier::class)->verify())->toBe([])
        ->and(AuditLog::query()->where('auditable_type', 'import_batch')->where('event', 'imported')->sole())
        ->user_id->toBe($this->admin->id);
});

it('posts opening balances in transactions of at most the configured number of lines', function () {
    app()->when(ImportWriter::class)->needs('$linesPerTransaction')->give(2);
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nP-1,One,pcs,1\nP-2,Two,pcs,2\nP-3,Three,pcs,3\n");

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    expect(StockTransaction::query()->where('type', 'opening')->withCount('lines')->orderBy('id')->pluck('lines_count')->all())
        ->toBe([2, 1])
        ->and(app(LedgerVerifier::class)->verify())->toBe([]);
});

it('checks the file again at confirmation, and writes nothing when it no longer passes', function () {
    $part = Part::factory()->create(['part_number' => 'BRG-6204', 'description' => 'Bearing']);
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nBRG-6204,Deep groove ball bearing,pcs,12\n");

    // Stock is recorded for the part between the preview and the confirmation.
    $opening = StockTransaction::factory()->opening()->withLines([[$part, '3']])->create();
    app(StockLedger::class)->complete($opening, $opening->custodian);

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Failed)
        ->confirmed_at->toBeNull()
        ->errors->toBe([['row' => 2, 'message' => 'BRG-6204 already has stock movements, so its opening balance cannot be imported.']])
        ->and($part->refresh()->description)->toBe('Bearing')
        ->and(onHand($part))->toBe('3.000');
});

it('writes nothing when the import fails part-way, and records why', function (Closure $failure, string $message) {
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nP-1,One,pcs,1\nP-2,Two,pcs,2\nP-3,Three,pcs,3\n");
    $created = 0;
    Part::created(function () use (&$created, $failure): void {
        if (++$created === 2) {
            throw $failure();
        }
    });

    // Not Throwable: Pest reads an interface name as an expected message, not as a class.
    expect(fn () => app(PartImports::class)->requestConfirmation($batch, $this->admin))->toThrow(Exception::class);

    expect(Part::query()->exists())->toBeFalse()
        ->and(StockTransaction::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'imported')->exists())->toBeFalse()
        ->and($batch->refresh())
        ->status->toBe(ImportStatus::Failed)
        ->confirmed_at->toBeNull()
        ->errors->toBe([['row' => 0, 'message' => $message]]);
})->with([
    'a refusal, which explains itself' => [
        fn () => PartChangeRefused::inactiveLocation(),
        'The import stopped and nothing was saved: Choose an Active storage location.',
    ],
    'a part saved elsewhere at the same moment' => [
        fn () => new UniqueConstraintViolationException('pgsql', 'insert into "parts" …', [], new Exception('duplicate key value violates unique constraint "parts_part_number_key"')),
        'The import stopped and nothing was saved: a spare part in the file was created elsewhere at the same moment. Check the file again, and that row will update it instead.',
    ],
    'an unexpected error, which may hold SQL' => [
        fn () => new RuntimeException('SQLSTATE[08006]: connection failure during insert into "parts" …'),
        'The import stopped and nothing was saved: an unexpected error, recorded in the application log.',
    ],
]);

it('imports a batch only after it passed its check, and only once', function () {
    $imports = app(PartImports::class);
    $batch = uploadImport("Part number,Description,Unit of measure\nP-1,One,pcs\n", $this->admin);

    expect(fn () => $imports->requestConfirmation($batch, $this->admin))
        ->toThrow(ImportRefused::class, 'Check the file, and fix every error, before importing it.');

    $imports->check($batch, $batch->column_map, $this->admin);
    $imports->requestConfirmation($batch, $this->admin);

    expect($batch->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(fn () => $imports->requestConfirmation($batch, $this->admin))->toThrow(ImportRefused::class)
        ->and(fn () => $imports->cancel($batch, $this->admin))->toThrow(ImportRefused::class)
        ->and(Part::query()->count())->toBe(1);
});

it('queues the import and marks the batch as started', function () {
    Queue::fake();
    $batch = ($this->checked)("Part number,Description,Unit of measure\nP-1,One,pcs\n");

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    Queue::assertPushed(ConfirmImport::class, fn (ConfirmImport $job): bool => $job->batchId === $batch->id && $job->actorId === $this->admin->id);

    expect($batch->refresh())
        ->status->toBe(ImportStatus::Validated)
        ->confirmed_at->not->toBeNull()
        ->and(Part::query()->exists())->toBeFalse();
});

it('locks opening balances in ascending part id order to avoid deadlock', function () {
    app()->when(ImportWriter::class)->needs('$linesPerTransaction')->give(2);

    // Create the parts so their ids run opposite to the file's rows: the last row holds the lowest id.
    $third = Part::factory()->create(['part_number' => 'P-3']);
    $second = Part::factory()->create(['part_number' => 'P-2']);
    $first = Part::factory()->create(['part_number' => 'P-1']);
    $batch = ($this->checked)("Part number,Description,Unit of measure,Opening quantity\nP-1,One,pcs,1\nP-2,Two,pcs,2\nP-3,Three,pcs,3\n");

    app(PartImports::class)->requestConfirmation($batch, $this->admin);

    $posted = StockMovement::query()
        ->where('movement_type', 'opening')
        ->orderBy('stock_transaction_id')
        ->orderBy('id')
        ->pluck('part_id')
        ->map(fn (mixed $partId): int => (int) $partId)
        ->all();

    // Ascending by part id across both transactions, not merely inside each one.
    expect($posted)->toBe([$third->id, $second->id, $first->id])
        ->and(StockTransaction::query()->where('type', 'opening')->count())->toBe(2);
});
