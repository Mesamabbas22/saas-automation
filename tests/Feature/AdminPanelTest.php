<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\WhatsAppAccounts\Pages\EditWhatsAppAccount;
use App\Filament\Admin\Resources\WhatsAppAccounts\Pages\ListWhatsAppAccounts;
use App\Filament\Admin\Widgets\MessageUsageTable;
use App\Models\MessageLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
    }

    public function test_all_admin_pages_render(): void
    {
        $account = WhatsAppAccount::factory()->create();
        Subscription::factory()->create();

        $this->actingAs($this->admin);

        foreach ([
            '/admin',
            '/admin/tenants',
            '/admin/tenants/create',
            "/admin/tenants/{$account->tenant_id}/edit",
            '/admin/whatsapp-accounts',
            '/admin/whatsapp-accounts/create',
            "/admin/whatsapp-accounts/{$account->id}/edit",
            '/admin/plans',
            '/admin/subscriptions',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_sees_tenants_of_all_businesses(): void
    {
        $tenants = Tenant::factory()->count(3)->create();

        $this->bootAdminPanel();

        Livewire::test(ListTenants::class)->assertCanSeeTableRecords($tenants);
    }

    public function test_create_tenant_form_creates_tenant_owner_whatsapp_account_and_subscription(): void
    {
        $plan = Plan::factory()->create(['name' => 'Business', 'message_limit' => 5000]);

        $this->bootAdminPanel();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Peshawar Chappal House',
                'slug' => 'peshawar-chappal',
                'plan_id' => $plan->id,
                'status' => TenantStatus::Active->value,
                'owner' => ['name' => 'Gul Khan', 'email' => 'gul@chappal.test', 'password' => 'secret-pass'],
                'whatsapp' => ['phone_number_id' => '109999', 'waba_id' => '208888', 'access_token' => 'EAAG-real-token', 'verify_token' => 'vt-123'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::query()->where('slug', 'peshawar-chappal')->sole();
        $this->assertSame($plan->id, $tenant->plan_id);

        $owner = User::query()->where('email', 'gul@chappal.test')->sole();
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue(Hash::check('secret-pass', $owner->password));

        $account = WhatsAppAccount::withoutTenantScope()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('109999', $account->phone_number_id);
        $this->assertSame('EAAG-real-token', $account->access_token);
        $this->assertNotSame('EAAG-real-token', DB::table('whatsapp_accounts')->value('access_token'), 'Token must be encrypted at rest.');

        $subscription = Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_create_tenant_rejects_duplicate_owner_email_and_phone_number_id(): void
    {
        User::factory()->create(['email' => 'taken@test.pk']);
        WhatsAppAccount::factory()->create(['phone_number_id' => 'TAKEN']);

        $this->bootAdminPanel();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Dup Shop',
                'slug' => 'dup-shop',
                'status' => TenantStatus::Active->value,
                'owner' => ['name' => 'X', 'email' => 'taken@test.pk', 'password' => 'secret-pass'],
                'whatsapp' => ['phone_number_id' => 'TAKEN', 'access_token' => 'tok'],
            ])
            ->call('create')
            ->assertHasFormErrors(['owner.email' => 'unique', 'whatsapp.phone_number_id' => 'unique']);

        $this->assertFalse(Tenant::query()->where('slug', 'dup-shop')->exists());
    }

    public function test_newly_created_owner_can_open_their_business_panel(): void
    {
        $this->bootAdminPanel();

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Multan Sohan Halwa',
                'slug' => 'multan-halwa',
                'status' => TenantStatus::Active->value,
                'owner' => ['name' => 'Owner', 'email' => 'owner@halwa.test', 'password' => 'secret-pass'],
                'whatsapp' => ['phone_number_id' => '555', 'access_token' => 'tok'],
            ])
            ->call('create');

        $owner = User::query()->where('email', 'owner@halwa.test')->sole();

        $this->actingAs($owner)->get('/app/multan-halwa')->assertOk();
    }

    public function test_access_token_is_never_sent_to_the_browser(): void
    {
        $account = WhatsAppAccount::factory()->create(['access_token' => 'SUPER-SECRET-TOKEN-123']);

        $this->actingAs($this->admin)
            ->get("/admin/whatsapp-accounts/{$account->id}/edit")
            ->assertOk()
            ->assertDontSee('SUPER-SECRET-TOKEN-123');

        $this->actingAs($this->admin)
            ->get('/admin/whatsapp-accounts')
            ->assertDontSee('SUPER-SECRET-TOKEN-123');
    }

    public function test_saving_account_with_empty_token_keeps_the_existing_token(): void
    {
        $account = WhatsAppAccount::factory()->create(['access_token' => 'KEEP-ME', 'waba_id' => 'old']);

        $this->bootAdminPanel();

        Livewire::test(EditWhatsAppAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['waba_id' => 'new-waba', 'access_token' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('new-waba', $account->waba_id);
        $this->assertSame('KEEP-ME', $account->access_token);
    }

    public function test_saving_account_with_new_token_replaces_it(): void
    {
        $account = WhatsAppAccount::factory()->create(['access_token' => 'OLD']);

        $this->bootAdminPanel();

        Livewire::test(EditWhatsAppAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['access_token' => 'NEW-TOKEN'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('NEW-TOKEN', $account->fresh()->access_token);
    }

    public function test_test_connection_action_calls_graph_with_the_accounts_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'display_phone_number' => '+1 555 000 0000',
            'verified_name' => 'Test Biz',
            'quality_rating' => 'GREEN',
        ])]);
        $account = WhatsAppAccount::factory()->create(['phone_number_id' => 'PN1', 'access_token' => 'TOK1']);

        $this->bootAdminPanel();

        Livewire::test(ListWhatsAppAccounts::class)
            ->callAction(TestAction::make('testConnection')->table($account))
            ->assertNotified('Connected');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/PN1?')
            && $request->hasHeader('Authorization', 'Bearer TOK1'));
    }

    public function test_suspend_action_blocks_the_owner(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'to-suspend']);
        $owner = User::factory()->owner()->for($tenant)->create();

        $this->bootAdminPanel();

        Livewire::test(ListTenants::class)->callAction(TestAction::make('suspend')->table($tenant));

        $this->assertSame(TenantStatus::Suspended, $tenant->fresh()->status);
        $this->actingAs($owner)->get('/app/to-suspend')->assertForbidden();
    }

    public function test_active_subscription_sets_the_tenants_plan(): void
    {
        $tenant = Tenant::factory()->create();
        $pro = Plan::factory()->create(['name' => 'Pro']);

        Subscription::factory()->for($tenant)->for($pro)->create(['status' => SubscriptionStatus::Active]);

        $this->assertSame($pro->id, $tenant->fresh()->plan_id);
    }

    public function test_message_usage_counts_only_this_months_outgoing_messages(): void
    {
        $plan = Plan::factory()->create(['message_limit' => 10]);
        $busy = Tenant::factory()->create(['plan_id' => $plan->id]);
        $quiet = Tenant::factory()->create(['plan_id' => $plan->id]);

        MessageLog::factory()->outgoing()->for($busy)->count(9)->create();
        MessageLog::factory()->for($busy)->count(5)->create(); // incoming: not counted
        MessageLog::factory()->outgoing()->for($busy)->create(['created_at' => now()->subMonthNoOverflow()->startOfMonth()]); // last month
        MessageLog::factory()->outgoing()->for($quiet)->create();

        $busy = Tenant::query()->with('plan')->withMessagesThisMonth()->find($busy->id);
        $quiet = Tenant::query()->with('plan')->withMessagesThisMonth()->find($quiet->id);

        $this->assertSame(9, (int) $busy->messages_this_month);
        $this->assertSame(90, MessageUsageTable::usagePercent($busy));
        $this->assertSame(10, MessageUsageTable::usagePercent($quiet));

        $this->bootAdminPanel();
        Livewire::test(MessageUsageTable::class)
            ->assertCanSeeTableRecords([$busy, $quiet], inOrder: true);
    }

    public function test_usage_percent_is_null_without_a_plan(): void
    {
        $tenant = Tenant::query()->with('plan')->withMessagesThisMonth()->find(Tenant::factory()->create()->id);

        $this->assertNull(MessageUsageTable::usagePercent($tenant));
    }

    private function bootAdminPanel(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    }
}
