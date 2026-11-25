<?php

namespace App\Models;

use App\Enums\ConversationStep;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ConversationStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'phone', 'step', 'data', 'last_message_at'])]
class ConversationState extends Model
{
    /** @use HasFactory<ConversationStateFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => ConversationStep::class,
            'data' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * WhatsApp allows free-form messages only within 24h of the customer's last message.
     */
    public function isWithinServiceWindow(): bool
    {
        return $this->last_message_at !== null
            && $this->last_message_at->greaterThan(now()->subHours(24));
    }
}
