<?php

namespace App\Filament\App\Resources\Customers\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('orders')->withMax('orders', 'created_at'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label('WhatsApp')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->badge()
                    ->sortable(),
                TextColumn::make('orders_max_created_at')
                    ->label('Last order')
                    ->since()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('First contact')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
