<?php

namespace Tests\Feature;

use App\Enums\ConversationStep;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Jobs\ProcessIncomingMessage;
use App\Models\ConversationState;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppPayloads;
use Tests\TestCase;

class BotFlowTest extends TestCase
{
    use BuildsWhatsAppPayloads, RefreshDatabase;

    private const PHONE_NUMBER_ID = 'PNID-A';

    private const CUSTOMER = '923001234567';

    private Tenant $tenant;

    private Product $biryani;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(24)]]]));

        $this->tenant = Tenant::factory()->create(['name' => 'Lahore Biryani House']);
        WhatsAppAccount::factory()->for($this->tenant)->create(['phone_number_id' => self::PHONE_NUMBER_ID]);

        $this->biryani = Product::factory()->for($this->tenant)->create(['name' => 'Chicken Biryani', 'price' => 650]);
        Product::factory()->for($this->tenant)->create(['name' => 'Raita', 'price' => 80]);
        Product::factory()->for($this->tenant)->inactive()->create(['name' => 'Hidden Item']);
    }

    public function test_full_order_flow_creates_the_order_and_sends_the_order_number(): void
    {
        $this->sendText('hi');
        $this->assertLastSentIsList();
        $this->assertStep(ConversationStep::Product);

        $this->pickFromList('product_'.$this->biryani->id);
        $this->assertLastSentButtons(['qty_1', 'qty_2', 'qty_3']);
        $this->assertStep(ConversationStep::Quantity);

        $this->tapButton('qty_2');
        $this->assertStringContainsString('delivery address', $this->lastSentBody());
        $this->assertStep(ConversationStep::Address);

        $this->sendText('House 12, Street 5, Model Town, Lahore');
        $this->assertLastSentButtons(['confirm_order', 'cancel_order']);
        $this->assertStringContainsString('Rs 1,300', $this->lastSentBody());
        $this->assertStep(ConversationStep::Confirm);

        $this->tapButton('confirm_order');

        $order = Order::withoutTenantScope()->sole();
        $this->assertSame($this->tenant->id, $order->tenant_id);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(PaymentMethod::Cod, $order->payment_method);
        $this->assertSame('1300.00', $order->total);
        $this->assertSame('House 12, Street 5, Model Town, Lahore', $order->address);
        $this->assertSame(self::CUSTOMER, $order->customer->phone);
        $this->assertSame('Test Customer', $order->customer->name);

        $item = $order->items()->sole();
        $this->assertSame($this->biryani->id, $item->product_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('650.00', $item->price);

        $this->assertStringContainsString($order->order_number, $this->lastSentBody());
        $this->assertStep(ConversationStep::Menu);
        $this->assertSame([], $this->state()->data);
    }

    public function test_product_list_contains_only_active_products_of_this_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        Product::factory()->for($otherTenant)->create(['name' => 'Other Tenant Product']);

        $this->sendText('salam');

        $rows = $this->lastSentPayload()['interactive']['action']['sections'][0]['rows'];
        $this->assertSame(['Chicken Biryani', 'Raita'], array_column($rows, 'title'));
        $this->assertSame('Rs 650 · '.$this->biryani->description, $rows[0]['description']);
    }

    public function test_cancel_resets_without_creating_an_order(): void
    {
        $this->walkToConfirm();

        $this->tapButton('cancel_order');

        $this->assertSame(0, Order::withoutTenantScope()->count());
        $this->assertStringContainsString('cancel', $this->lastSentBody());
        $this->assertStep(ConversationStep::Menu);
    }

    public function test_menu_resets_the_flow_at_any_step(): void
    {
        $this->sendText('hi');
        $this->pickFromList('product_'.$this->biryani->id);
        $this->tapButton('qty_1');
        $this->assertStep(ConversationStep::Address);

        $this->sendText('Menu');

        $this->assertLastSentIsList();
        $this->assertStep(ConversationStep::Product);
        $this->assertSame([], $this->state()->data);
    }

    public function test_product_from_another_tenant_cannot_be_selected(): void
    {
        $foreign = Product::factory()->for(Tenant::factory())->create();

        $this->sendText('hi');
        $this->pickFromList('product_'.$foreign->id);

        $this->assertStep(ConversationStep::Product);
        $this->assertStringContainsString('list mein se product', $this->lastSentPayload()['interactive']['body']['text']);
    }

    public function test_inactive_product_cannot_be_selected(): void
    {
        $hidden = Product::withoutTenantScope()->where('name', 'Hidden Item')->sole();

        $this->sendText('hi');
        $this->pickFromList('product_'.$hidden->id);

        $this->assertStep(ConversationStep::Product);
    }

    public function test_typed_quantity_is_accepted_and_invalid_quantity_reprompts(): void
    {
        $this->sendText('hi');
        $this->pickFromList('product_'.$this->biryani->id);

        $this->sendText('50');
        $this->assertStep(ConversationStep::Quantity);
        $this->assertLastSentButtons(['qty_1', 'qty_2', 'qty_3']);

        $this->sendText('5');
        $this->assertStep(ConversationStep::Address);
        $this->assertSame(5, $this->state()->data['quantity']);
    }

    public function test_too_short_address_is_rejected(): void
    {
        $this->sendText('hi');
        $this->pickFromList('product_'.$this->biryani->id);
        $this->tapButton('qty_1');

        $this->sendText('Lahore');

        $this->assertStep(ConversationStep::Address);
        $this->assertStringContainsString('mukammal', $this->lastSentBody());
    }

    public function test_product_deactivated_before_confirm_is_not_ordered(): void
    {
        $this->walkToConfirm();
        $this->biryani->update(['is_active' => false]);

        $this->tapButton('confirm_order');

        $this->assertSame(0, Order::withoutTenantScope()->count());
        $this->assertStep(ConversationStep::Product);
    }

    public function test_typed_yes_confirms_the_order(): void
    {
        $this->walkToConfirm();

        $this->sendText('Haan');

        $this->assertSame(1, Order::withoutTenantScope()->count());
    }

    public function test_last_message_at_tracks_the_24_hour_window(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->sendText('hi');

        $this->assertTrue($this->state()->last_message_at->equalTo(now()));
        $this->assertTrue($this->state()->isWithinServiceWindow());

        $this->travel(25)->hours();
        $this->assertFalse($this->state()->isWithinServiceWindow());
    }

    public function test_conversation_idle_for_more_than_24h_restarts(): void
    {
        $this->sendText('hi');
        $this->pickFromList('product_'.$this->biryani->id);
        $this->tapButton('qty_1');
        $this->assertStep(ConversationStep::Address);

        $this->travel(2)->days();
        $this->sendText('kya haal hai, order karna hai');

        $this->assertLastSentIsList();
        $this->assertStep(ConversationStep::Product);
    }

    public function test_incoming_message_is_marked_as_read(): void
    {
        $this->sendText('hi', 'wamid.INCOMING1');

        Http::assertSent(fn (Request $request): bool => ($request['status'] ?? null) === 'read'
            && $request['message_id'] === 'wamid.INCOMING1');
    }

    public function test_no_active_products_sends_a_sorry_message(): void
    {
        Product::withoutTenantScope()->update(['is_active' => false]);

        $this->sendText('hi');

        $this->assertStringContainsString('koi product available nahi', $this->lastSentBody());
        $this->assertStep(ConversationStep::Menu);
    }

    public function test_customer_is_created_once_per_tenant(): void
    {
        $this->sendText('hi');
        $this->sendText('menu');

        $this->assertSame(1, Customer::withoutTenantScope()->where('phone', self::CUSTOMER)->count());
    }

    public function test_reaction_messages_are_ignored(): void
    {
        $this->runJob($this->messagesPayload(self::PHONE_NUMBER_ID, [[
            'from' => self::CUSTOMER,
            'id' => 'wamid.REACT',
            'type' => 'reaction',
            'reaction' => ['message_id' => 'wamid.X', 'emoji' => '👍'],
        ]]));

        Http::assertNothingSent();
    }

    private function walkToConfirm(): void
    {
        $this->sendText('hi');
        $this->pickFromList('product_'.$this->biryani->id);
        $this->tapButton('qty_1');
        $this->sendText('House 12, Street 5, Model Town, Lahore');
        $this->assertStep(ConversationStep::Confirm);
    }

    private function sendText(string $body, ?string $waMessageId = null): void
    {
        $this->runJob($this->textMessagePayload(self::PHONE_NUMBER_ID, self::CUSTOMER, $body, $waMessageId));
    }

    private function pickFromList(string $replyId): void
    {
        $this->runJob($this->interactiveReplyPayload(self::PHONE_NUMBER_ID, self::CUSTOMER, 'list_reply', $replyId));
    }

    private function tapButton(string $replyId): void
    {
        $this->runJob($this->interactiveReplyPayload(self::PHONE_NUMBER_ID, self::CUSTOMER, 'button_reply', $replyId));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runJob(array $payload): void
    {
        app()->call([new ProcessIncomingMessage($payload), 'handle']);
    }

    private function state(): ConversationState
    {
        return ConversationState::withoutTenantScope()
            ->where('tenant_id', $this->tenant->id)
            ->where('phone', self::CUSTOMER)
            ->sole();
    }

    private function assertStep(ConversationStep $step): void
    {
        $this->assertSame($step, $this->state()->step);
    }

    /**
     * Body of the last *message* sent (read receipts excluded).
     *
     * @return array<string, mixed>
     */
    private function lastSentPayload(): array
    {
        $messages = Http::recorded()
            ->map(fn (array $pair): array => $pair[0]->data())
            ->filter(fn (array $data): bool => isset($data['type']));

        $this->assertNotEmpty($messages, 'No WhatsApp message was sent.');

        return $messages->last();
    }

    private function lastSentBody(): string
    {
        $payload = $this->lastSentPayload();

        return $payload['text']['body'] ?? $payload['interactive']['body']['text'] ?? '';
    }

    private function assertLastSentIsList(): void
    {
        $payload = $this->lastSentPayload();

        $this->assertSame('interactive', $payload['type']);
        $this->assertSame('list', $payload['interactive']['type']);
    }

    /**
     * @param  array<int, string>  $expectedIds
     */
    private function assertLastSentButtons(array $expectedIds): void
    {
        $payload = $this->lastSentPayload();

        $this->assertSame('button', $payload['interactive']['type'] ?? null);
        $this->assertSame($expectedIds, array_column(array_column($payload['interactive']['action']['buttons'], 'reply'), 'id'));
    }
}
