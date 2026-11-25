<?php

namespace App\Services\Bot\Steps;

use App\Enums\ConversationStep;
use App\Models\Product;
use App\Services\Bot\BotContext;
use App\Services\Bot\BotReplies;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shows the product List Message and handles the selection.
 */
class ProductStep implements Step
{
    public const REPLY_PREFIX = 'product_';

    /**
     * Sends welcome (or a notice) + product list; starts a fresh order.
     */
    public function prompt(BotContext $context, ?string $notice = null): void
    {
        $context->reset();

        $products = $this->listableProducts();

        if ($products->isEmpty()) {
            $context->client->sendText($context->phone(), BotReplies::noProducts());

            return;
        }

        $context->client->sendList(
            to: $context->phone(),
            body: $notice ?? BotReplies::welcome($context->tenant->name, $context->customer->name),
            buttonText: BotReplies::LIST_BUTTON,
            sections: [[
                'title' => BotReplies::LIST_SECTION,
                'rows' => $products->map(fn (Product $product): array => [
                    'id' => self::REPLY_PREFIX.$product->id,
                    'title' => $product->name,
                    'description' => trim(BotReplies::money($product->price).' · '.$product->description, ' ·'),
                ])->all(),
            ]],
        );

        $context->goTo(ConversationStep::Product);
    }

    public function handle(BotContext $context): void
    {
        $productId = $context->message->replyIdAfter(self::REPLY_PREFIX);

        // Product::active() is tenant-scoped: an id from another tenant is simply not found.
        $product = ctype_digit((string) $productId) ? Product::active()->find((int) $productId) : null;

        if ($product === null) {
            $this->prompt($context, BotReplies::invalidProduct());

            return;
        }

        app(QuantityStep::class)->prompt($context, $product);
    }

    /**
     * A List Message holds at most 10 rows.
     *
     * @return Collection<int, Product>
     */
    private function listableProducts(): Collection
    {
        return Product::active()->orderBy('name')->limit(WhatsAppClient::MAX_LIST_ROWS)->get();
    }
}
