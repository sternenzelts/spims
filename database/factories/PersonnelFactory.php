<?php

namespace Database\Factories;

use App\Domain\Identity\BadgeIdentity;
use App\Models\Personnel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personnel>
 */
class PersonnelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_no' => fake()->unique()->numerify('EMP-#####'),
            'full_name' => fake()->name(),
            'department' => fake()->randomElement(['Maintenance', 'Production', 'Utilities']),
            'is_active' => true,
        ];
    }

    /**
     * Enroll a badge directly, for tests that need an identified person but are not about enrollment.
     */
    public function withBadge(string $uid): static
    {
        return $this->state(fn (): array => app(BadgeIdentity::class)->attributesFor($uid));
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
