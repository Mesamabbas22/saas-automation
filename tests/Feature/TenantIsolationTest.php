<?php

namespace Tests\Feature;

use App\Enums\ConversationStep;
use App\Enums\OrderStatus;
use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\MessageLogs\Pages\ListMessageLogs;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Widgets\OrderStatsOverview;
use App\Filament\App\Widgets\PendingOrdersTable;
use App\Jobs\ProcessIncomingMessage;
use App\Models\ConversationState;
use App\Models\Customer;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsWhatsAppPayloads;
use Tests\TestCase;

/**
 * Tenant A's users must never see or touch tenant B's data — in the panel, its
 * widgets, or through the bot.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsWhatsAppPayloads, RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $ownerA;

    /** @var array<string, Order|Product|Customer|MessageLog> */
    private array $recordsA = [];

    /** @var array<string, Order|Product|Customer|MessageLog> */
    private array $recordsB = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);
        $this->ownerA = User::factory()->owner()->for($this->tenantA)->create();

        $this->recordsA = $this->seedRecordsFor($this->tenantA, 'AAA');
        $this->recordsB = $this->seedRecordsFor($this->tenantB, 'BBB');
    }

    // ---------------------------------------------------------------------
    // Panel: URLs
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function recordPages(): array
    {
        return [
            'order view' => ['order', '/orders/{id}'],
            'product edit' => ['product', '/products/{id}/edit'],
            'customer view' => ['customer', '/customers/{id}'],
            'customer edit' => ['customer', '/customers/{id}/edit'],
            'message log view' => ['log', '/message-logs/{id}'],
        ];
    }

    #[DataProvider('recordPages')]
    public function test_owner_gets_404_for_another_tenants_record(string $key, string $path): void
    {
        $url = '/app/tenant-a'.str_replace('{id}', (string) $this->recordsB[$key]->id, $path);

        $this->actingAs($this->ownerA)->get($url)->assertNotFound();
    }

    #[DataProvider('recordPages')]
    public function test_owner_can_open_own_record(string $key, string $path): void
    {
        $url = '/app/tenant-a'.str_replace('{id}', (string) $this->recordsA[$key]->id, $path);

        $this->actingAs($this->ownerA)->get($url)->assertOk();
    }

    #[DataProvider('recordPages')]
    public function test_staff_gets_404_for_another_tenants_record(string $key, string $path): void
    {
        $staffA = User::factory()->staff()->for($this->tenantA)->create();
        $url = '/app/tenant-a'.str_replace('{id}', (string) $this->recordsB[$key]->id, $path);

        $this->actingAs($staffA)->get($url)->assertNotFound();
    }

    public function test_owner_cannot_reach_another_tenants_panel_by_changing_the_slug(): void
    {
        $this->actingAs($this->ownerA);

        foreach (['', '/orders', '/products', '/customers', '/message-logs', "/orders/{$this->recordsB['order']->id}"] as $path) {
            $this->get('/app/tenant-b'.$path)->assertNotFound();
        }
    }

    public function test_list_pages_render_only_own_records(): void
    {
        $this->actingAs($this->ownerA);

        $this->get('/app/tenant-a/orders')->assertOk()
            ->assertSee($this->recordsA['order']->order_number)
            ->assertDontSee($this->recordsB['order']->order_number);

        $this->get('/app/tenant-a/products')->assertOk()
            ->assertSee('Product AAA')
            ->assertDontSee('Product BBB');

        $this->get('/app/tenant-a/customers')->assertOk()
            ->assertSee('Customer AAA')
            ->assertDontSee('Customer BBB');
    }

    // ---------------------------------------------------------------------
    // Panel: Livewire tables & widgets
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function listPages(): array
    {
        return [
            'orders' => [ListOrders::class, 'order'],
            'products' => [ListProducts::class, 'product'],
            'customers' => [ListCustomers::class, 'customer'],
            'message logs' => [ListMessageLogs::class, 'log'],
        ];
    }

    /**
     * @param  class-string  $page
     */
    #[DataProvider('listPages')]
    public function test_livewire_tables_contain_only_own_records(string $page, string $key): void
    {
        $this->bootTenantPanel();

        Livewire::test($page)
            ->assertCanSeeTableRecords([$this->recordsA[$key]])
            ->assertCanNotSeeTableRecords([$this->recordsB[$key]]);
    }

    public function test_tables_stay_isolated_even_without_the_tenant_context(): void
    {
        // Defence in depth: Filament's own tenancy scope still applies if TenantContext is missing.
        $this->bootTenantPanel(setTenantContext: false);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$this->recordsA['order']])
            ->assertCanNotSeeTableRecords([$this->recordsB['order']]);
    }

    public function test_dashboard_stats_count_only_own_orders(): void
    {
        Order::factory()->for($this->tenantB)->count(3)->create(['total' => 33333]);
        $this->bootTenantPanel();

        Livewire::test(OrderStatsOverview::class)
            ->assertSeeText('Rs 1,000')
            ->assertDontSeeText('100,000');
    }

    public function test_pending_orders_widget_shows_only_own_orders(): void
    {
        $this->bootTenantPanel();

        Livewire::test(PendingOrdersTable::class)
            ->assertCanSeeTableRecords([$this->recordsA['order']])
            ->assertCanNotSeeTableRecords([$this->recordsB['order']]);
    }

    public function test_pending_orders_navigation_badge_counts_only_own_orders(): void
    {
        Order::factory()->for($this->tenantB)->count(4)->create();
        $this->bootTenantPanel();

        $this->assertSame('1', OrderResource::getNavigationBadge());
    }

    public function test_product_created_in_the_panel_belongs_to_the_current_tenant_only(): void
    {
        $this->bootTenantPanel();

        $product = Product::create(['name' => 'New in A', 'price' => 10]);

        $this->assertSame($this->tenantA->id, $product->tenant_id);
        app(TenantContext::class)->set($this->tenantB);
        $this->assertNull(Product::find($product->id));
    }

    // ---------------------------------------------------------------------
    // Bot
    // ---------------------------------------------------------------------

    public function test_same_customer_messaging_two_businesses_gets_separate_conversations_and_catalogs(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(24)]]]));
        WhatsAppAccount::factory()->for($this->tenantA)->create(['phone_number_id' => 'PN-A']);
        WhatsAppAccount::factory()->for($this->tenantB)->create(['phone_number_id' => 'PN-B']);
        $phone = '923331112222';

        $this->runJob($this->textMessagePayload('PN-A', $phone, 'hi'));
        $this->runJob($this->interactiveReplyPayload('PN-A', $phone, 'list_reply', 'product_'.$this->recordsA['product']->id));
        $this->runJob($this->textMessagePayload('PN-B', $phone, 'hi'));

        $states = ConversationState::withoutTenantScope()->where('phone', $phone)->get()->keyBy('tenant_id');
        $this->assertSame(ConversationStep::Quantity, $states[$this->tenantA->id]->step);
        $this->assertSame(ConversationStep::Product, $states[$this->tenantB->id]->step);

        $this->assertSame(2, Customer::withoutTenantScope()->where('phone', $phone)->count());

        $listSentByB = Http::recorded()
            ->map(fn (array $pair): array => $pair[0]->data())
            ->filter(fn (array $data): bool => ($data['interactive']['type'] ?? null) === 'list')
            ->last();
        $titles = array_column($listSentByB['interactive']['action']['sections'][0]['rows'], 'title');
        $this->assertSame(['Product BBB'], $titles);
    }

    public function test_bot_cannot_order_another_tenants_product(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(24)]]]));
        WhatsAppAccount::factory()->for($this->tenantA)->create(['phone_number_id' => 'PN-A']);
        $phone = '923331112222';

        $this->runJob($this->textMessagePayload('PN-A', $phone, 'hi'));
        $this->runJob($this->interactiveReplyPayload('PN-A', $phone, 'list_reply', 'product_'.$this->recordsB['product']->id));

        $state = ConversationState::withoutTenantScope()->where('tenant_id', $this->tenantA->id)->where('phone', $phone)->sole();
        $this->assertSame(ConversationStep::Product, $state->step);
        $this->assertArrayNotHasKey('product_id', $state->data ?? []);
    }

    /**
     * @return array{order: Order, product: Product, customer: Customer, log: MessageLog}
     */
    private function seedRecordsFor(Tenant $tenant, string $tag): array
    {
        $customer = Customer::factory()->for($tenant)->create(['name' => "Customer {$tag}"]);

        return [
            'customer' => $customer,
            'order' => Order::factory()->for($tenant)->create([
                'customer_id' => $customer->id,
                'order_number' => "ORD-{$tag}111",
                'total' => $tag === 'AAA' ? 1000 : 100000,
                'status' => OrderStatus::Pending,
            ]),
            'product' => Product::factory()->for($tenant)->create(['name' => "Product {$tag}"]),
            'log' => MessageLog::factory()->for($tenant)->create(['phone' => $customer->phone]),
        ];
    }

    private function bootTenantPanel(bool $setTenantContext = true): void
    {
        $this->actingAs($this->ownerA);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->tenantA);
        Filament::bootCurrentPanel();

        if ($setTenantContext) {
            app(TenantContext::class)->set($this->tenantA);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runJob(array $payload): void
    {
        app()->call([new ProcessIncomingMessage($payload), 'handle']);
    }
}
