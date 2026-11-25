<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Tenant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Outgoing messages per tenant this calendar month vs. their plan's message_limit.
 */
class MessageUsageTable extends TableWidget
{
    public const WARNING_PERCENT = 80;

    public const DANGER_PERCENT = 100;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Message usage this month';

    public function table(Table $table): Table
    {
        return $table
            ->description('Outgoing WhatsApp messages since '.now()->startOfMonth()->format('j M').' vs. plan limit')
            ->query(fn () => Tenant::query()->with('plan')->withMessagesThisMonth())
            ->defaultSort('messages_this_month', 'desc')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('name')
                    ->label('Business')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->badge()
                    ->color('gray')
                    ->placeholder('No plan'),
                TextColumn::make('messages_this_month')
                    ->label('Used')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('plan.message_limit')
                    ->label('Limit')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('usage_percent')
                    ->label('Usage')
                    ->state(fn (Tenant $record): ?int => self::usagePercent($record))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state}%")
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= self::DANGER_PERCENT => 'danger',
                        $state >= self::WARNING_PERCENT => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('status')
                    ->badge(),
            ]);
    }

    public static function usagePercent(Tenant $tenant): ?int
    {
        $limit = (int) ($tenant->plan?->message_limit ?? 0);

        if ($limit <= 0) {
            return null;
        }

        return (int) floor(((int) $tenant->messages_this_month / $limit) * 100);
    }
}
