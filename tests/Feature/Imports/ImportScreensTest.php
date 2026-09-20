<?php

use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Livewire\Imports\Index;
use App\Livewire\Imports\Review;
use App\Livewire\Imports\Upload;
use App\Models\ImportBatch;
use App\Models\Part;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake();
    configureInstall();
});

it('lets supervisors and administrators import spare parts', function (Role $role, bool $allowed) {
    $user = userWithRoles($role);
    $batch = ImportBatch::factory()->create();

    $this->actingAs($user)->get(route('imports.index'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('imports.create'))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('imports.show', $batch))->assertStatus($allowed ? 200 : 403);
    $this->actingAs($user)->get(route('imports.template'))->assertStatus($allowed ? 200 : 403);

    expect(in_array('Spare part imports', menuLabels($user), true))->toBe($allowed);
})->with(roleMatrix('-S-A'));

it('downloads the import template', function () {
    $this->actingAs(userWithRoles(Role::Supervisor))
        ->get(route('imports.template'))
        ->assertOk()
        ->assertDownload('spims-spare-part-import-template.xlsx');
});

it('uploads a file, matches its columns, checks it and imports it', function () {
    $admin = userWithRoles(Role::Admin);

    $upload = Livewire::actingAs($admin)->test(Upload::class)
        ->set('file', UploadedFile::fake()->createWithContent('parts.csv', "Item code,Item description,UOM,Qty\nBRG-6204,Deep groove ball bearing,pcs,12\n"))
        ->call('upload')
        ->assertHasNoErrors();

    $batch = ImportBatch::query()->sole();
    $upload->assertRedirect(route('imports.show', $batch));

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->assertSet('columnMap.part_number', '0')
        ->assertSet('columnMap.opening_quantity', '3')
        ->assertSet('columnMap.location_code', '')
        ->call('check')
        ->assertHasNoErrors()
        ->assertSee('Spare parts to create: 1')
        ->assertSee('Opening balances to record: 1')
        // The sync queue in tests runs the import at once.
        ->call('import')
        ->assertSee('1 created, 0 updated, 1 opening balances recorded.');

    expect($batch->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(onHand(Part::query()->where('part_number', 'BRG-6204')->sole()))->toBe('12.000');
});

it('refuses a file that is not an xlsx or csv file', function () {
    Livewire::actingAs(userWithRoles(Role::Supervisor))->test(Upload::class)
        ->set('file', UploadedFile::fake()->createWithContent('parts.ods', 'content'))
        ->call('upload')
        ->assertHasErrors('file')
        ->assertSee('Upload an .xlsx or .csv file.');

    expect(ImportBatch::query()->exists())->toBeFalse();
});

it('asks for a column for every required field before checking', function () {
    $admin = userWithRoles(Role::Admin);
    $batch = uploadImport("Item,Name,Unit\nBRG-6204,Bearing,pcs\n", $admin);

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->assertSet('columnMap.part_number', '')
        ->call('check')
        ->assertHasErrors('columnMap')
        ->assertSee('Match these required fields to a column of the file: Part number.')
        ->set('columnMap.part_number', '0')
        ->call('check')
        ->assertHasNoErrors();

    expect($batch->refresh()->status)->toBe(ImportStatus::Validated);
});

it('lists each error with its row number and blocks the import', function () {
    $admin = userWithRoles(Role::Admin);
    $batch = uploadImport("Part number,Description,Unit of measure\nBRG-6204,,pcs\n", $admin);

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->call('check')
        ->assertSee('Row 2: Description is required.')
        ->assertDontSeeHtml('wire:click="import"')
        ->call('import')
        ->assertHasErrors('batch');

    expect(Part::query()->exists())->toBeFalse();
});

it('shows a running import and refreshes until it ends', function () {
    Queue::fake();
    $admin = userWithRoles(Role::Admin);
    $batch = uploadImport("Part number,Description,Unit of measure\nP-1,One,pcs\n", $admin);

    Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch])
        ->call('check')
        ->call('import')
        ->assertSee('The import is running.')
        ->assertSeeHtml('wire:poll.2s');
});

it('cancels an import at the preview, writing nothing', function () {
    $supervisor = userWithRoles(Role::Supervisor);
    $batch = uploadImport("Part number,Description,Unit of measure\nP-1,One,pcs\n", $supervisor);

    Livewire::actingAs($supervisor)->test(Review::class, ['batch' => $batch])
        ->call('check')
        ->call('cancel')
        ->assertRedirect(route('imports.index'));

    expect($batch->refresh()->status)->toBe(ImportStatus::Cancelled)
        ->and(Part::query()->exists())->toBeFalse();
});

it('lists imports newest first with their status', function () {
    ImportBatch::factory()->create(['filename' => 'first.csv', 'status' => ImportStatus::Completed]);
    ImportBatch::factory()->create(['filename' => 'second.xlsx', 'status' => ImportStatus::Failed]);

    Livewire::actingAs(userWithRoles(Role::Admin))->test(Index::class)
        ->assertSeeInOrder(['second.xlsx', 'Needs fixing', 'first.csv', 'Imported']);
});
