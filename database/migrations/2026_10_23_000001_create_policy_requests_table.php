<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            // Public identifier used in URLs and sent to the insurer as the idempotency key.
            $table->string('uuid', 36)->unique();

            // Idempotency key from the agent. The unique index below is the real
            // duplicate guard; application checks are only an optimisation.
            $table->string('agent_id', 64);
            $table->string('client_request_id', 64);
            $table->string('payload_fingerprint', 64);

            $table->string('customer_name', 120);
            $table->date('date_of_birth');
            $table->string('destination', 20);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20);
            $table->unsignedInteger('sum_insured');

            // Immutable premium snapshot (see PolicyRequest::booted()).
            $table->unsignedSmallInteger('travel_days');
            $table->unsignedSmallInteger('age_at_trip_start');
            $table->decimal('premium', 12, 2);
            $table->json('premium_breakdown');

            $table->string('status', 32);
            $table->string('mock_scenario', 32)->nullable();

            $table->string('provider_reference', 64)->nullable()->unique();
            $table->string('policy_number', 64)->nullable()->unique();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->boolean('failure_retriable')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('last_response')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'client_request_id'], 'policy_requests_agent_client_unique');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
