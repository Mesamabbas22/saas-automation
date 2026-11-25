<?php

namespace App\Filament\App\Resources\Customers\Schemas;

use App\Enums\OrderStatus;
use App\Models\Customer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name')->placeholder('—'),
                        TextEntry::make('phone')->label('WhatsApp')->copyable(),
                        TextEntry::make('orders_count')
                            ->label('Orders')
                            ->state(fn (Customer $record): int => $record->orders()->count()),
                        TextEntry::make('total_spent')
                            ->label('Total spent')
                            ->money('PKR')
                            ->state(fn (Customer $record): float => (float) $record->orders()
                                ->where('status', '!=', OrderStatus::Cancelled)
                                ->sum('total')),
                        TextEntry::make('created_at')->label('First contact')->dateTime('d M Y'),
                    ]),
            ]);
    }
}
