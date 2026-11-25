<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Enums\TenantStatus;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Create: business + owner + WhatsApp account in one form (handled by TenantProvisioner).
 * Edit: business fields only; owner/WhatsApp are managed in their own screens.
 */
class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Business')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create' && blank($get('slug'))) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->prefix('/app/')
                            ->helperText('Used in the business panel URL.'),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->relationship('plan', 'name')
                            ->preload()
                            ->helperText('On create, an active 1-month subscription is started for this plan.'),
                        Select::make('status')
                            ->options(TenantStatus::class)
                            ->default(TenantStatus::Active)
                            ->required()
                            ->native(false),
                    ]),

                Section::make('Owner login')
                    ->description('The business owner signs in at /app with these details.')
                    ->visibleOn('create')
                    ->statePath('owner')
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(table: User::class, column: 'email'),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8),
                    ]),

                Section::make('WhatsApp Cloud API account')
                    ->description('From Meta Developer dashboard → your app → WhatsApp → API Setup.')
                    ->visibleOn('create')
                    ->statePath('whatsapp')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone_number_id')
                            ->label('Phone number ID')
                            ->required()
                            ->maxLength(64)
                            ->unique(table: WhatsAppAccount::class, column: 'phone_number_id'),
                        TextInput::make('waba_id')
                            ->label('WhatsApp Business Account ID')
                            ->maxLength(64),
                        TextInput::make('access_token')
                            ->label('Access token')
                            ->password()
                            ->required()
                            ->helperText('Stored encrypted. Use a System User permanent token in production.'),
                        TextInput::make('verify_token')
                            ->label('Verify token (optional)')
                            ->password()
                            ->revealable()
                            ->default(fn (): string => Str::random(32))
                            ->maxLength(255),
                    ]),
            ]);
    }
}
