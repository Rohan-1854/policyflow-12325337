<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per outbound call to the insurer (issue or status lookup).
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained('policy_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 16); // ISSUE | STATUS_LOOKUP
            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
