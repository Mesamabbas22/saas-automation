<?php

namespace App\Filament\App\Widgets;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Actions\ChangeOrderStatusAction;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingOrdersTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Pending orders';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Order::query()->with('customer')->where('status', OrderStatus::Pending))
            ->defaultSort('created_at', 'desc')
            ->paginated([5, 10])
            ->emptyStateHeading('No pending orders')
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('order_number')->label('Order #')->weight('bold'),
                TextColumn::make('customer.name')->label('Customer')->description(fn (Order $record): string => $record->customer->phone),
                TextColumn::make('total')->money('PKR'),
                TextColumn::make('created_at')->label('Placed')->since(),
            ])
            ->recordActions([
                ChangeOrderStatusAction::make(),
            ]);
    }
}
