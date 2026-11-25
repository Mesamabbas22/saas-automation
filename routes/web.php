<?php

use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyWhatsAppSignature;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/webhook', [WebhookController::class, 'verify'])->name('webhook.verify');

Route::post('/webhook', [WebhookController::class, 'receive'])
    ->middleware(VerifyWhatsAppSignature::class)
    ->name('webhook.receive');
