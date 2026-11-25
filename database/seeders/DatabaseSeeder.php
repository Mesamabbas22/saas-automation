<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds: 3 plans, 1 super admin, 2 demo tenants (each with owner, WhatsApp account, products).
 *
 * Default password for all seeded users: "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $starter = Plan::query()->updateOrCreate(['name' => 'Starter'], ['price' => 0, 'message_limit' => 1000]);
        $business = Plan::query()->updateOrCreate(['name' => 'Business'], ['price' => 2500, 'message_limit' => 5000]);
        Plan::query()->updateOrCreate(['name' => 'Pro'], ['price' => 6000, 'message_limit' => 20000]);

        User::query()->updateOrCreate(
            ['email' => 'admin@orderbot.test'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'role' => UserRole::SuperAdmin,
                'tenant_id' => null,
                'email_verified_at' => now(),
            ],
        );

        $this->seedTenant(
            name: 'Lahore Biryani House',
            slug: 'lahore-biryani',
            ownerName: 'Ali Raza',
            ownerEmail: 'owner@lahore-biryani.test',
            plan: $business,
            phoneNumberId: config('whatsapp.demo.phone_number_id'),
            wabaId: config('whatsapp.demo.waba_id'),
            accessToken: config('whatsapp.demo.access_token'),
            products: [
                ['Chicken Biryani (Full)', 'Masaledar chicken biryani, 1 plate', 650],
                ['Beef Pulao', 'Yakhni wala beef pulao, 1 plate', 700],
                ['Chicken Karahi (Half)', 'Desi ghee karahi, half kg', 1400],
                ['Raita', 'Fresh podina raita', 80],
                ['Cold Drink 1.5L', 'Coke / Sprite / Fanta', 220],
            ],
        );

        $this->seedTenant(
            name: 'Karachi Kapra Store',
            slug: 'karachi-kapra',
            ownerName: 'Sana Khan',
            ownerEmail: 'owner@karachi-kapra.test',
            plan: $starter,
            phoneNumberId: 'demo-phone-number-id-2',
            wabaId: 'demo-waba-id-2',
            accessToken: 'replace-with-real-token',
            products: [
                ['Lawn Suit 3pc', 'Unstitched printed lawn, 3 piece', 3500],
                ['Khaddar Shawl', 'Winter khaddar shawl', 1800],
                ['Men Kurta (Cotton)', 'Stitched cotton kurta, all sizes', 2200],
                ['Dupatta Chiffon', 'Embroidered chiffon dupatta', 1200],
            ],
        );
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int}>  $products
     */
    private function seedTenant(
        string $name,
        string $slug,
        string $ownerName,
        string $ownerEmail,
        Plan $plan,
        string $phoneNumberId,
        string $wabaId,
        string $accessToken,
        array $products,
    ): void {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'plan_id' => $plan->id, 'status' => TenantStatus::Active],
        );

        User::query()->updateOrCreate(
            ['email' => $ownerEmail],
            [
                'tenant_id' => $tenant->id,
                'name' => $ownerName,
                'password' => 'password',
                'role' => UserRole::Owner,
                'email_verified_at' => now(),
            ],
        );

        $tenant->whatsappAccounts()->updateOrCreate(
            [],
            [
                'phone_number_id' => $phoneNumberId,
                'waba_id' => $wabaId,
                'access_token' => $accessToken,
                'verify_token' => Str::random(32),
            ],
        );

        foreach ($products as [$productName, $description, $price]) {
            $tenant->products()->updateOrCreate(
                ['name' => $productName],
                ['description' => $description, 'price' => $price, 'is_active' => true],
            );
        }

        $tenant->subscriptions()->firstOrCreate(
            ['plan_id' => $plan->id, 'status' => SubscriptionStatus::Active],
            ['starts_at' => now()->startOfMonth(), 'ends_at' => now()->startOfMonth()->addMonth()],
        );
    }
}
