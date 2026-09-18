<?php

namespace Database\Seeders;

use App\Models\WithdrawalPurpose;
use Illuminate\Database\Seeder;

/**
 * The proposal's §4 Step 3 purpose list, seeded into every install. Existing rows are left alone,
 * so a System Administrator's renames and deactivations survive re-running the seeder.
 */
class WithdrawalPurposeSeeder extends Seeder
{
    public function run(): void
    {
        $purposes = [
            ['code' => 'preventive_maintenance', 'name' => 'Preventive Maintenance', 'requires_remarks' => false],
            ['code' => 'corrective_maintenance', 'name' => 'Corrective Maintenance', 'requires_remarks' => false],
            ['code' => 'breakdown_repair', 'name' => 'Breakdown Repair', 'requires_remarks' => false],
            ['code' => 'machine_modification', 'name' => 'Machine Modification', 'requires_remarks' => false],
            ['code' => 'worn_part_replacement', 'name' => 'Replacement of Worn Part', 'requires_remarks' => false],
            ['code' => 'other', 'name' => 'Other Maintenance Activity', 'requires_remarks' => true],
        ];

        foreach ($purposes as $index => $purpose) {
            WithdrawalPurpose::query()->firstOrCreate(
                ['code' => $purpose['code']],
                [...$purpose, 'sort_order' => ($index + 1) * 10],
            );
        }
    }
}
