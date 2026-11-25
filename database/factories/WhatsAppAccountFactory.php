<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppAccount>
 */
class WhatsAppAccountFactory extends Factory
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
            'phone_number_id' => (string) fake()->unique()->numerify('1###############'),
            'waba_id' => (string) fake()->numerify('1###############'),
            'access_token' => 'EAAG'.Str::random(60),
            'verify_token' => Str::random(32),
        ];
    }
}
