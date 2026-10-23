<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransition;
use App\Services\MockInsurer\MockInsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrencyAndStateGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_submit_while_one_is_in_flight_gets_409_and_no_provider_call(): void
    {
        $request = $this->createRequest();
        DB::table('policy_requests')->where('id', $request->id)->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'attempt_count' => 1]);

        $this->submit($request)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SUBMISSION_IN_PROGRESS');

        $this->assertNull($this->mockPolicyFor($request));
    }

    public function test_abandoned_submission_is_reconciled_not_reissued_when_insurer_already_issued(): void
    {
        $request = $this->createRequest();
        // The insurer issued it, then our worker died before recording the result.
        app(MockInsurer::class)->issue(['idempotency_key' => $request->uuid, 'scenario' => 'SUCCESS']);
        DB::table('policy_requests')->where('id', $request->id)->update([
            'status' => 'SUBMITTED', 'submitted_at' => now()->subMinutes(10), 'attempt_count' => 1,
        ]);

        $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('meta.provider_calls', 1);

        $this->assertSame(1, $this->mockPolicyFor($request)->issue_calls);
    }

    public function test_abandoned_submission_unknown_to_insurer_becomes_safely_retriable(): void
    {
        $request = $this->createRequest();
        DB::table('policy_requests')->where('id', $request->id)->update([
            'status' => 'SUBMITTED', 'submitted_at' => now()->subMinutes(10), 'attempt_count' => 1,
        ]);

        $this->submit($request)->assertStatus(502)->assertJsonPath('meta.retriable', true);
        $this->submit($request)->assertOk()->assertJsonPath('data.status', 'ISSUED');
    }

    public function test_model_rejects_regression_from_issued(): void
    {
        $request = $this->createRequest();
        $this->submit($request)->assertOk();
        $request->refresh();

        $this->expectException(InvalidStateTransition::class);

        $request->transitionTo(PolicyStatus::SUBMITTED, 'test', 'should fail');
    }
}
