<?php

use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Livewire\Admin\InstallSettingsForm;
use App\Livewire\Imports\Review as ImportReview;
use App\Livewire\Imports\Upload as ImportUpload;
use App\Livewire\MasterData\Equipment\Form as EquipmentForm;
use App\Livewire\MasterData\StorageLocations\Form as StorageLocationForm;
use App\Livewire\Personnel\Form as PersonnelForm;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('provisions an install through the screens alone: design §3.6 steps 4 to 8', function () {
    Storage::fake();

    // Step 4: the first System Administrator.
    $this->artisan('spims:create-admin', ['email' => 'it.admin@microtech.test', 'name' => 'IT Administrator'])
        ->expectsQuestion('Password', 'correct-horse-battery-staple')
        ->assertSuccessful();
    $admin = User::query()->where('email', 'it.admin@microtech.test')->sole();

    // Step 5: company profile.
    Livewire::actingAs($admin)->test(InstallSettingsForm::class)
        ->set('settings.company.name', 'Microtech Industrial Solutions, Inc.')
        ->call('save')
        ->assertHasNoErrors();

    // Step 6: storage locations and the equipment register.
    Livewire::actingAs($admin)->test(StorageLocationForm::class)
        ->set('code', 'A-01')
        ->set('name', 'Aisle A, bin 1')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(EquipmentForm::class)
        ->set('code', 'CNC-01')
        ->set('name', 'CNC lathe 1')
        ->call('save')
        ->assertHasNoErrors();

    // Step 7: spare parts, their stock levels and their opening balances, from one file.
    Livewire::actingAs($admin)->test(ImportUpload::class)
        ->set('file', UploadedFile::fake()->createWithContent(
            'parts.csv',
            "Part number,Description,Unit of measure,Storage location code,Equipment codes,Criticality,Reorder point,Minimum level,Critical level,Opening quantity\n".
            "BRG-6204,Deep groove ball bearing,pcs,A-01,CNC-01,critical,10,4,2,12\n",
        ))
        ->call('save')
        ->assertHasNoErrors();

    // The sync queue in tests writes the import during the import call.
    Livewire::actingAs($admin)->test(ImportReview::class, ['batch' => ImportBatch::query()->sole()])
        ->call('check')
        ->assertHasNoErrors()
        ->call('import')
        ->assertHasNoErrors();

    // Step 8: personnel and badges.
    Livewire::actingAs($admin)->test(PersonnelForm::class)
        ->set('employee_no', 'EMP-00042')
        ->set('full_name', 'Jose Rizal')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)->test(PersonnelForm::class, ['personnel' => Personnel::query()->sole()])
        ->set('badgeScan', 'A12345678')
        ->call('enrollBadge')
        ->assertHasNoErrors();

    $checklist = collect(Livewire::actingAs($admin)->test(InstallSettingsForm::class)->instance()->checklist())
        ->pluck('done', 'label');
    $part = Part::query()->with(['defaultLocation', 'equipment'])->sole();

    expect(app(InstallSettings::class)->isReadyForStockTransactions())->toBeTrue()
        ->and($checklist->every(fn (bool $done): bool => $done))->toBeTrue()
        ->and($part->description)->toBe('Deep groove ball bearing')
        ->and($part->defaultLocation?->code)->toBe('A-01')
        ->and($part->equipment->pluck('code')->all())->toBe(['CNC-01'])
        ->and($part->min_level)->toBe('4.000')
        ->and(onHand($part))->toBe('12.000')
        ->and(app(PersonnelBadges::class)->identify('A12345678')?->employee_no)->toBe('EMP-00042')
        ->and(AuditLog::query()->where('user_id', $admin->id)->distinct()->pluck('auditable_type')->sort()->values()->all())
        ->toBe(['equipment', 'import_batch', 'part', 'personnel', 'setting', 'stock_transaction', 'storage_location']);
});
