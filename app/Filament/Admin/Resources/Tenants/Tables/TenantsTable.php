<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['plan', 'whatsappAccount', 'users' => fn ($users) => $users->where('role', UserRole::Owner)])
                ->withCount('orders')
                ->withMessagesThisMonth())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Tenant $record): string => '/app/'.$record->slug),
                TextColumn::make('owner')
                    ->label('Owner')
                    ->state(fn (Tenant $record): ?string => $record->users->first()?->email)
                    ->placeholder('—'),
                TextColumn::make('whatsappAccount.phone_number_id')
                    ->label('Phone number ID')
                    ->placeholder('Not connected')
                    ->toggleable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->sortable(),
                TextColumn::make('messages_this_month')
                    ->label('Msgs (month)')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(TenantStatus::class),
                SelectFilter::make('plan')->relationship('plan', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('suspend')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The owner loses panel access and the bot stops replying for this business.')
                    ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Active)
                    ->action(fn (Tenant $record) => $record->update(['status' => TenantStatus::Suspended])),
                Action::make('activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Suspended)
                    ->action(fn (Tenant $record) => $record->update(['status' => TenantStatus::Active])),
            ]);
    }
}
