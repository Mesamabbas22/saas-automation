<?php

namespace App\Filament\Admin\Resources\WhatsAppAccounts;

use App\Filament\Admin\Resources\WhatsAppAccounts\Pages\CreateWhatsAppAccount;
use App\Filament\Admin\Resources\WhatsAppAccounts\Pages\EditWhatsAppAccount;
use App\Filament\Admin\Resources\WhatsAppAccounts\Pages\ListWhatsAppAccounts;
use App\Filament\Admin\Resources\WhatsAppAccounts\Schemas\WhatsAppAccountForm;
use App\Filament\Admin\Resources\WhatsAppAccounts\Tables\WhatsAppAccountsTable;
use App\Models\WhatsAppAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WhatsAppAccountResource extends Resource
{
    protected static ?string $model = WhatsAppAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleOvalLeftEllipsis;

    protected static ?string $navigationLabel = 'WhatsApp accounts';

    protected static ?string $recordTitleAttribute = 'phone_number_id';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'whatsapp-accounts';

    public static function form(Schema $schema): Schema
    {
        return WhatsAppAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WhatsAppAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppAccounts::route('/'),
            'create' => CreateWhatsAppAccount::route('/create'),
            'edit' => EditWhatsAppAccount::route('/{record}/edit'),
        ];
    }
}
