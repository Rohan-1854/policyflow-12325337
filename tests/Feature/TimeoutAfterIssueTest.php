<?php

namespace Tests\Feature;

use App\Models\IssuanceAttempt;
use App\Models\MockInsurerPolicy;
use App\Services\Insurer\Exceptions\InsurerLookupFailed;
use App\Services\Insurer\Exceptions\InsurerTimeout;
use App\Services\Insurer\InsurerGateway;
use App\Services\Insurer\LookupResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ScriptedInsurerGateway;
use Tests\TestCase;

/**
 * Reliability case: "Timeout after success".
 * The insurer created the policy but our call timed out. We must find out
 * via the status lookup instead of issuing again.
 */
class TimeoutAfterIssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeout_after_issue_is_reconciled_without_reissuing(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'TIMEOUT_AFTER_ISSUE']);

        $response = $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('meta.provider_calls', 2); // 1 issue (timed out) + 1 status lookup

        $mock = $this->mockPolicyFor($request);
        $this->assertSame(1, $mock->issue_calls, 'the insurer must be asked to issue only once');
        $this->assertSame(1, $mock->lookup_calls);
        $this->assertSame($mock->policy_number, $response->json('data.policy_number'));

        $this->assertSame(
            ['RECEIVED', 'VALIDATED', 'SUBMITTED', 'NEEDS_RECONCILIATION', 'ISSUED'],
            array_column($response->json('data.audit_trail'), 'to'),
        );
        $this->assertSame(
            [['ISSUE', 'TIMEOUT'], ['STATUS_LOOKUP', 'ISSUED']],
            IssuanceAttempt::orderBy('sequence')->get()->map(fn ($a) => [$a->kind, $a->outcome->value])->all(),
        );

        // A client retry after the timeout changes nothing.
        $this->submit($request)->assertOk()->assertJsonPath('meta.provider_calls', 0);
        $this->assertSame(1, $mock->fresh()->issue_calls);
        $this->assertSame(1, MockInsurerPolicy::count());
    }

    public function test_timeout_where_insurer_did_not_issue_allows_a_safe_retry(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'TIMEOUT_BEFORE_ISSUE']);

        $this->submit($request)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.failure.retriable', true)
            ->assertJsonPath('meta.retriable', true)
            ->assertJsonPath('meta.provider_calls', 2);

        $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 2);

        $mock = $this->mockPolicyFor($request);
        $this->assertSame(2, $mock->issue_calls);
        $this->assertSame(1, MockInsurerPolicy::count(), 'same idempotency key on the retry');
    }

    public function test_when_the_status_lookup_also_fails_we_wait_and_never_reissue(): void
    {
        $gateway = new ScriptedInsurerGateway(
            issueScript: [fn () => throw new InsurerTimeout('read timed out')],
            lookupScript: [
                fn () => throw new InsurerLookupFailed('status API unavailable'),
                fn () => new LookupResult(LookupResult::ISSUED, 'MIR-ABC', 'TSP-2026-0000042', 200, ['status' => 'ISSUED']),
            ],
        );
        $this->app->instance(InsurerGateway::class, $gateway);

        $request = $this->createRequest();

        $this->submit($request)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'NEEDS_RECONCILIATION')
            ->assertJsonPath('meta.provider_calls', 2);

        // Retrying the submit only re-checks the status; it does not issue.
        $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.policy_number', 'TSP-2026-0000042')
            ->assertJsonPath('meta.provider_calls', 1);

        $this->assertCount(1, $gateway->issueCalls);
        $this->assertSame(2, $gateway->lookupCalls);
    }
}
