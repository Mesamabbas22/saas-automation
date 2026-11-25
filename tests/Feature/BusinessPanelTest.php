<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Models\Customer;
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
use Tests\TestCase;

class BusinessPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'biryani']);
        $this->owner = User::factory()->owner()->for($this->tenant)->create();
        WhatsAppAccount::factory()->for($this->tenant)->create();
    }

    public function test_all_business_pages_render_for_the_owner(): void
    {
        $order = Order::factory()->for($this->tenant)->create();
        $product = Product::factory()->for($this->tenant)->create();
        $log = MessageLog::factory()->for($this->tenant)->create();

        $this->actingAs($this->owner);

        foreach ([
            '/app/biryani',
            '/app/biryani/orders',
            "/app/biryani/orders/{$order->id}",
            '/app/biryani/products',
            '/app/biryani/products/create',
            "/app/biryani/products/{$product->id}/edit",
            '/app/biryani/customers',
            "/app/biryani/customers/{$order->customer_id}",
            '/app/biryani/message-logs',
            "/app/biryani/message-logs/{$log->id}",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_orders_list_shows_only_this_tenants_orders(): void
    {
        $own = Order::factory()->for($this->tenant)->create(['order_number' => 'ORD-OWN111']);
        $foreign = Order::factory()->for(Tenant::factory())->create(['order_number' => 'ORD-FOREIGN']);

        $this->actingAs($this->owner)
            ->get('/app/biryani/orders')
            ->assertOk()
            ->assertSee($own->order_number)
            ->assertDontSee($foreign->order_number);
    }

    public function test_another_tenants_order_returns_404(): void
    {
        $foreign = Order::factory()->for(Tenant::factory())->create();

        $this->actingAs($this->owner)
            ->get("/app/biryani/orders/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_order_view_lists_items(): void
    {
        $order = Order::factory()->for($this->tenant)->create();
        $product = Product::factory()->for($this->tenant)->create(['name' => 'Chicken Biryani']);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'price' => 650]);

        $this->actingAs($this->owner)
            ->get("/app/biryani/orders/{$order->id}")
            ->assertOk()
            ->assertSee('Chicken Biryani')
            ->assertSee('1,300');
    }

    public function test_change_status_action_updates_order_and_messages_customer(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(20)]]]));
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '923001234567']);
        $order = Order::factory()->for($this->tenant)->create(['customer_id' => $customer->id]);

        $this->bootPanel();

        Livewire::test(ListOrders::class)
            ->callAction(TestAction::make('changeStatus')->table($order), data: [
                'status' => OrderStatus::Dispatched->value,
                'notify_customer' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(OrderStatus::Dispatched, $order->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request['to'] === '923001234567');
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        $pending = Order::factory()->for($this->tenant)->create();
        $delivered = Order::factory()->for($this->tenant)->status(OrderStatus::Delivered)->create();

        $this->bootPanel();

        Livewire::test(ListOrders::class)
            ->filterTable('status', [OrderStatus::Delivered->value])
            ->assertCanSeeTableRecords([$delivered])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_owner_can_create_a_product_for_their_tenant(): void
    {
        $this->bootPanel();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Beef Pulao',
                'description' => 'Yakhni wala',
                'price' => 700,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutTenantScope()->where('name', 'Beef Pulao')->sole();
        $this->assertSame($this->tenant->id, $product->tenant_id);
    }

    /**
     * Livewire::test() skips HTTP middleware, so set what the panel middleware would.
     */
    private function bootPanel(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->tenant);
        Filament::bootCurrentPanel();
        app(TenantContext::class)->set($this->tenant);
    }
}
