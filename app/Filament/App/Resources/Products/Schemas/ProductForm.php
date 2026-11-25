<?php

namespace App\Filament\App\Resources\Products\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Details')
                    ->columnSpan(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('First 24 characters are shown in the WhatsApp product list.'),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Shown (shortened) under the product name in WhatsApp.'),
                        TextInput::make('price')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999999)
                            ->prefix('Rs'),
                    ]),
                Section::make('Image & visibility')
                    ->columnSpan(1)
                    ->schema([
                        FileUpload::make('image_url')
                            ->label('Image')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->visibility('public')
                            ->directory(fn (): string => 'products/'.Filament::getTenant()?->getKey())
                            ->maxSize(2048),
                        Toggle::make('is_active')
                            ->label('Active (visible in WhatsApp menu)')
                            ->default(true),
                    ]),
            ]);
    }
}
