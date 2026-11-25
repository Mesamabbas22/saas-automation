<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\WhatsApp\OrderNotification;
use App\Services\WhatsApp\OrderStatusNotifier;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private OrderStatusNotifier $notifier) {}

    /**
     * Update the status and (optionally) tell the customer on WhatsApp.
     * Returns null when nothing was sent (status unchanged or notify = false).
     */
    public function changeStatus(Order $order, OrderStatus $status, bool $notifyCustomer = true): ?OrderNotification
    {
        if ($order->status === $status) {
            return null;
        }

        $order->update(['status' => $status]);

        return $notifyCustomer ? $this->notifier->notify($order) : null;
    }

    /**
     * Create an order with one line item. Price is snapshotted from the product.
     */
    public function placeOrder(
        Customer $customer,
        Product $product,
        int $quantity,
        string $address,
        PaymentMethod $paymentMethod = PaymentMethod::Cod,
    ): Order {
        return DB::transaction(function () use ($customer, $product, $quantity, $address, $paymentMethod): Order {
            $order = Order::create([
                'tenant_id' => $customer->tenant_id,
                'customer_id' => $customer->id,
                'address' => $address,
                'total' => $quantity * (float) $product->price,
                'status' => OrderStatus::Pending,
                'payment_method' => $paymentMethod,
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $product->price,
            ]);

            return $order;
        });
    }
}
