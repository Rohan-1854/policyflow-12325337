<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inbox of insurer callbacks. The unique event_id makes processing
        // idempotent: a redelivered event fails the insert and is ignored.
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 40);
            $table->string('provider_reference', 64)->nullable()->index();
            $table->string('client_reference', 64)->nullable()->index();
            $table->foreignId('policy_request_id')->nullable()->constrained('policy_requests')->nullOnDelete();
            $table->json('payload');
            $table->string('processing_result', 32);
            $table->string('processing_note', 255)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
