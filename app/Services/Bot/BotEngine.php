<?php

namespace App\Services\Bot;

use App\Enums\ConversationStep;
use App\Models\ConversationState;
use App\Models\Customer;
use App\Models\WhatsAppAccount;
use App\Services\Bot\Steps\AddressStep;
use App\Services\Bot\Steps\ConfirmStep;
use App\Services\Bot\Steps\MenuStep;
use App\Services\Bot\Steps\ProductStep;
use App\Services\Bot\Steps\QuantityStep;
use App\Services\Bot\Steps\Step;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\Cache;

/**
 * Conversation state machine:
 *
 *   Menu --(any msg)--> Product --(list pick)--> Quantity --(1/2/3)--> Address --(text)--> Confirm
 *     ^                                                                                     |
 *     +------------------------------ Confirm: save order / Cancel ---------------------------+
 *
 * "menu"/"hi" (or a conversation idle for 24h+) restarts from any step.
 * Must run inside a TenantContext (ProcessIncomingMessage sets it).
 */
class BotEngine
{
    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 15;

    /**
     * @param  array<string, mixed>  $rawMessage  One item of value.messages[]
     * @param  array<int, array<string, mixed>>  $contacts  value.contacts[]
     */
    public function handle(WhatsAppAccount $account, array $rawMessage, array $contacts = []): void
    {
        $message = IncomingMessage::fromWebhook($rawMessage, $contacts);

        if ($message->isIgnorable()) {
            return;
        }

        // Serialise messages per customer so two quick taps can't corrupt the state.
        Cache::lock("bot:{$account->tenant_id}:{$message->from}", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->process($account, $message));
    }

    private function process(WhatsAppAccount $account, IncomingMessage $message): void
    {
        $client = WhatsAppClient::forAccount($account);

        if ($message->waMessageId !== null) {
            $client->markAsRead($message->waMessageId);
        }

        $state = ConversationState::firstOrCreate(
            ['phone' => $message->from],
            ['step' => ConversationStep::Menu, 'data' => []],
        );

        $wasIdleTooLong = $state->last_message_at !== null && ! $state->isWithinServiceWindow();

        // Opens/extends the 24h customer service window.
        $state->last_message_at = now();
        $state->save();

        $context = new BotContext(
            tenant: $account->tenant,
            client: $client,
            state: $state,
            customer: $this->resolveCustomer($message),
            message: $message,
        );

        if ($message->isResetCommand() || $wasIdleTooLong) {
            app(ProductStep::class)->prompt($context);

            return;
        }

        $this->stepFor($state->step)->handle($context);
    }

    private function resolveCustomer(IncomingMessage $message): Customer
    {
        $customer = Customer::firstOrCreate(['phone' => $message->from], ['name' => $message->contactName]);

        if ($customer->name === null && $message->contactName !== null) {
            $customer->update(['name' => $message->contactName]);
        }

        return $customer;
    }

    private function stepFor(?ConversationStep $step): Step
    {
        return app(match ($step) {
            ConversationStep::Product => ProductStep::class,
            ConversationStep::Quantity => QuantityStep::class,
            ConversationStep::Address => AddressStep::class,
            ConversationStep::Confirm => ConfirmStep::class,
            default => MenuStep::class,
        });
    }
}
