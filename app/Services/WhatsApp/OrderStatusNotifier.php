<?php

namespace App\Services\WhatsApp;

use App\Models\ConversationState;
use App\Models\Order;
use App\Models\WhatsAppAccount;

/**
 * Sends an order status update to the customer.
 *
 * Inside the 24h customer service window -> free-form text.
 * Outside it (or if Meta says the window is closed, error 131047) -> the approved
 * utility template from config('whatsapp.order_update_template'), with body
 * parameters {{1}} = order number, {{2}} = status.
 */
class OrderStatusNotifier
{
    public function notify(Order $order): OrderNotification
    {
        $order->loadMissing('customer');

        $account = WhatsAppAccount::withoutTenantScope()->where('tenant_id', $order->tenant_id)->first();

        if ($account === null) {
            return new OrderNotification(SendResult::failure('This business has no WhatsApp account configured.'), null);
        }

        $client = WhatsAppClient::forAccount($account);
        $phone = $order->customer->phone;

        if ($this->isWithinServiceWindow($order)) {
            $result = $client->sendText($phone, $this->statusText($order));

            if (! $result->isOutsideServiceWindow()) {
                return new OrderNotification($result, OrderNotification::VIA_TEXT);
            }
        }

        $result = $client->sendTemplate(
            to: $phone,
            templateName: (string) config('whatsapp.order_update_template'),
            bodyParameters: [$order->order_number, $order->status->getLabel()],
        );

        return new OrderNotification($result, OrderNotification::VIA_TEMPLATE);
    }

    public function isWithinServiceWindow(Order $order): bool
    {
        $state = ConversationState::withoutTenantScope()
            ->where('tenant_id', $order->tenant_id)
            ->where('phone', $order->customer->phone)
            ->first();

        return $state?->isWithinServiceWindow() ?? false;
    }

    private function statusText(Order $order): string
    {
        return "📦 Order *{$order->order_number}*\n\n".$order->status->customerMessage();
    }
}
