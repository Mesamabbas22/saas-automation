<?php

namespace Tests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Builds webhook bodies shaped like Meta's WhatsApp Cloud API payloads.
 */
trait BuildsWhatsAppPayloads
{
    /**
     * @param  array<string, mixed>  $message  Overrides/extra keys for the message
     * @return array<string, mixed>
     */
    protected function textMessagePayload(string $phoneNumberId, string $from, string $body, ?string $waMessageId = null, array $message = []): array
    {
        return $this->messagesPayload($phoneNumberId, [
            array_merge([
                'from' => $from,
                'id' => $waMessageId ?? 'wamid.'.Str::random(24),
                'timestamp' => (string) now()->timestamp,
                'type' => 'text',
                'text' => ['body' => $body],
            ], $message),
        ], $from);
    }

    /**
     * @param  'button_reply'|'list_reply'  $kind
     * @return array<string, mixed>
     */
    protected function interactiveReplyPayload(string $phoneNumberId, string $from, string $kind, string $replyId, string $title = 'Reply'): array
    {
        return $this->messagesPayload($phoneNumberId, [[
            'from' => $from,
            'id' => 'wamid.'.Str::random(24),
            'timestamp' => (string) now()->timestamp,
            'type' => 'interactive',
            'interactive' => [
                'type' => $kind,
                $kind => ['id' => $replyId, 'title' => $title],
            ],
        ]], $from);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function messagesPayload(string $phoneNumberId, array $messages, string $from = '923001234567'): array
    {
        return $this->wrapValue($phoneNumberId, [
            'contacts' => [['profile' => ['name' => 'Test Customer'], 'wa_id' => $from]],
            'messages' => $messages,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function statusPayload(string $phoneNumberId, string $waMessageId, string $status, string $recipient = '923001234567'): array
    {
        return $this->wrapValue($phoneNumberId, [
            'statuses' => [[
                'id' => $waMessageId,
                'status' => $status,
                'timestamp' => (string) now()->timestamp,
                'recipient_id' => $recipient,
            ]],
        ]);
    }

    /**
     * Send a correctly signed POST /webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret ?? (string) config('whatsapp.app_secret'));

        return $this->call('POST', '/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function wrapValue(string $phoneNumberId, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => array_merge([
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15550000000',
                            'phone_number_id' => $phoneNumberId,
                        ],
                    ], $value),
                ]],
            ]],
        ];
    }
}
