<?php

namespace App\Filament\App\Resources\Customers\RelationManagers;

use App\Filament\App\Resources\Orders\Actions\ChangeOrderStatusAction;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list of a customer's orders.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')->label('Order #')->weight('bold')->searchable(),
                TextColumn::make('total')->money('PKR')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('payment_method')->label('Payment')->badge()->color('gray'),
                TextColumn::make('created_at')->label('Placed')->dateTime('d M Y, h:i A')->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
                ChangeOrderStatusAction::make(),
            ]);
    }
}
