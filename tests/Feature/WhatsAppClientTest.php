<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Models\MessageLog;
use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class WhatsAppClientTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://graph.facebook.com/v99.0/PHONE123/messages';

    private WhatsAppAccount $account;

    private WhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.graph_version' => 'v99.0', 'whatsapp.template_language' => 'en']);
        Http::preventStrayRequests();

        $this->account = WhatsAppAccount::factory()->create([
            'phone_number_id' => 'PHONE123',
            'access_token' => 'tenant-secret-token',
        ]);
        $this->client = WhatsAppClient::forAccount($this->account);
    }

    public function test_send_text_posts_to_the_configured_graph_version_with_the_tenant_token(): void
    {
        $this->fakeSuccess('wamid.TEXT1');

        $result = $this->client->sendText('0300-1234567', 'Assalam o Alaikum!');

        $this->assertTrue($result->successful());
        $this->assertSame('wamid.TEXT1', $result->waMessageId);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::URL
            && $request->hasHeader('Authorization', 'Bearer tenant-secret-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === '923001234567'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Assalam o Alaikum!');
    }

    public function test_outgoing_message_is_logged_under_the_accounts_tenant(): void
    {
        $this->fakeSuccess('wamid.LOG1');

        $this->client->sendText('923001234567', 'Hi');

        $log = MessageLog::withoutTenantScope()->sole();
        $this->assertSame($this->account->tenant_id, $log->tenant_id);
        $this->assertSame(MessageDirection::Out, $log->direction);
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.LOG1', $log->wa_message_id);
        $this->assertSame('text', $log->type);
    }

    public function test_send_buttons_builds_reply_buttons_and_truncates_titles(): void
    {
        $this->fakeSuccess();

        $this->client->sendButtons('923001234567', 'Kitni quantity?', [
            'qty_1' => '1',
            'qty_2' => '2',
            'qty_3' => 'A title that is way too long for WhatsApp',
        ], header: 'Quantity', footer: 'Order Bot');

        Http::assertSent(function (Request $request): bool {
            $interactive = $request['interactive'];

            return $interactive['type'] === 'button'
                && $interactive['body']['text'] === 'Kitni quantity?'
                && $interactive['header'] === ['type' => 'text', 'text' => 'Quantity']
                && $interactive['footer'] === ['text' => 'Order Bot']
                && count($interactive['action']['buttons']) === 3
                && $interactive['action']['buttons'][0] === ['type' => 'reply', 'reply' => ['id' => 'qty_1', 'title' => '1']]
                && mb_strlen($interactive['action']['buttons'][2]['reply']['title']) <= 20;
        });
    }

    public function test_send_buttons_rejects_more_than_three_buttons(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->sendButtons('923001234567', 'Too many', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);
    }

    public function test_send_list_builds_sections_and_rows(): void
    {
        $this->fakeSuccess();

        $this->client->sendList('923001234567', 'Menu dekhein', 'Products', [
            ['title' => 'Food', 'rows' => [
                ['id' => 'product_1', 'title' => 'Chicken Biryani', 'description' => 'Rs 650'],
                ['id' => 'product_2', 'title' => 'Raita'],
            ]],
        ]);

        Http::assertSent(function (Request $request): bool {
            $action = $request['interactive']['action'];

            return $request['interactive']['type'] === 'list'
                && $action['button'] === 'Products'
                && $action['sections'][0]['title'] === 'Food'
                && $action['sections'][0]['rows'][0] === ['id' => 'product_1', 'title' => 'Chicken Biryani', 'description' => 'Rs 650']
                && $action['sections'][0]['rows'][1] === ['id' => 'product_2', 'title' => 'Raita'];
        });
    }

    public function test_send_list_rejects_more_than_ten_rows(): void
    {
        $rows = array_map(fn (int $i): array => ['id' => "p{$i}", 'title' => "P{$i}"], range(1, 11));

        $this->expectException(InvalidArgumentException::class);

        $this->client->sendList('923001234567', 'Body', 'View', [['rows' => $rows]]);
    }

    public function test_send_template_builds_body_parameters(): void
    {
        $this->fakeSuccess();

        $this->client->sendTemplate('923001234567', 'order_status_update', bodyParameters: ['ORD-ABC123', 'Dispatched']);

        Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
            && $request['template']['name'] === 'order_status_update'
            && $request['template']['language'] === ['code' => 'en']
            && $request['template']['components'] === [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => 'ORD-ABC123'],
                    ['type' => 'text', 'text' => 'Dispatched'],
                ],
            ]]);
    }

    public function test_mark_as_read_sends_read_status_and_is_not_logged_as_a_message(): void
    {
        Http::fake([self::URL => Http::response(['success' => true])]);

        $result = $this->client->markAsRead('wamid.INCOMING');

        $this->assertTrue($result->successful());
        Http::assertSent(fn (Request $request): bool => $request['status'] === 'read' && $request['message_id'] === 'wamid.INCOMING');
        $this->assertSame(0, MessageLog::withoutTenantScope()->count());
    }

    public function test_api_error_returns_failed_result_and_is_logged(): void
    {
        Log::shouldReceive('channel')->with('whatsapp')->once()->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'send:text')
            && $context['code'] === 131047
            && ! in_array('tenant-secret-token', $context, true));

        Http::fake([self::URL => Http::response([
            'error' => [
                'message' => 'Re-engagement message',
                'type' => 'OAuthException',
                'code' => 131047,
                'error_data' => ['details' => 'Message failed to send because more than 24 hours have passed.'],
                'fbtrace_id' => 'TRACE',
            ],
        ], 400)]);

        $result = $this->client->sendText('923001234567', 'Late reply');

        $this->assertTrue($result->failed());
        $this->assertTrue($result->isOutsideServiceWindow());
        $this->assertSame(400, $result->httpStatus);
        $this->assertStringContainsString('24 hours', $result->errorMessage);

        $log = MessageLog::withoutTenantScope()->sole();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->wa_message_id);
        $this->assertSame(131047, $log->payload['error']['code']);
    }

    public function test_invalid_token_is_detected(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

        $result = $this->client->sendText('923001234567', 'Hi');

        $this->assertTrue($result->isInvalidToken());
    }

    public function test_connection_error_returns_failed_result_instead_of_throwing(): void
    {
        Http::fake([self::URL => Http::failedConnection('Could not resolve host')]);

        $result = $this->client->sendText('923001234567', 'Hi');

        $this->assertTrue($result->failed());
        $this->assertStringContainsString('Could not resolve host', $result->errorMessage);
        $this->assertSame('failed', MessageLog::withoutTenantScope()->sole()->status);
    }

    public function test_for_tenant_uses_that_tenants_account(): void
    {
        $this->fakeSuccess();

        WhatsAppClient::forTenant($this->account->tenant)->sendText('923001234567', 'Hi');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::URL);
    }

    public function test_for_tenant_without_account_throws(): void
    {
        $this->expectException(RuntimeException::class);

        WhatsAppClient::forTenant(Tenant::factory()->create());
    }

    private function fakeSuccess(string $waMessageId = 'wamid.OK'): void
    {
        Http::fake([self::URL => Http::response([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '923001234567', 'wa_id' => '923001234567']],
            'messages' => [['id' => $waMessageId]],
        ])]);
    }
}
