<?php

namespace App\Filament\Admin\Resources\WhatsAppAccounts\Tables;

use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WhatsAppAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('tenant'))
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Business')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_number_id')
                    ->label('Phone number ID')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('waba_id')
                    ->label('WABA ID')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('token_state')
                    ->label('Access token')
                    ->state(fn (WhatsAppAccount $record): string => filled($record->access_token) ? 'Set (encrypted)' : 'Missing')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Missing' ? 'danger' : 'success'),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('testConnection')
                    ->label('Test')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->action(function (WhatsAppAccount $record): void {
                        $result = WhatsAppClient::forAccount($record)->phoneNumberInfo();

                        if ($result->successful()) {
                            Notification::make()
                                ->title('Connected')
                                ->body(sprintf(
                                    '%s (%s) · quality: %s',
                                    $result->response['verified_name'] ?? '?',
                                    $result->response['display_phone_number'] ?? '?',
                                    $result->response['quality_rating'] ?? 'n/a',
                                ))
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Connection failed')
                            ->body($result->errorMessage)
                            ->danger()
                            ->persistent()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
