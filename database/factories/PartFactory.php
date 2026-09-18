<?php

namespace Database\Factories;

use App\Enums\Criticality;
use App\Models\Part;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Part>
 */
class PartFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'part_number' => fake()->unique()->bothify('PN-#####-??'),
            'description' => fake()->randomElement(['Deep groove ball bearing', 'V-belt', 'Proximity sensor', 'Hydraulic oil']).' '.fake()->bothify('##??'),
            'unit_of_measure' => 'pcs',
            'criticality' => Criticality::Normal,
            'is_active' => true,
        ];
    }
}
