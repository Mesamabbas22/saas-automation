<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingMessage;
use App\Models\MessageLog;
use App\Models\Tenant;
use App\Models\WhatsAppAccount;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsWhatsAppPayloads;
use Tests\TestCase;

class ProcessIncomingMessageTest extends TestCase
{
    use BuildsWhatsAppPayloads, RefreshDatabase;

    private WhatsAppAccount $accountA;

    private WhatsAppAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(20)]]]));

        $this->accountA = WhatsAppAccount::factory()->create(['phone_number_id' => '111']);
        $this->accountB = WhatsAppAccount::factory()->create(['phone_number_id' => '222']);
    }

    public function test_incoming_message_is_logged_under_the_tenant_of_the_phone_number_id(): void
    {
        $this->runJob($this->textMessagePayload('222', '923001112223', 'hi', 'wamid.ONE'));

        $log = MessageLog::withoutTenantScope()->where('direction', MessageDirection::In)->sole();
        $this->assertSame($this->accountB->tenant_id, $log->tenant_id);
        $this->assertSame(MessageDirection::In, $log->direction);
        $this->assertSame('923001112223', $log->phone);
        $this->assertSame('text', $log->type);
        $this->assertSame('wamid.ONE', $log->wa_message_id);
        $this->assertSame('hi', $log->payload['text']['body']);
    }

    public function test_duplicate_wa_message_id_is_ignored(): void
    {
        $payload = $this->textMessagePayload('111', '923001112223', 'hi', 'wamid.DUP');

        $this->runJob($payload);
        $this->runJob($payload);

        $this->assertSame(1, MessageLog::withoutTenantScope()->where('direction', MessageDirection::In)->count());
    }

    public function test_unknown_phone_number_id_is_ignored(): void
    {
        $this->runJob($this->textMessagePayload('999', '923001112223', 'hi'));

        $this->assertSame(0, MessageLog::withoutTenantScope()->count());
    }

    public function test_messages_for_a_suspended_tenant_are_ignored(): void
    {
        $suspended = WhatsAppAccount::factory()
            ->for(Tenant::factory()->suspended())
            ->create(['phone_number_id' => '333']);

        $this->runJob($this->textMessagePayload('333', '923001112223', 'hi'));

        $this->assertSame(0, MessageLog::withoutTenantScope()->where('tenant_id', $suspended->tenant_id)->count());
    }

    public function test_tenant_context_is_restored_after_the_job(): void
    {
        $this->runJob($this->textMessagePayload('111', '923001112223', 'hi'));

        $this->assertFalse(app(TenantContext::class)->has());
    }

    public function test_status_webhook_updates_the_outgoing_log_and_never_downgrades(): void
    {
        $log = MessageLog::factory()->outgoing()->for($this->accountA->tenant)->create(['wa_message_id' => 'wamid.OUT']);

        $this->runJob($this->statusPayload('111', 'wamid.OUT', 'read'));
        $this->assertSame('read', $log->fresh()->status);

        $this->runJob($this->statusPayload('111', 'wamid.OUT', 'delivered'));
        $this->assertSame('read', $log->fresh()->status);
    }

    public function test_status_webhook_cannot_touch_another_tenants_log(): void
    {
        $log = MessageLog::factory()->outgoing()->for($this->accountA->tenant)->create(['wa_message_id' => 'wamid.OUT']);

        // Status arrives on tenant B's number for tenant A's message id.
        $this->runJob($this->statusPayload('222', 'wamid.OUT', 'read'));

        $this->assertSame('sent', $log->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runJob(array $payload): void
    {
        app()->call([new ProcessIncomingMessage($payload), 'handle']);
    }
}
