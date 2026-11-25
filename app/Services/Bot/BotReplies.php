<?php

namespace App\Services\Bot;

use App\Models\Order;
use App\Models\Product;

/**
 * Every customer-facing bot text, in simple Roman Urdu + English.
 */
final class BotReplies
{
    public const LIST_BUTTON = 'Products dekhein';

    public const LIST_SECTION = 'Menu';

    public const CONFIRM_ID = 'confirm_order';

    public const CANCEL_ID = 'cancel_order';

    public static function welcome(string $businessName, ?string $customerName): string
    {
        $greeting = $customerName ? "Assalam o Alaikum {$customerName}! 👋" : 'Assalam o Alaikum! 👋';

        return "{$greeting}\n*{$businessName}* mein khush aamdeed.\nWelcome to *{$businessName}*!\n\n"
            .'Neeche list se product chunein. / Choose a product from the list below.';
    }

    public static function noProducts(): string
    {
        return "Maazrat, abhi koi product available nahi hai.\nSorry, no products are available right now.";
    }

    public static function invalidProduct(): string
    {
        return "Barah-e-karam list mein se product chunein.\nPlease pick a product from the list.";
    }

    public static function productUnavailable(): string
    {
        return "Maazrat, yeh product ab available nahi hai. Koi aur chunein.\nSorry, this product is no longer available. Please choose another.";
    }

    public static function askQuantity(Product $product): string
    {
        return "*{$product->name}* — ".self::money($product->price)."\n\n"
            ."Kitni quantity chahiye?\nHow many would you like?";
    }

    public static function quantityFooter(int $max): string
    {
        return "Ya 1-{$max} type karein / Or type 1-{$max}";
    }

    public static function invalidQuantity(int $max): string
    {
        return "Barah-e-karam 1, 2 ya 3 chunein (ya 1-{$max} type karein).\nPlease choose 1, 2 or 3 (or type 1-{$max}).";
    }

    public static function askAddress(): string
    {
        return "Apna mukammal delivery address bhejein (ghar #, gali, area, sheher).\n"
            .'Please send your full delivery address (house #, street, area, city).';
    }

    public static function invalidAddress(int $minLength): string
    {
        return "Address thora mukammal likhein (kam az kam {$minLength} huroof).\n"
            ."Please send a complete address (at least {$minLength} characters).";
    }

    public static function orderSummary(Product $product, int $quantity, string $address): string
    {
        $total = $quantity * (float) $product->price;

        return "🧾 *Order Summary*\n\n"
            ."{$quantity} x {$product->name} @ ".self::money($product->price)."\n"
            .'*Total: '.self::money($total)."*\n\n"
            ."📍 Address: {$address}\n"
            ."💵 Payment: Cash on Delivery\n\n"
            .'Order confirm karein? / Confirm your order?';
    }

    public static function invalidConfirmation(): string
    {
        return 'Barah-e-karam *Confirm* ya *Cancel* dabayein. / Please tap Confirm or Cancel.';
    }

    public static function orderPlaced(Order $order): string
    {
        return "Shukriya! Aap ka order mil gaya hai. ✅\nThank you! Your order has been placed.\n\n"
            ."Order #: *{$order->order_number}*\n"
            .'Total: *'.self::money($order->total)."*\n\n"
            ."Hum jald aap se rabta karenge. / We will contact you shortly.\n"
            ."Naya order karne ke liye 'menu' likhein. / Type 'menu' to order again.";
    }

    public static function orderCancelled(): string
    {
        return "Order cancel kar diya gaya hai. / Your order has been cancelled.\n"
            ."Dobara shuru karne ke liye 'menu' likhein. / Type 'menu' to start again.";
    }

    public static function money(float|int|string|null $amount): string
    {
        $amount = (float) $amount;
        $decimals = floor($amount) === $amount ? 0 : 2;

        return 'Rs '.number_format($amount, $decimals);
    }
}
