<?php

namespace App\Filament\Admin\Resources\WhatsAppAccounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The access token is write-only: it is never sent back to the browser.
 * Leaving it empty on edit keeps the stored (encrypted) token.
 */
class WhatsAppAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('tenant_id')
                    ->label('Business')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('phone_number_id')
                    ->label('Phone number ID')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true),
                TextInput::make('waba_id')
                    ->label('WhatsApp Business Account ID')
                    ->maxLength(64),
                TextInput::make('access_token')
                    ->label('Access token')
                    ->password()
                    ->formatStateUsing(fn (): ?string => null)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder(fn (string $operation): ?string => $operation === 'edit' ? '•••••••• (leave empty to keep current token)' : null)
                    ->helperText('Stored encrypted.'),
                TextInput::make('verify_token')
                    ->label('Verify token')
                    ->password()
                    ->revealable()
                    ->default(fn (): string => Str::random(32))
                    ->maxLength(255),
            ]);
    }
}
