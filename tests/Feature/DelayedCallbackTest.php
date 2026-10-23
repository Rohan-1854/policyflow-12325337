<?php

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Services\Callbacks\ProviderCallbackService;
use App\Services\Insurer\InsurerGateway;
use App\Services\Insurer\IssueRequest;
use App\Services\Insurer\IssueResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ScriptedInsurerGateway;
use Tests\TestCase;

class DelayedCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_request_only_transitions_after_the_confirmation_event(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'DELAYED_CALLBACK']);

        $this->submit($request)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION')
            ->assertJsonPath('data.policy_number', null);

        // Re-submitting while pending must not hit the insurer.
        $this->submit($request)->assertStatus(202)->assertJsonPath('meta.provider_calls', 0);
        $this->assertSame(1, $this->mockPolicyFor($request)->issue_calls);

        $this->postJson('/api/mock-insurer/deliver-callbacks')
            ->assertOk()
            ->assertJsonPath('meta.deliveries', 1)
            ->assertJsonPath('data.0.result', 'APPLIED');

        $this->getJson("/api/policy-requests/{$request->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', $this->mockPolicyFor($request)->policy_number)
            ->assertJsonPath('data.provider_events.0.result', 'APPLIED');
    }

    /**
     * The confirmation callback arrives while our own issue() call is still
     * in flight. When the (PENDING) response finally comes back it must not
     * drag the already-ISSUED request back to PENDING_CONFIRMATION.
     */
    public function test_late_insurer_response_cannot_regress_an_issued_request(): void
    {
        $gateway = new ScriptedInsurerGateway(issueScript: [
            function (IssueRequest $issue): IssueResult {
                app(ProviderCallbackService::class)->process([
                    'event_id' => 'evt-race',
                    'event_type' => 'POLICY_ISSUED',
                    'provider_reference' => 'MIR-RACE',
                    'client_reference' => $issue->idempotencyKey,
                    'policy_number' => 'TSP-2026-0000321',
                ]);

                return new IssueResult(IssueResult::PENDING, 'MIR-RACE', null, 202, ['status' => 'PENDING']);
            },
        ]);
        $this->app->instance(InsurerGateway::class, $gateway);

        $request = $this->createRequest();

        $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', 'TSP-2026-0000321');

        $this->assertSame(PolicyStatus::ISSUED, $request->fresh()->status);
        $this->assertSame('PENDING', IssuanceAttempt::sole()->outcome->value, 'the attempt is still recorded');
    }
}
