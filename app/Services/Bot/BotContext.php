<?php

namespace App\Services\Bot;

use App\Enums\ConversationStep;
use App\Models\ConversationState;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppClient;

/**
 * Everything a step needs while handling one incoming message.
 */
final class BotContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly WhatsAppClient $client,
        public readonly ConversationState $state,
        public readonly Customer $customer,
        public readonly IncomingMessage $message,
    ) {}

    public function phone(): string
    {
        return $this->state->phone;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return ($this->state->data ?? [])[$key] ?? $default;
    }

    /**
     * Stage values in the conversation data; persisted by the next goTo()/reset().
     *
     * @param  array<string, mixed>  $data
     */
    public function remember(array $data): void
    {
        $this->state->data = array_merge($this->state->data ?? [], $data);
    }

    /**
     * Set which step handles the customer's NEXT message, and save.
     */
    public function goTo(ConversationStep $step): void
    {
        $this->state->step = $step;
        $this->state->save();
    }

    public function reset(): void
    {
        $this->state->step = ConversationStep::Menu;
        $this->state->data = [];
        $this->state->save();
    }
}
