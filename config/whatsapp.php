<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta WhatsApp Cloud API
    |--------------------------------------------------------------------------
    |
    | App-level settings. Per-tenant settings (phone_number_id, access token)
    | live in the whatsapp_accounts table.
    |
    */

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),

    /** Meta App Secret, used to verify the X-Hub-Signature-256 header on webhooks. */
    'app_secret' => env('WHATSAPP_APP_SECRET'),

    /** Token you type in the Meta dashboard when subscribing the webhook. */
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 15),

    /** Approved utility template used for order updates outside the 24h window. */
    'order_update_template' => env('WHATSAPP_ORDER_UPDATE_TEMPLATE', 'order_status_update'),

    'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Demo seed data (local only)
    |--------------------------------------------------------------------------
    |
    | Lets the seeder attach your Meta test number to the first demo tenant.
    |
    */

    'demo' => [
        'phone_number_id' => env('DEMO_WA_PHONE_NUMBER_ID') ?: 'demo-phone-number-id-1',
        'waba_id' => env('DEMO_WA_WABA_ID') ?: 'demo-waba-id-1',
        'access_token' => env('DEMO_WA_ACCESS_TOKEN') ?: 'replace-with-real-token',
    ],

];
