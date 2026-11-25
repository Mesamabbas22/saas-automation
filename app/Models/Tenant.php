<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A business using the bot. The Filament /app panel tenant.
 *
 * Relationship names (products, orders, customers, ...) match what Filament
 * tenancy expects for resource scoping.
 */
#[Fillable(['name', 'slug', 'plan_id', 'status'])]
class Tenant extends Model implements HasName
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function whatsappAccount(): HasOne
    {
        return $this->hasOne(WhatsAppAccount::class);
    }

    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsAppAccount::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function conversationStates(): HasMany
    {
        return $this->hasMany(ConversationState::class);
    }

    public function messageLogs(): HasMany
    {
        return $this->hasMany(MessageLog::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Adds `messages_this_month`: outgoing WhatsApp messages since the 1st of this month
     * (what counts against the plan's message_limit).
     */
    #[Scope]
    protected function withMessagesThisMonth(Builder $query): void
    {
        $query->withCount([
            'messageLogs as messages_this_month' => fn (Builder $logs): Builder => $logs
                ->where('direction', MessageDirection::Out)
                ->where('created_at', '>=', now()->startOfMonth()),
        ]);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany(
            ['starts_at' => 'max'],
            fn ($query) => $query->where('status', 'active'),
        );
    }
}
