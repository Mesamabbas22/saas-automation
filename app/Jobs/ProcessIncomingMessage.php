<?php

namespace App\Jobs;

use App\Models\WhatsAppAccount;
use App\Services\Bot\BotEngine;
use App\Services\TenantContext;
use App\Services\WhatsApp\MessageLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one WhatsApp webhook payload in the background.
 *
 * A payload can hold several entries/changes, possibly for different phone numbers
 * (= different tenants). Each change is handled inside its own tenant context,
 * resolved from value.metadata.phone_number_id.
 */
class ProcessIncomingMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload  The raw webhook body
     */
    public function __construct(public array $payload) {}

    public function handle(TenantContext $tenantContext, MessageLogger $messageLogger): void
    {
        foreach ($this->payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $this->handleChange($change['value'] ?? [], $tenantContext, $messageLogger);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleChange(array $value, TenantContext $tenantContext, MessageLogger $messageLogger): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        $account = $phoneNumberId === null ? null : WhatsAppAccount::withoutTenantScope()
            ->with('tenant')
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if ($account === null) {
            Log::warning('WhatsApp webhook for unknown phone_number_id', ['phone_number_id' => $phoneNumberId]);

            return;
        }

        if (! $account->tenant->isActive()) {
            Log::info('WhatsApp webhook ignored: tenant is not active', ['tenant_id' => $account->tenant_id]);

            return;
        }

        $tenantContext->run($account->tenant, function () use ($value, $account, $messageLogger): void {
            foreach ($value['statuses'] ?? [] as $status) {
                $messageLogger->updateStatus($status);
            }

            foreach ($value['messages'] ?? [] as $message) {
                $log = $messageLogger->logIncoming($message);

                if ($log === null) {
                    continue; // duplicate delivery from Meta
                }

                $this->handleMessage($account, $message, $value['contacts'] ?? []);
            }
        });
    }

    /**
     * Hand a new (non-duplicate) message to the bot.
     *
     * @param  array<string, mixed>  $message
     * @param  array<int, array<string, mixed>>  $contacts
     */
    protected function handleMessage(WhatsAppAccount $account, array $message, array $contacts): void
    {
        app(BotEngine::class)->handle($account, $message, $contacts);
    }
}
