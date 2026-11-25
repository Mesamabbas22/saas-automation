<?php

namespace App\Filament\App\Resources\Orders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Order')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('order_number')->label('Order #')->weight('bold')->copyable(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('total')->money('PKR')->weight('bold'),
                        TextEntry::make('payment_method')->label('Payment')->badge()->color('gray'),
                        TextEntry::make('created_at')->label('Placed')->dateTime('d M Y, h:i A'),
                        TextEntry::make('updated_at')->label('Last update')->since(),
                    ]),
                Section::make('Customer')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('customer.name')->label('Name')->placeholder('—'),
                        TextEntry::make('customer.phone')->label('WhatsApp')->copyable(),
                        TextEntry::make('address')->placeholder('—'),
                    ]),
                Section::make('Items')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                RepeatableEntry\TableColumn::make('Product'),
                                RepeatableEntry\TableColumn::make('Qty'),
                                RepeatableEntry\TableColumn::make('Unit price'),
                                RepeatableEntry\TableColumn::make('Subtotal'),
                            ])
                            ->schema([
                                TextEntry::make('product.name')->placeholder('(deleted product)'),
                                TextEntry::make('quantity'),
                                TextEntry::make('price')->money('PKR'),
                                TextEntry::make('subtotal')->money('PKR'),
                            ]),
                    ]),
            ]);
    }
}
