<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboards a business in one transaction: tenant + owner user + WhatsApp account
 * (+ an active subscription when a plan is chosen).
 */
class TenantProvisioner
{
    /**
     * @param  array{
     *     name: string,
     *     slug?: string|null,
     *     plan_id?: int|null,
     *     status?: TenantStatus|string|null,
     *     owner: array{name: string, email: string, password: string},
     *     whatsapp: array{phone_number_id: string, waba_id?: string|null, access_token: string, verify_token?: string|null},
     * }  $data
     */
    public function provision(array $data): Tenant
    {
        return DB::transaction(function () use ($data): Tenant {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => filled($data['slug'] ?? null) ? $data['slug'] : $this->uniqueSlug($data['name']),
                'plan_id' => $data['plan_id'] ?? null,
                'status' => $data['status'] ?? TenantStatus::Active,
            ]);

            User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'password' => $data['owner']['password'],
                'role' => UserRole::Owner,
            ]);

            WhatsAppAccount::create([
                'tenant_id' => $tenant->id,
                'phone_number_id' => $data['whatsapp']['phone_number_id'],
                'waba_id' => $data['whatsapp']['waba_id'] ?? null,
                'access_token' => $data['whatsapp']['access_token'],
                'verify_token' => filled($data['whatsapp']['verify_token'] ?? null) ? $data['whatsapp']['verify_token'] : Str::random(32),
            ]);

            if ($tenant->plan_id !== null) {
                $tenant->subscriptions()->create([
                    'plan_id' => $tenant->plan_id,
                    'starts_at' => now(),
                    'ends_at' => now()->addMonth(),
                    'status' => SubscriptionStatus::Active,
                ]);
            }

            return $tenant;
        });
    }

    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
