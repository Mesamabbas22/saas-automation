<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessage;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    /**
     * GET /webhook — Meta's subscription handshake.
     *
     * PHP turns the "hub.mode" query key into "hub_mode", hence the underscores.
     */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($mode !== 'subscribe' || ! $this->isValidVerifyToken($token)) {
            return response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return response($challenge, Response::HTTP_OK)->header('Content-Type', 'text/plain');
    }

    /**
     * POST /webhook — signature already checked by VerifyWhatsAppSignature.
     * Answer 200 straight away and do the work in the queue, otherwise Meta retries.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->json()->all();

        if (($payload['object'] ?? null) === 'whatsapp_business_account') {
            ProcessIncomingMessage::dispatch($payload);
        }

        return response('EVENT_RECEIVED', Response::HTTP_OK);
    }

    /**
     * Accept the app-wide token from .env, or a tenant account's own verify_token.
     */
    private function isValidVerifyToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $globalToken = (string) config('whatsapp.verify_token');

        if ($globalToken !== '' && hash_equals($globalToken, $token)) {
            return true;
        }

        return WhatsAppAccount::withoutTenantScope()->where('verify_token', $token)->exists();
    }
}
