<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\MessageDirection;
use App\Enums\OrderStatus;
use App\Enums\TenantStatus;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Bot\BotReplies;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform-wide numbers. No TenantContext is set in /admin, so these span all tenants.
 */
class PlatformStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $activeTenants = Tenant::query()->where('status', TenantStatus::Active)->count();
        $totalTenants = Tenant::query()->count();

        return [
            Stat::make('Active businesses', $activeTenants)
                ->description("{$totalTenants} total")
                ->descriptionIcon(Heroicon::BuildingStorefront)
                ->color('primary'),
            Stat::make('Orders today', Order::query()->whereDate('created_at', today())->count())
                ->description('Sales this month: '.BotReplies::money((float) Order::query()
                    ->where('status', '!=', OrderStatus::Cancelled)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('total')))
                ->descriptionIcon(Heroicon::ShoppingBag)
                ->color('success'),
            Stat::make('Messages sent this month', MessageLog::query()
                ->where('direction', MessageDirection::Out)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count())
                ->description('Failed: '.MessageLog::query()
                    ->where('status', 'failed')
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count())
                ->descriptionIcon(Heroicon::ChatBubbleLeftRight)
                ->color('info'),
        ];
    }
}
