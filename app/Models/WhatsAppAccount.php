<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WhatsAppAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Table('whatsapp_accounts')]
#[Fillable(['tenant_id', 'phone_number_id', 'waba_id', 'access_token', 'verify_token'])]
#[Hidden(['access_token', 'verify_token'])]
class WhatsAppAccount extends Model
{
    /** @use HasFactory<WhatsAppAccountFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
        ];
    }
}
