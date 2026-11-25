<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessage;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsWhatsAppPayloads;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use BuildsWhatsAppPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.verify_token' => 'global-verify-token',
            'whatsapp.app_secret' => 'test-app-secret',
        ]);
    }

    public function test_verification_returns_the_challenge_for_the_correct_token(): void
    {
        $this->get('/webhook?hub.mode=subscribe&hub.verify_token=global-verify-token&hub.challenge=1158201444')
            ->assertOk()
            ->assertSeeText('1158201444');
    }

    public function test_verification_accepts_a_tenant_accounts_verify_token(): void
    {
        WhatsAppAccount::factory()->create(['verify_token' => 'tenant-token']);

        $this->get('/webhook?hub.mode=subscribe&hub.verify_token=tenant-token&hub.challenge=42')
            ->assertOk()
            ->assertSeeText('42');
    }

    public function test_verification_rejects_a_wrong_token(): void
    {
        $this->get('/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')
            ->assertForbidden();
    }

    public function test_verification_rejects_a_wrong_mode(): void
    {
        $this->get('/webhook?hub.mode=unsubscribe&hub.verify_token=global-verify-token&hub.challenge=42')
            ->assertForbidden();
    }

    public function test_verification_rejects_an_empty_token_even_when_none_is_configured(): void
    {
        config(['whatsapp.verify_token' => '']);

        $this->get('/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=42')
            ->assertForbidden();
    }

    public function test_signed_post_returns_200_and_queues_the_job(): void
    {
        Queue::fake();
        $payload = $this->textMessagePayload('123', '923001234567', 'hi');

        $this->postSignedWebhook($payload)->assertOk()->assertSeeText('EVENT_RECEIVED');

        Queue::assertPushed(ProcessIncomingMessage::class, fn (ProcessIncomingMessage $job) => $job->payload === $payload);
    }

    public function test_post_without_signature_is_rejected(): void
    {
        Queue::fake();

        $this->postJson('/webhook', $this->textMessagePayload('123', '923001234567', 'hi'))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_post_with_wrong_signature_is_rejected(): void
    {
        Queue::fake();

        $this->postSignedWebhook($this->textMessagePayload('123', '923001234567', 'hi'), secret: 'some-other-secret')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_post_with_tampered_body_is_rejected(): void
    {
        Queue::fake();
        $body = json_encode($this->textMessagePayload('123', '923001234567', 'hi'));
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');
        $tampered = str_replace('"hi"', '"hacked"', $body);

        $this->call('POST', '/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $tampered)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_post_is_rejected_when_app_secret_is_not_configured(): void
    {
        Queue::fake();
        config(['whatsapp.app_secret' => '']);

        $this->postSignedWebhook($this->textMessagePayload('123', '923001234567', 'hi'), secret: '')
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_non_whatsapp_payloads_are_acknowledged_but_not_queued(): void
    {
        Queue::fake();

        $this->postSignedWebhook(['object' => 'page', 'entry' => []])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_webhook_is_excluded_from_csrf_protection(): void
    {
        Queue::fake();

        // No CSRF token is sent; a 419 here would mean CSRF still applies.
        $this->postSignedWebhook($this->textMessagePayload('123', '923001234567', 'hi'))->assertOk();
    }
}
