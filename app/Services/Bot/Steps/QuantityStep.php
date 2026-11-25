<?php

namespace App\Services\Bot\Steps;

use App\Enums\ConversationStep;
use App\Models\Product;
use App\Services\Bot\BotContext;
use App\Services\Bot\BotReplies;

/**
 * Asks quantity with Reply Buttons 1/2/3; a typed number up to MAX_TYPED is accepted too.
 */
class QuantityStep implements Step
{
    public const REPLY_PREFIX = 'qty_';

    public const MAX_TYPED = 10;

    public function prompt(BotContext $context, Product $product, ?string $notice = null): void
    {
        $context->remember(['product_id' => $product->id]);

        $body = BotReplies::askQuantity($product);

        $context->client->sendButtons(
            to: $context->phone(),
            body: $notice === null ? $body : "{$notice}\n\n{$body}",
            buttons: [
                self::REPLY_PREFIX.'1' => '1',
                self::REPLY_PREFIX.'2' => '2',
                self::REPLY_PREFIX.'3' => '3',
            ],
            footer: BotReplies::quantityFooter(self::MAX_TYPED),
        );

        $context->goTo(ConversationStep::Quantity);
    }

    public function handle(BotContext $context): void
    {
        $product = Product::active()->find($context->get('product_id'));

        if ($product === null) {
            app(ProductStep::class)->prompt($context, BotReplies::productUnavailable());

            return;
        }

        $quantity = $this->parseQuantity($context);

        if ($quantity === null) {
            $this->prompt($context, $product, BotReplies::invalidQuantity(self::MAX_TYPED));

            return;
        }

        $context->remember(['quantity' => $quantity]);

        app(AddressStep::class)->prompt($context);
    }

    private function parseQuantity(BotContext $context): ?int
    {
        $raw = $context->message->replyIdAfter(self::REPLY_PREFIX)
            ?? ($context->message->isTypedText() ? $context->message->normalizedText() : null);

        if ($raw === null || ! ctype_digit($raw)) {
            return null;
        }

        $quantity = (int) $raw;

        return $quantity >= 1 && $quantity <= self::MAX_TYPED ? $quantity : null;
    }
}
