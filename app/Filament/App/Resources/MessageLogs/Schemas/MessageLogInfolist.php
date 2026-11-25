<?php

namespace App\Filament\App\Resources\MessageLogs\Schemas;

use App\Models\MessageLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class MessageLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('created_at')->label('Time')->dateTime('d M Y, h:i:s A'),
                        TextEntry::make('direction')->badge(),
                        TextEntry::make('phone')->copyable(),
                        TextEntry::make('type')->badge()->color('gray'),
                        TextEntry::make('status')->badge()->placeholder('—'),
                        TextEntry::make('wa_message_id')->label('WA message id')->copyable()->placeholder('—'),
                    ]),
                Section::make('Payload')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('payload')
                            ->hiddenLabel()
                            ->state(fn (MessageLog $record): HtmlString => new HtmlString(
                                '<pre style="white-space:pre-wrap;font-size:12px;line-height:1.5">'
                                .e(json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                                .'</pre>'
                            )),
                    ]),
            ]);
    }
}
