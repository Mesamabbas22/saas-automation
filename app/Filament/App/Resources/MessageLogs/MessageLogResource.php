<?php

namespace App\Filament\App\Resources\MessageLogs;

use App\Filament\App\Resources\MessageLogs\Pages\ListMessageLogs;
use App\Filament\App\Resources\MessageLogs\Pages\ViewMessageLog;
use App\Filament\App\Resources\MessageLogs\Schemas\MessageLogInfolist;
use App\Filament\App\Resources\MessageLogs\Tables\MessageLogsTable;
use App\Models\MessageLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only WhatsApp traffic log, for debugging.
 */
class MessageLogResource extends Resource
{
    protected static ?string $model = MessageLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Debug';

    protected static ?string $navigationLabel = 'Message logs';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return MessageLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MessageLogsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageLogs::route('/'),
            'view' => ViewMessageLog::route('/{record}'),
        ];
    }
}
