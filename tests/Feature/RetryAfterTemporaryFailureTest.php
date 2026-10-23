<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use App\Services\Insurer\Exceptions\InsurerRetriableFailure;
use App\Services\Insurer\InsurerGateway;
use App\Services\Insurer\IssueResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\ScriptedInsurerGateway;
use Tests\TestCase;

/**
 * Reliability case: "Retry after temporary failure".
 * Retries must not create duplicate policies or lose the accepted premium.
 */
class RetryAfterTemporaryFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_after_temporary_failure_issues_once_with_the_original_premium(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'TEMPORARY_FAILURE']);

        $this->submit($request)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.attempt_count', 1)
            ->assertJsonPath('data.failure.retriable', true)
            ->assertJsonPath('meta.retriable', true);

        $retry = $this->submit($request)
            ->assertOk()
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.attempt_count', 2)
            ->assertJsonPath('data.premium.amount', '225.00');

        // Further submits are no-ops.
        $this->submit($request)->assertOk()->assertJsonPath('meta.provider_calls', 0);

        $mock = $this->mockPolicyFor($request);
        $this->assertSame(2, $mock->issue_calls);
        $this->assertSame(1, MockInsurerPolicy::count(), 'both attempts used the same idempotency key');
        $this->assertSame('225.00', $mock->request_summary['premium']);
        $this->assertSame(1, PolicyRequest::whereNotNull('policy_number')->count());
        $this->assertSame(
            ['RECEIVED', 'VALIDATED', 'SUBMITTED', 'FAILED', 'SUBMITTED', 'ISSUED'],
            array_column($retry->json('data.audit_trail'), 'to'),
        );
    }

    public function test_every_attempt_sends_the_same_key_and_snapshot_premium(): void
    {
        $gateway = new ScriptedInsurerGateway(issueScript: [
            fn () => throw new InsurerRetriableFailure('503', 503),
            fn () => throw new InsurerRetriableFailure('503', 503),
            fn ($issue) => new IssueResult(IssueResult::ISSUED, 'MIR-1', 'TSP-2026-0000001', 201, []),
        ]);
        $this->app->instance(InsurerGateway::class, $gateway);

        $request = $this->createRequest();
        $this->submit($request)->assertStatus(502);
        $this->submit($request)->assertStatus(502);
        $this->submit($request)->assertOk()->assertJsonPath('data.status', 'ISSUED');

        $this->assertCount(3, $gateway->issueCalls);
        $this->assertSame([$request->uuid], array_values(array_unique(array_column($gateway->issueCalls, 'idempotency_key'))));
        $this->assertSame(['225.00'], array_values(array_unique(array_column($gateway->issueCalls, 'premium'))));
    }

    public function test_retries_stop_at_the_attempt_limit(): void
    {
        config(['insurer.mock.temporary_failures' => 10, 'insurer.issuance.max_attempts' => 2]);
        $request = $this->createRequest(['mock_scenario' => 'TEMPORARY_FAILURE']);

        $this->submit($request)->assertStatus(502);
        $this->submit($request)->assertStatus(502);
        $this->submit($request)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'RETRY_LIMIT_REACHED');

        $this->assertSame(2, $this->mockPolicyFor($request)->issue_calls);
    }

    public function test_permanent_decline_is_not_retried(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'PERMANENT_FAILURE']);

        $this->submit($request)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('meta.retriable', false);

        $this->submit($request)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUANCE_FAILED_PERMANENTLY');

        $this->assertSame(1, $this->mockPolicyFor($request)->issue_calls);
    }
}
