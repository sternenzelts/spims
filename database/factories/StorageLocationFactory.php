<?php

namespace Database\Factories;

use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageLocation>
 */
class StorageLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('RACK-??-##'),
            'name' => 'Rack '.fake()->unique()->bothify('?##'),
            'is_active' => true,
        ];
    }
}
