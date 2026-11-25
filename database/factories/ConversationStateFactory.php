<?php

namespace Database\Factories;

use App\Enums\ConversationStep;
use App\Models\ConversationState;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationState>
 */
class ConversationStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'phone' => '923'.fake()->unique()->numerify('#########'),
            'step' => ConversationStep::Menu,
            'data' => [],
            'last_message_at' => now(),
        ];
    }
}
