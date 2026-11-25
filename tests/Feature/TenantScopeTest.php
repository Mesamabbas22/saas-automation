<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();

        Product::factory()->count(3)->for($this->tenantA)->create();
        Product::factory()->count(2)->for($this->tenantB)->create();
    }

    public function test_queries_are_scoped_to_the_active_tenant(): void
    {
        app(TenantContext::class)->set($this->tenantA);

        $this->assertSame(3, Product::count());
        $this->assertTrue(Product::all()->every(fn (Product $product) => $product->tenant_id === $this->tenantA->id));
    }

    public function test_records_of_another_tenant_cannot_be_found_by_id(): void
    {
        $foreignProduct = Product::withoutTenantScope()->where('tenant_id', $this->tenantB->id)->first();

        app(TenantContext::class)->set($this->tenantA);

        $this->assertNull(Product::find($foreignProduct->id));
    }

    public function test_tenant_id_is_filled_automatically_on_create(): void
    {
        app(TenantContext::class)->set($this->tenantB);

        $product = Product::create(['name' => 'Auto filled', 'price' => 100]);

        $this->assertSame($this->tenantB->id, $product->tenant_id);
    }

    public function test_creating_a_record_for_another_tenant_is_rejected(): void
    {
        app(TenantContext::class)->set($this->tenantA);

        $this->expectException(LogicException::class);

        Product::create(['tenant_id' => $this->tenantB->id, 'name' => 'Sneaky', 'price' => 1]);
    }

    public function test_no_scope_is_applied_without_an_active_tenant(): void
    {
        $this->assertSame(5, Product::count());
    }

    public function test_run_restores_the_previous_tenant(): void
    {
        $context = app(TenantContext::class);
        $context->set($this->tenantA);

        $countInB = $context->run($this->tenantB, fn (): int => Product::count());

        $this->assertSame(2, $countInB);
        $this->assertSame($this->tenantA->id, $context->id());
    }

    public function test_order_and_customer_relations_stay_inside_the_tenant(): void
    {
        Order::factory()->for($this->tenantA)->create();
        Order::factory()->for($this->tenantB)->create();

        app(TenantContext::class)->set($this->tenantA);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Customer::count());
        $this->assertSame($this->tenantA->id, Order::first()->customer->tenant_id);
    }
}
