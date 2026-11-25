<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MessageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'phone', 'direction', 'type', 'payload', 'wa_message_id', 'status'])]
class MessageLog extends Model
{
    /** @use HasFactory<MessageLogFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'payload' => 'array',
        ];
    }
}
