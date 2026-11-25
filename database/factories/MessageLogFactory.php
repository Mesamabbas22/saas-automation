<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Models\MessageLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageLog>
 */
class MessageLogFactory extends Factory
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
            'phone' => '923'.fake()->numerify('#########'),
            'direction' => MessageDirection::In,
            'type' => 'text',
            'payload' => ['text' => ['body' => fake()->sentence()]],
            'wa_message_id' => 'wamid.'.fake()->unique()->uuid(),
            'status' => 'received',
        ];
    }

    public function outgoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => MessageDirection::Out,
            'status' => 'sent',
        ]);
    }
}
