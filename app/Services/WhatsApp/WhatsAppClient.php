<?php

namespace App\Services\WhatsApp;

use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around the official WhatsApp Cloud API (Meta Graph API).
 *
 * One instance per WhatsApp account (= per tenant token):
 *   WhatsAppClient::forAccount($account)->sendText('923001234567', 'Salam!');
 *
 * Every outgoing message is written to message_logs (status "sent" or "failed").
 * API / network errors never throw: they return a failed SendResult and are logged
 * to storage/logs/whatsapp.log.
 */
class WhatsAppClient
{
    public const MAX_BUTTONS = 3;

    public const MAX_LIST_ROWS = 10;

    private const TEXT_MAX = 4096;

    private const INTERACTIVE_BODY_MAX = 1024;

    private const HEADER_MAX = 60;

    private const FOOTER_MAX = 60;

    private const BUTTON_TITLE_MAX = 20;

    private const LIST_BUTTON_MAX = 20;

    private const ROW_TITLE_MAX = 24;

    private const ROW_DESCRIPTION_MAX = 72;

    private const SECTION_TITLE_MAX = 24;

    public function __construct(
        private WhatsAppAccount $account,
        private MessageLogger $messageLogger,
    ) {}

    public static function forAccount(WhatsAppAccount $account): self
    {
        return app()->make(self::class, ['account' => $account]);
    }

    public static function forTenant(Tenant $tenant): self
    {
        $account = WhatsAppAccount::withoutTenantScope()->where('tenant_id', $tenant->getKey())->first();

        if ($account === null) {
            throw new RuntimeException("Tenant [{$tenant->getKey()}] has no WhatsApp account configured.");
        }

        return self::forAccount($account);
    }

    public function account(): WhatsAppAccount
    {
        return $this->account;
    }

    public function sendText(string $to, string $body, bool $previewUrl = false): SendResult
    {
        return $this->sendMessage($to, 'text', [
            'text' => [
                'preview_url' => $previewUrl,
                'body' => Str::limit($body, self::TEXT_MAX, ''),
            ],
        ]);
    }

    /**
     * Reply Buttons (max 3).
     *
     * @param  array<string, string>  $buttons  [id => title], e.g. ['qty_1' => '1', 'qty_2' => '2']
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null): SendResult
    {
        if ($buttons === [] || count($buttons) > self::MAX_BUTTONS) {
            throw new InvalidArgumentException('Reply buttons must have between 1 and '.self::MAX_BUTTONS.' buttons.');
        }

        $interactive = [
            'type' => 'button',
            'body' => ['text' => Str::limit($body, self::INTERACTIVE_BODY_MAX, '')],
            'action' => [
                'buttons' => collect($buttons)->map(fn (string $title, int|string $id): array => [
                    'type' => 'reply',
                    'reply' => ['id' => (string) $id, 'title' => Str::limit($title, self::BUTTON_TITLE_MAX, '')],
                ])->values()->all(),
            ],
        ];

        return $this->sendMessage($to, 'interactive', [
            'interactive' => $this->withHeaderAndFooter($interactive, $header, $footer),
        ]);
    }

    /**
     * List Message (max 10 rows across all sections).
     *
     * @param  array<int, array{title?: string, rows: array<int, array{id: string, title: string, description?: string}>}>  $sections
     */
    public function sendList(string $to, string $body, string $buttonText, array $sections, ?string $header = null, ?string $footer = null): SendResult
    {
        $rowCount = collect($sections)->sum(fn (array $section): int => count($section['rows'] ?? []));

        if ($rowCount === 0 || $rowCount > self::MAX_LIST_ROWS) {
            throw new InvalidArgumentException('A list message must have between 1 and '.self::MAX_LIST_ROWS.' rows.');
        }

        $interactive = [
            'type' => 'list',
            'body' => ['text' => Str::limit($body, self::INTERACTIVE_BODY_MAX, '')],
            'action' => [
                'button' => Str::limit($buttonText, self::LIST_BUTTON_MAX, ''),
                'sections' => collect($sections)->map(function (array $section): array {
                    $mapped = [
                        'rows' => collect($section['rows'])->map(fn (array $row): array => array_filter([
                            'id' => (string) $row['id'],
                            'title' => Str::limit($row['title'], self::ROW_TITLE_MAX, ''),
                            'description' => isset($row['description']) ? Str::limit($row['description'], self::ROW_DESCRIPTION_MAX, '') : null,
                        ], fn (?string $value): bool => $value !== null))->values()->all(),
                    ];

                    if (! empty($section['title'])) {
                        $mapped = ['title' => Str::limit($section['title'], self::SECTION_TITLE_MAX, '')] + $mapped;
                    }

                    return $mapped;
                })->values()->all(),
            ],
        ];

        return $this->sendMessage($to, 'interactive', [
            'interactive' => $this->withHeaderAndFooter($interactive, $header, $footer),
        ]);
    }

