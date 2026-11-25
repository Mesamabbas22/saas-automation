<?php

namespace App\Filament\App\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\WhatsApp\OrderNotification;
use App\Services\WhatsApp\OrderStatusNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Change status": updates the order and sends the customer a WhatsApp update
 * (free text inside the 24h window, approved template outside it).
 * Used both as a table row action and on the view page.
 */
class ChangeOrderStatusAction
{
    public static function make(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->modalHeading(fn (Order $record): string => "Change status — {$record->order_number}")
            ->modalSubmitActionLabel('Update')
            ->fillForm(fn (Order $record): array => [
                'status' => $record->status,
                'notify_customer' => true,
            ])
            ->schema(fn (Order $record): array => [
                Select::make('status')
                    ->options(OrderStatus::class)
                    ->required()
                    ->native(false),
                Toggle::make('notify_customer')
                    ->label('Send WhatsApp update to customer')
                    ->helperText(app(OrderStatusNotifier::class)->isWithinServiceWindow($record)
                        ? 'Customer messaged in the last 24h: a normal text message will be sent.'
                        : 'Outside the 24h window: the approved "'.config('whatsapp.order_update_template').'" template will be used.')
                    ->default(true),
            ])
            ->action(function (Order $record, array $data, OrderService $orderService): void {
                $status = $data['status'] instanceof OrderStatus ? $data['status'] : OrderStatus::from($data['status']);

                $notification = $orderService->changeStatus($record, $status, (bool) $data['notify_customer']);

                self::notifyUser($record, $notification);
            });
    }

    private static function notifyUser(Order $order, ?OrderNotification $notification): void
    {
        $title = "Order {$order->order_number} is now {$order->status->getLabel()}";

        if ($notification === null) {
            Notification::make()->title($title)->success()->send();

            return;
        }

        if ($notification->successful()) {
            Notification::make()
                ->title($title)
                ->body($notification->viaTemplate() ? 'Customer notified via WhatsApp template.' : 'Customer notified on WhatsApp.')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title($title)
            ->body('Status saved, but the WhatsApp message failed: '.$notification->result->errorMessage)
            ->warning()
            ->persistent()
            ->send();
    }
}
