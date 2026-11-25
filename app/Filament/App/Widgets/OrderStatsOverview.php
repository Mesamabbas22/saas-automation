<?php

namespace App\Filament\App\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Bot\BotReplies;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Queries are tenant-scoped by BelongsToTenant (TenantContext is set by the panel middleware).
 */
class OrderStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $todayOrders = Order::query()->whereDate('created_at', today())->count();
        $yesterdayOrders = Order::query()->whereDate('created_at', today()->subDay())->count();
        $pendingOrders = Order::query()->where('status', OrderStatus::Pending)->count();
        $monthSales = (float) Order::query()
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total');

        return [
            Stat::make("Today's orders", $todayOrders)
                ->description("Yesterday: {$yesterdayOrders}")
                ->descriptionIcon($todayOrders >= $yesterdayOrders ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->color($todayOrders >= $yesterdayOrders ? 'success' : 'gray'),
            Stat::make('Pending orders', $pendingOrders)
                ->description($pendingOrders > 0 ? 'Waiting for confirmation' : 'All caught up')
                ->descriptionIcon(Heroicon::Clock)
                ->color($pendingOrders > 0 ? 'warning' : 'success'),
            Stat::make('Sales this month', BotReplies::money($monthSales))
                ->description(now()->format('F Y').' · excl. cancelled')
                ->descriptionIcon(Heroicon::Banknotes)
                ->color('primary'),
        ];
    }
}
