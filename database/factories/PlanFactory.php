<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Starter', 'Business', 'Pro']),
            'price' => fake()->randomElement([0, 2500, 5000]),
            'message_limit' => fake()->randomElement([1000, 5000, 20000]),
        ];
    }
}
