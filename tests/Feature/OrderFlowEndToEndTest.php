<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsWhatsAppPayloads;
use Tests\TestCase;

/**
 * Customer orders over WhatsApp through the real, signed POST /webhook route
 * (queue runs synchronously in tests), with the Graph API mocked by Http::fake.
 * Then the owner dispatches the order from the panel.
 */
class OrderFlowEndToEndTest extends TestCase
{
    use BuildsWhatsAppPayloads, RefreshDatabase;

    private const PNID = '106540352242922';

    private const CUSTOMER = '923001234567';

    private const GRAPH = 'https://graph.facebook.com/v23.0/'.self::PNID.'/messages';

    private Tenant $tenant;

    private Product $karahi;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.app_secret' => 'e2e-app-secret',
            'whatsapp.graph_version' => 'v23.0',
            'whatsapp.graph_url' => 'https://graph.facebook.com',
        ]);

        Http::fake([self::GRAPH => fn () => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.OUT'.Str::random(20)]],
        ])]);

        $this->tenant = Tenant::factory()->create(['name' => 'Lahore Biryani House', 'slug' => 'lahore-biryani']);
        WhatsAppAccount::factory()->for($this->tenant)->create([
            'phone_number_id' => self::PNID,
            'access_token' => 'tenant-token',
        ]);
        $this->karahi = Product::factory()->for($this->tenant)->create(['name' => 'Chicken Karahi', 'price' => 1400]);
    }

    public function test_customer_orders_over_whatsapp_and_owner_dispatches_it(): void
    {
        $this->customerSays('Assalam o Alaikum');
        $this->customerPicks('list_reply', 'product_'.$this->karahi->id, 'Chicken Karahi');
        $this->customerPicks('button_reply', 'qty_3', '3');
        $this->customerSays('Flat 4, Block C, Gulberg III, Lahore');
        $this->customerPicks('button_reply', 'confirm_order', '✅ Confirm');

        // Order saved for the right tenant with a price snapshot.
        $order = Order::withoutTenantScope()->with('items', 'customer')->sole();
        $this->assertSame($this->tenant->id, $order->tenant_id);
        $this->assertSame('4200.00', $order->total);
        $this->assertSame(3, $order->items->sole()->quantity);
        $this->assertSame(self::CUSTOMER, $order->customer->phone);

        // Every reply went to the tenant's number, with the tenant's token.
        Http::assertSent(fn (Request $request): bool => $request->url() === self::GRAPH
            && $request->hasHeader('Authorization', 'Bearer tenant-token'));

        $sent = $this->sentMessages();
        $this->assertSame(['list', 'button', 'text', 'button', 'text'], array_map(
            fn (array $message): string => $message['interactive']['type'] ?? $message['type'],
            $sent,
        ));
        $this->assertStringContainsString($order->order_number, end($sent)['text']['body']);

        // The whole conversation is in message_logs: 5 in + 5 out.
        $this->assertSame(5, MessageLog::withoutTenantScope()->where('direction', MessageDirection::In)->count());
        $this->assertSame(5, MessageLog::withoutTenantScope()->where('direction', MessageDirection::Out)->count());

        // Owner dispatches it from the panel; customer is within 24h -> plain text.
        $this->actAsOwnerInPanel();
        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('changeStatus')->table($order), data: [
                'status' => OrderStatus::Dispatched->value,
                'notify_customer' => true,
            ])
            ->assertNotified();

        $this->assertSame(OrderStatus::Dispatched, $order->fresh()->status);
        $last = collect($this->sentMessages())->last();
        $this->assertSame('text', $last['type']);
        $this->assertStringContainsString('rawana', $last['text']['body']);
    }

    public function test_meta_redelivering_the_same_webhook_does_not_duplicate_replies_or_orders(): void
    {
        $this->customerSays('hi');
        $this->customerPicks('list_reply', 'product_'.$this->karahi->id);
        $this->customerPicks('button_reply', 'qty_1');
        $this->customerSays('Flat 4, Block C, Gulberg III, Lahore');

        $confirm = $this->interactiveReplyPayload(self::PNID, self::CUSTOMER, 'button_reply', 'confirm_order');
        $this->postSignedWebhook($confirm)->assertOk();
        $this->postSignedWebhook($confirm)->assertOk(); // retry from Meta, same wamid
        $this->postSignedWebhook($confirm)->assertOk();

        $this->assertSame(1, Order::withoutTenantScope()->count());
        $this->assertSame(5, count($this->sentMessages()));
    }

    public function test_unsigned_webhook_cannot_place_an_order(): void
    {
        $this->postJson('/webhook', $this->textMessagePayload(self::PNID, self::CUSTOMER, 'hi'))->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, MessageLog::withoutTenantScope()->count());
    }

    public function test_delivery_status_webhooks_update_the_outgoing_log(): void
    {
        $this->customerSays('hi');
        $outgoing = MessageLog::withoutTenantScope()->where('direction', MessageDirection::Out)->sole();

        $this->postSignedWebhook($this->statusPayload(self::PNID, $outgoing->wa_message_id, 'delivered'))->assertOk();
        $this->assertSame('delivered', $outgoing->fresh()->status);

        $this->postSignedWebhook($this->statusPayload(self::PNID, $outgoing->wa_message_id, 'read'))->assertOk();
        $this->assertSame('read', $outgoing->fresh()->status);
    }

    private function customerSays(string $text): void
    {
        $this->postSignedWebhook($this->textMessagePayload(self::PNID, self::CUSTOMER, $text))
            ->assertOk()
            ->assertSeeText('EVENT_RECEIVED');
    }

    /**
     * @param  'button_reply'|'list_reply'  $kind
     */
    private function customerPicks(string $kind, string $replyId, string $title = 'Reply'): void
    {
        $this->postSignedWebhook($this->interactiveReplyPayload(self::PNID, self::CUSTOMER, $kind, $replyId, $title))
            ->assertOk();
    }

    /**
     * Messages (not read receipts) sent to the Graph API, in order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sentMessages(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): array => $pair[0]->data())
            ->filter(fn (array $data): bool => isset($data['type']))
            ->values()
            ->all();
    }

    private function actAsOwnerInPanel(): void
    {
        $owner = User::factory()->owner()->for($this->tenant)->create();
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
        app(TenantContext::class)->set($this->tenant);
    }
}
