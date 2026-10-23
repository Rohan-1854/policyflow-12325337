<?php

namespace Tests\Feature;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceSuccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_provider_response_produces_exactly_one_policy_number(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'SUCCESS']);

        $response = $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 1)
            ->assertJsonPath('meta.provider_calls', 1);

        $policyNumber = $response->json('data.policy_number');
        $this->assertMatchesRegularExpression('/^TSP-\d{4}-\d{7}$/', $policyNumber);
        $this->assertNotNull($response->json('data.provider_reference'));
        $this->assertSame(['RECEIVED', 'VALIDATED', 'SUBMITTED', 'ISSUED'], array_column($response->json('data.audit_trail'), 'to'));
        $this->assertSame($policyNumber, $this->mockPolicyFor($request)->policy_number);
    }

    public function test_repeating_submit_on_an_issued_request_does_not_call_the_insurer_again(): void
    {
        $request = $this->createRequest();
        $policyNumber = $this->submit($request)->assertOk()->json('data.policy_number');

        foreach (range(1, 3) as $_) {
            $this->submit($request)
                ->assertOk()
                ->assertJsonPath('data.status', 'ISSUED')
                ->assertJsonPath('data.policy_number', $policyNumber)
                ->assertJsonPath('meta.provider_calls', 0);
        }

        $this->assertSame(1, $this->mockPolicyFor($request)->issue_calls);
        $this->assertSame(1, IssuanceAttempt::count());
        $this->assertSame(1, PolicyRequest::whereNotNull('policy_number')->count());
    }

    public function test_each_request_gets_a_distinct_policy_number(): void
    {
        $a = $this->createRequest(['client_request_id' => 'A-1']);
        $b = $this->createRequest(['client_request_id' => 'B-1']);

        $numberA = $this->submit($a)->json('data.policy_number');
        $numberB = $this->submit($b)->json('data.policy_number');

        $this->assertNotSame($numberA, $numberB);
    }

    public function test_read_endpoint_exposes_state_premium_references_and_audit_trail(): void
    {
        $request = $this->createRequest();
        $this->submit($request)->assertOk();

        $this->getJson("/api/policy-requests/{$request->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $request->uuid)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.premium.amount', '225.00')
            ->assertJsonStructure(['data' => [
                'provider_reference', 'policy_number', 'attempt_count', 'last_response',
                'audit_trail' => [['from', 'to', 'actor', 'reason', 'at']],
                'issuance_attempts' => [['sequence', 'kind', 'outcome', 'http_status', 'started_at', 'finished_at']],
                'provider_events',
            ]])
            ->assertJsonPath('data.issuance_attempts.0.kind', 'ISSUE')
            ->assertJsonPath('data.issuance_attempts.0.outcome', 'ISSUED');
    }

    public function test_a_callback_cannot_overwrite_a_confirmed_issued_policy(): void
    {
        $request = $this->createRequest();
        $policyNumber = $this->submit($request)->json('data.policy_number');
        $request->refresh();

        $this->postSignedCallback([
            'event_id' => 'evt-rogue-1',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $request->provider_reference,
            'policy_number' => 'TSP-9999-0000001',
        ])->assertOk()->assertJsonPath('data.result', 'CONFLICT_IGNORED');

        $this->postSignedCallback([
            'event_id' => 'evt-rogue-2',
            'event_type' => 'POLICY_DECLINED',
            'provider_reference' => $request->provider_reference,
        ])->assertOk()->assertJsonPath('data.result', 'CONFLICT_IGNORED');

        $request->refresh();
        $this->assertSame('ISSUED', $request->status->value);
        $this->assertSame($policyNumber, $request->policy_number);
    }
}
