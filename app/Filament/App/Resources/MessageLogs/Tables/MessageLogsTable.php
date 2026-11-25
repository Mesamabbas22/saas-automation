<?php

namespace App\Filament\App\Resources\MessageLogs\Tables;

use App\Enums\MessageDirection;
use App\Models\MessageLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MessageLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('15s')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('d M, h:i:s A')
                    ->sortable(),
                TextColumn::make('direction')
                    ->badge(),
                TextColumn::make('phone')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('preview')
                    ->label('Message')
                    ->state(fn (MessageLog $record): string => self::preview($record))
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'failed' => 'danger',
                        'read' => 'success',
                        'delivered' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('wa_message_id')
                    ->label('WA message id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')->options(MessageDirection::class),
                SelectFilter::make('status')->options([
                    'received' => 'Received',
                    'sent' => 'Sent',
                    'delivered' => 'Delivered',
                    'read' => 'Read',
                    'failed' => 'Failed',
                ]),
                SelectFilter::make('type')->options([
                    'text' => 'Text',
                    'interactive' => 'Interactive',
                    'template' => 'Template',
                    'image' => 'Image',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Human-readable gist of the payload for the table.
     */
    public static function preview(MessageLog $record): string
    {
        $payload = $record->payload ?? [];

        return (string) ($payload['text']['body']
            ?? $payload['interactive']['body']['text']
            ?? $payload['interactive']['button_reply']['title']
            ?? $payload['interactive']['list_reply']['title']
            ?? (isset($payload['template']['name']) ? 'Template: '.$payload['template']['name'] : null)
            ?? $payload['error']['message']
            ?? '—');
    }
}
