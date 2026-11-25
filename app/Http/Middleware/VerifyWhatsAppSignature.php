<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies Meta's X-Hub-Signature-256 header: "sha256=" + HMAC-SHA256(raw body, app secret).
 */
class VerifyWhatsAppSignature
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appSecret = (string) config('whatsapp.app_secret');

        if ($appSecret === '') {
            Log::error('WHATSAPP_APP_SECRET is not set; rejecting webhook.');

            abort(Response::HTTP_FORBIDDEN, 'Webhook not configured.');
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        if ($header === '' || ! hash_equals($expected, $header)) {
            Log::warning('WhatsApp webhook signature mismatch', ['ip' => $request->ip()]);

            abort(Response::HTTP_FORBIDDEN, 'Invalid signature.');
        }

        return $next($request);
    }
}
