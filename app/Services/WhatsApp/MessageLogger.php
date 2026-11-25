<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageDirection;
use App\Models\MessageLog;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Single place that writes message_logs. Must be called while TenantContext holds
 * the tenant (BelongsToTenant fills tenant_id).
 */
class MessageLogger
{
    /**
     * Delivery statuses in the order Meta can report them. A late "delivered"
     * webhook must not overwrite an earlier "read".
     *
     * @var array<string, int>
     */
    private const STATUS_RANK = [
        'queued' => 0,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
    ];

    /**
     * Log an incoming message. Returns null when this wa_message_id was already
     * logged, i.e. Meta re-delivered the webhook and the message must be ignored.
     *
     * @param  array<string, mixed>  $message  One item of value.messages[] from the webhook
     */
    public function logIncoming(array $message): ?MessageLog
    {
        $waMessageId = $message['id'] ?? null;

        if ($waMessageId !== null && $this->incomingExists($waMessageId)) {
            return null;
        }

        try {
            return MessageLog::create([
                'phone' => (string) ($message['from'] ?? ''),
                'direction' => MessageDirection::In,
                'type' => $message['type'] ?? 'unknown',
                'payload' => $message,
                'wa_message_id' => $waMessageId,
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two workers raced on the same webhook; the unique index decided the winner.
            return null;
        }
    }

    /**
     * The tenant is passed explicitly so the client also works where no TenantContext
     * is set (super admin actions, console). If a different tenant IS active,
     * BelongsToTenant refuses the write.
     *
     * @param  array<string, mixed>  $payload  Request body sent to the Graph API (or error details)
     */
    public function logOutgoing(int $tenantId, string $phone, string $type, array $payload, ?string $waMessageId, string $status): MessageLog
    {
        return MessageLog::create([
            'tenant_id' => $tenantId,
            'phone' => $phone,
            'direction' => MessageDirection::Out,
            'type' => $type,
            'payload' => $payload,
            'wa_message_id' => $waMessageId,
            'status' => $status,
        ]);
    }

    /**
     * Apply a delivery status webhook (sent / delivered / read / failed) to the outgoing log.
     *
     * @param  array<string, mixed>  $status  One item of value.statuses[] from the webhook
     */
    public function updateStatus(array $status): void
    {
        $log = MessageLog::query()
            ->where('direction', MessageDirection::Out)
            ->where('wa_message_id', $status['id'] ?? null)
            ->first();

        $newStatus = (string) ($status['status'] ?? '');

        if ($log === null || $newStatus === '' || ! $this->shouldReplaceStatus($log->status, $newStatus)) {
            return;
        }

        $payload = $log->payload ?? [];

        if (! empty($status['errors'])) {
            $payload['errors'] = $status['errors'];
        }

        $log->update(['status' => $newStatus, 'payload' => $payload]);
    }

    private function incomingExists(string $waMessageId): bool
    {
        return MessageLog::withoutTenantScope()
            ->where('direction', MessageDirection::In)
            ->where('wa_message_id', $waMessageId)
            ->exists();
    }

    private function shouldReplaceStatus(?string $current, string $new): bool
    {
        if ($new === 'failed') {
            return true;
        }

        if ($current === 'failed') {
            return false;
        }

        return (self::STATUS_RANK[$new] ?? -1) > (self::STATUS_RANK[$current] ?? -1);
    }
}
