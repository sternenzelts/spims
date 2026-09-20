<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A batch whose file is never read, for tests about screens and states rather than rows.
 *
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => 'parts.csv',
            'format' => 'csv',
            'storage_path' => 'imports/'.fake()->uuid().'.csv',
            'status' => ImportStatus::Pending,
            'headers' => ['Part number', 'Description', 'Unit of measure'],
            'column_map' => ['part_number' => 0, 'description' => 1, 'unit_of_measure' => 2],
            'imported_by_user_id' => User::factory(),
        ];
    }
}
