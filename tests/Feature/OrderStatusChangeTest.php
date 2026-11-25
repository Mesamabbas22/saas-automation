<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\ConversationState;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.order_update_template' => 'order_status_update', 'whatsapp.template_language' => 'en']);

        $this->tenant = Tenant::factory()->create();
        WhatsAppAccount::factory()->for($this->tenant)->create(['phone_number_id' => 'PNID']);
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '923001234567']);
        $this->order = Order::factory()->for($this->tenant)->create([
            'customer_id' => $customer->id,
            'order_number' => 'ORD-TEST01',
        ]);
    }

    public function test_inside_24h_window_a_text_message_is_sent(): void
    {
        $this->fakeGraphSuccess();
        $this->customerMessagedHoursAgo(2);

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Dispatched);

        $this->assertSame(OrderStatus::Dispatched, $this->order->fresh()->status);
        $this->assertTrue($notification->successful());
        $this->assertFalse($notification->viaTemplate());
        Http::assertSent(fn (Request $request): bool => $request['type'] === 'text'
            && str_contains($request['text']['body'], 'ORD-TEST01')
            && str_contains($request['text']['body'], 'rawana'));
    }

    public function test_outside_24h_window_the_template_is_sent(): void
    {
        $this->fakeGraphSuccess();
        $this->customerMessagedHoursAgo(30);

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Delivered);

        $this->assertTrue($notification->viaTemplate());
        Http::assertSent(fn (Request $request): bool => $request['type'] === 'template'
            && $request['template']['name'] === 'order_status_update'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'ORD-TEST01'],
                ['type' => 'text', 'text' => 'Delivered'],
            ]);
    }

    public function test_customer_without_conversation_gets_the_template(): void
    {
        $this->fakeGraphSuccess();

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Confirmed);

        $this->assertTrue($notification->viaTemplate());
    }

    public function test_falls_back_to_template_when_meta_reports_the_window_closed(): void
    {
        $this->customerMessagedHoursAgo(1);
        Http::fake(fn (Request $request) => $request['type'] === 'text'
            ? Http::response(['error' => ['message' => 'Re-engagement message', 'code' => 131047]], 400)
            : Http::response(['messages' => [['id' => 'wamid.'.Str::random(20)]]]));

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Dispatched);

        $this->assertTrue($notification->successful());
        $this->assertTrue($notification->viaTemplate());
        Http::assertSentCount(2);
    }

    public function test_unchanged_status_sends_nothing(): void
    {
        Http::fake();

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Pending);

        $this->assertNull($notification);
        Http::assertNothingSent();
    }

    public function test_notify_can_be_switched_off(): void
    {
        Http::fake();

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Cancelled, notifyCustomer: false);

        $this->assertNull($notification);
        $this->assertSame(OrderStatus::Cancelled, $this->order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_missing_whatsapp_account_saves_status_and_reports_failure(): void
    {
        Http::fake();
        WhatsAppAccount::withoutTenantScope()->delete();

        $notification = app(OrderService::class)->changeStatus($this->order, OrderStatus::Confirmed);

        $this->assertSame(OrderStatus::Confirmed, $this->order->fresh()->status);
        $this->assertFalse($notification->successful());
        Http::assertNothingSent();
    }

    private function customerMessagedHoursAgo(int $hours): void
    {
        ConversationState::factory()->for($this->tenant)->create([
            'phone' => '923001234567',
            'last_message_at' => now()->subHours($hours),
        ]);
    }

    private function fakeGraphSuccess(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(20)]]]));
    }
}
