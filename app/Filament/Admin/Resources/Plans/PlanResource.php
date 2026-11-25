<?php

namespace App\Filament\Admin\Resources\Plans;

use App\Filament\Admin\Resources\Plans\Pages\ManagePlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('price')
                    ->label('Price per month')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix('Rs'),
                TextInput::make('message_limit')
                    ->label('Message limit per month')
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(1000)
                    ->helperText('Outgoing WhatsApp messages per calendar month.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('price')
            ->modifyQueryUsing(fn ($query) => $query->withCount('tenants'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('price')
                    ->money('PKR')
                    ->sortable(),
                TextColumn::make('message_limit')
                    ->label('Messages / month')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tenants_count')
                    ->label('Businesses')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Plan $record): bool => $record->tenants_count > 0 || $record->subscriptions()->exists())
                    ->tooltip(fn (Plan $record): ?string => $record->tenants_count > 0 ? 'Plan is in use' : null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlans::route('/'),
        ];
    }
}
