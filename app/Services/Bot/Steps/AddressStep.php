<?php

namespace App\Services\Bot\Steps;

use App\Enums\ConversationStep;
use App\Services\Bot\BotContext;
use App\Services\Bot\BotReplies;
use Illuminate\Support\Str;

/**
 * Asks for the delivery address as free text.
 */
class AddressStep implements Step
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 500;

    public function prompt(BotContext $context, ?string $notice = null): void
    {
        $context->client->sendText($context->phone(), $notice ?? BotReplies::askAddress());

        $context->goTo(ConversationStep::Address);
    }

    public function handle(BotContext $context): void
    {
        $address = $context->message->isTypedText() ? trim((string) $context->message->text) : '';

        if (mb_strlen($address) < self::MIN_LENGTH) {
            $this->prompt($context, BotReplies::invalidAddress(self::MIN_LENGTH));

            return;
        }

        $context->remember(['address' => Str::limit($address, self::MAX_LENGTH, '')]);

        app(ConfirmStep::class)->prompt($context);
    }
}
