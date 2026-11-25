<?php

namespace App\Filament\Admin\Resources\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Subscriptions\Pages\ManageSubscriptions;
use App\Models\Subscription;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Saving an *active* subscription also sets the tenant's current plan (see Subscription::booted).
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tenant_id')
                    ->label('Business')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name')
                    ->preload()
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->required()
                    ->default(now()),
                DateTimePicker::make('ends_at')
                    ->default(now()->addMonth())
                    ->after(fn (Get $get): ?string => $get('starts_at') ? 'starts_at' : null),
                Select::make('status')
                    ->options(SubscriptionStatus::class)
                    ->default(SubscriptionStatus::Active)
                    ->required()
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'plan']))
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Business')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('starts_at')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('Open-ended')
                    ->color(fn (Subscription $record): ?string => $record->ends_at?->isPast() ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('status')->options(SubscriptionStatus::class),
                SelectFilter::make('plan')->relationship('plan', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSubscriptions::route('/'),
        ];
    }
}
