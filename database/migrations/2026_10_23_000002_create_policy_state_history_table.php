<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail of every status change.
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor', 32); // api | callback | reconciler
            $table->string('reason', 255);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['policy_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
