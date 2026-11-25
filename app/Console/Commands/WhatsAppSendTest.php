<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\SendResult;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:send-test {tenant : Tenant slug} {to : Recipient phone, e.g. 923001234567} {--type=text : text|buttons|list|template} {--message=Assalam o Alaikum! Test message from Order Bot.}')]
#[Description('Send a test WhatsApp message through a tenant\'s Cloud API account')]
class WhatsAppSendTest extends Command
{
    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $client = WhatsAppClient::forTenant($tenant);
        $to = (string) $this->argument('to');
        $message = (string) $this->option('message');

        $result = match ($this->option('type')) {
            'buttons' => $client->sendButtons($to, $message, ['qty_1' => '1', 'qty_2' => '2', 'qty_3' => '3']),
            'list' => $client->sendList($to, $message, 'Products', [[
                'title' => 'Demo',
                'rows' => [
                    ['id' => 'demo_1', 'title' => 'Demo product 1', 'description' => 'Rs 500'],
                    ['id' => 'demo_2', 'title' => 'Demo product 2', 'description' => 'Rs 900'],
                ],
            ]]),
            'template' => $client->sendTemplate($to, 'hello_world', 'en_US'),
            default => $client->sendText($to, $message),
        };

        return $this->report($result);
    }

    private function report(SendResult $result): int
    {
        if ($result->successful()) {
            $this->info("Sent. wa_message_id: {$result->waMessageId}");

            return self::SUCCESS;
        }

        $this->error("Failed ({$result->httpStatus}, code {$result->errorCode}): {$result->errorMessage}");

        if ($result->isOutsideServiceWindow()) {
            $this->line('The recipient has not messaged you in the last 24h. Use --type=template, or send "hi" from that phone first.');
        }

        return self::FAILURE;
    }
}