    /**
     * Pre-approved template; the only message type allowed outside the 24h window.
     *
     * @param  array<int, string>  $bodyParameters  Values for {{1}}, {{2}}, ... in the template body
     * @param  array<int, array<string, mixed>>  $extraComponents  Raw header/button components, if any
     */
    public function sendTemplate(string $to, string $templateName, ?string $languageCode = null, array $bodyParameters = [], array $extraComponents = []): SendResult
    {
        $components = $extraComponents;

        if ($bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], array_values($bodyParameters)),
            ];
        }

        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode ?? config('whatsapp.template_language')],
        ];

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->sendMessage($to, 'template', ['template' => $template]);
    }

    /**
     * Shows blue ticks on the customer's message. Not logged as a message.
     */
    public function markAsRead(string $waMessageId): SendResult
    {
        $result = $this->post([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
        ]);

        if ($result->failed()) {
            $this->logError('markAsRead', $result, ['message_id' => $waMessageId]);
        }

        return $result;
    }

    /**
     * GET the phone number's details; a cheap way to check the token and id are valid.
     * On success ->response holds display_phone_number, verified_name, quality_rating.
     */
    public function phoneNumberInfo(): SendResult
    {
        try {
            $response = $this->request()->get($this->phoneNumberUrl(), [
                'fields' => 'display_phone_number,verified_name,quality_rating',
            ]);
        } catch (Throwable $exception) {
            return SendResult::failure('Connection error: '.$exception->getMessage());
        }

        $result = $this->toResult($response);

        if ($result->failed()) {
            $this->logError('phoneNumberInfo', $result, []);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $content  Type-specific part of the body, e.g. ['text' => [...]]
     */
    private function sendMessage(string $to, string $type, array $content): SendResult
    {
        $to = PhoneNumber::normalize($to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            ...$content,
        ];

        $result = $this->post($payload);

        if ($result->failed()) {
            $this->logError("send:{$type}", $result, ['to' => $to]);
        }

        $this->messageLogger->logOutgoing(
            tenantId: $this->account->tenant_id,
            phone: $to,
            type: $type,
            payload: $result->failed() ? $payload + ['error' => $this->errorDetails($result)] : $payload,
            waMessageId: $result->waMessageId,
            status: $result->successful() ? 'sent' : 'failed',
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): SendResult
    {
        try {
            $response = $this->request()->post($this->messagesUrl(), $payload);
        } catch (ConnectionException $exception) {
            return SendResult::failure('Connection error: '.$exception->getMessage());
        } catch (Throwable $exception) {
            return SendResult::failure('Unexpected error: '.$exception->getMessage());
        }

        return $this->toResult($response);
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->account->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('whatsapp.timeout', 15))
            ->retry(2, 300, fn (Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);
    }

    private function phoneNumberUrl(): string
    {
        return sprintf(
            '%s/%s/%s',
            rtrim((string) config('whatsapp.graph_url'), '/'),
            config('whatsapp.graph_version'),
            $this->account->phone_number_id,
        );
    }

    private function messagesUrl(): string
    {
        return $this->phoneNumberUrl().'/messages';
    }

    private function toResult(Response $response): SendResult
    {
        $body = $response->json() ?? [];

        if ($response->successful()) {
            return SendResult::success($body['messages'][0]['id'] ?? null, $response->status(), $body);
        }

        $error = $body['error'] ?? [];

        return SendResult::failure(
            errorMessage: $error['error_data']['details'] ?? $error['message'] ?? "HTTP {$response->status()}",
            errorCode: isset($error['code']) ? (int) $error['code'] : null,
            httpStatus: $response->status(),
            response: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $interactive
     * @return array<string, mixed>
     */
    private function withHeaderAndFooter(array $interactive, ?string $header, ?string $footer): array
    {
        if ($header !== null && $header !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => Str::limit($header, self::HEADER_MAX, '')];
        }

        if ($footer !== null && $footer !== '') {
            $interactive['footer'] = ['text' => Str::limit($footer, self::FOOTER_MAX, '')];
        }

        return $interactive;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorDetails(SendResult $result): array
    {
        return [
            'message' => $result->errorMessage,
            'code' => $result->errorCode,
            'http_status' => $result->httpStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logError(string $operation, SendResult $result, array $context): void
    {
        Log::channel('whatsapp')->error("WhatsApp API {$operation} failed", [
            'tenant_id' => $this->account->tenant_id,
            'phone_number_id' => $this->account->phone_number_id,
            ...$context,
            ...$this->errorDetails($result),
            'fbtrace_id' => $result->response['error']['fbtrace_id'] ?? null,
        ]);
    }
}
