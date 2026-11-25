<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_admin_panel(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_super_admin_cannot_access_business_panel(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $tenant = Tenant::factory()->create();

        $this->actingAs($admin)->get("/app/{$tenant->slug}")->assertForbidden();
    }

    public function test_owner_cannot_access_admin_panel(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/admin')->assertForbidden();
    }

    public function test_owner_can_access_own_tenant_dashboard(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get("/app/{$owner->tenant->slug}")->assertOk();
    }

    public function test_staff_can_access_own_tenant_dashboard(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get("/app/{$staff->tenant->slug}")->assertOk();
    }

    public function test_owner_cannot_access_another_tenants_dashboard(): void
    {
        $owner = User::factory()->owner()->create();
        $otherTenant = Tenant::factory()->create();

        $this->actingAs($owner)->get("/app/{$otherTenant->slug}")->assertNotFound();
    }

    public function test_owner_of_suspended_tenant_cannot_access_business_panel(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $owner = User::factory()->owner()->for($tenant)->create();

        $this->actingAs($owner)->get("/app/{$tenant->slug}")->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/app')->assertRedirect('/app/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
