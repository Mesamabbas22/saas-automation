<?php

namespace App\Services\Bot\Steps;

use App\Enums\ConversationStep;
use App\Models\Product;
use App\Services\Bot\BotContext;
use App\Services\Bot\BotReplies;
use App\Services\OrderService;

/**
 * Shows the order summary with Confirm / Cancel buttons; places or drops the order.
 */
class ConfirmStep implements Step
{
    /** @var array<int, string> */
    private const YES_WORDS = ['confirm', 'yes', 'haan', 'han', 'ji', 'ok', 'okay'];

    /** @var array<int, string> */
    private const NO_WORDS = ['cancel', 'no', 'nahi', 'nhi'];

    public function __construct(private OrderService $orderService) {}

    public function prompt(BotContext $context, ?string $notice = null): void
    {
        $product = $this->currentProduct($context);

        if ($product === null) {
            app(ProductStep::class)->prompt($context, BotReplies::productUnavailable());

            return;
        }

        $summary = BotReplies::orderSummary($product, (int) $context->get('quantity'), (string) $context->get('address'));

        $context->client->sendButtons(
            to: $context->phone(),
            body: $notice === null ? $summary : "{$notice}\n\n{$summary}",
            buttons: [
                BotReplies::CONFIRM_ID => '✅ Confirm',
                BotReplies::CANCEL_ID => '❌ Cancel',
            ],
        );

        $context->goTo(ConversationStep::Confirm);
    }

    public function handle(BotContext $context): void
    {
        $message = $context->message;

        if ($message->replyId === BotReplies::CONFIRM_ID || in_array($message->normalizedText(), self::YES_WORDS, true)) {
            $this->placeOrder($context);

            return;
        }

        if ($message->replyId === BotReplies::CANCEL_ID || in_array($message->normalizedText(), self::NO_WORDS, true)) {
            $context->client->sendText($context->phone(), BotReplies::orderCancelled());
            $context->reset();

            return;
        }

        $this->prompt($context, BotReplies::invalidConfirmation());
    }

    private function placeOrder(BotContext $context): void
    {
        $product = $this->currentProduct($context);

        if ($product === null) {
            app(ProductStep::class)->prompt($context, BotReplies::productUnavailable());

            return;
        }

        $order = $this->orderService->placeOrder(
            customer: $context->customer,
            product: $product,
            quantity: (int) $context->get('quantity'),
            address: (string) $context->get('address'),
        );

        $context->reset();
        $context->client->sendText($context->phone(), BotReplies::orderPlaced($order));
    }

    private function currentProduct(BotContext $context): ?Product
    {
        return Product::active()->find($context->get('product_id'));
    }
}
