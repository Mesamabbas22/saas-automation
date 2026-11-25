<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('direction', 3); // in | out
            $table->string('type', 30)->nullable();
            $table->json('payload')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->string('status', 20)->nullable();
            $table->timestamps();

            // DB-level guard against processing the same incoming message twice.
            $table->unique(['direction', 'wa_message_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};
