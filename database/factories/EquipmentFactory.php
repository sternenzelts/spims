<?php

namespace Database\Factories;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('EQ-####'),
            'name' => fake()->randomElement(['Conveyor', 'Compressor', 'Boiler Feed Pump', 'Packaging Line']).' '.fake()->numberBetween(1, 9),
            'area' => fake()->randomElement(['Production', 'Utilities', 'Warehouse']),
            'is_active' => true,
        ];
    }
}
