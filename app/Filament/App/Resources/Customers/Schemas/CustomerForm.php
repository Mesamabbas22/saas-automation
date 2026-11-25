<?php

namespace App\Filament\App\Resources\Customers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('WhatsApp number')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Comes from WhatsApp and cannot be changed.'),
            ]);
    }
}
