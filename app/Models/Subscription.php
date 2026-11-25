<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'plan_id', 'starts_at', 'ends_at', 'status'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => SubscriptionStatus::class,
        ];
    }

    /**
     * An active subscription decides the tenant's current plan (and so its message limit).
     */
    protected static function booted(): void
    {
        static::saved(function (Subscription $subscription): void {
            if ($subscription->status === SubscriptionStatus::Active) {
                Tenant::query()->whereKey($subscription->tenant_id)->update(['plan_id' => $subscription->plan_id]);
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
