<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The simulated insurer's own book of policies. Conceptually this lives
        // in the insurer's system, not ours; it shares the database only to keep
        // local setup to a single command.
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique();
            $table->string('provider_reference', 64)->unique();
            $table->string('policy_number', 64)->nullable()->unique();
            $table->string('scenario', 32);
            $table->string('status', 16); // NOT_ISSUED | PENDING | ISSUED | DECLINED
            $table->unsignedSmallInteger('issue_calls')->default(0);
            $table->unsignedSmallInteger('lookup_calls')->default(0);
            $table->unsignedSmallInteger('callbacks_due')->default(0);
            $table->unsignedSmallInteger('callbacks_delivered')->default(0);
            $table->string('callback_event_id', 100)->nullable();
            $table->json('request_summary')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
