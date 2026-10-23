<?php

namespace Tests\Feature;

use App\Services\Insurer\Exceptions\InsurerPermanentFailure;
use App\Services\Insurer\Exceptions\InsurerRetriableFailure;
use App\Services\Insurer\Exceptions\InsurerTimeout;
use App\Services\Insurer\HttpInsurerGateway;
use App\Services\Insurer\IssueRequest;
use App\Services\Insurer\LookupResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MockInsurerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_http_endpoints_issue_idempotently_and_expose_status_lookup(): void
    {
        $payload = ['idempotency_key' => 'key-123', 'scenario' => 'TIMEOUT_AFTER_ISSUE', 'premium' => '225.00'];

        $this->postJson('/api/mock-insurer/policies', $payload)->assertStatus(504);

        $lookup = $this->getJson('/api/mock-insurer/policies/key-123')
            ->assertOk()
            ->assertJsonPath('status', 'ISSUED');

        $this->postJson('/api/mock-insurer/policies', $payload)
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('policy_number', $lookup->json('policy_number'));

        $this->getJson('/api/mock-insurer/policies/unknown-key')->assertNotFound()->assertJsonPath('status', 'NOT_FOUND');
    }

    public function test_mock_endpoints_are_hidden_when_disabled(): void
    {
        config(['insurer.mock.enabled' => false]);

        $this->postJson('/api/mock-insurer/policies', ['idempotency_key' => 'k'])->assertNotFound();
    }

    public function test_http_gateway_maps_insurer_responses(): void
    {
        $request = $this->createRequest();
        $issue = IssueRequest::fromPolicyRequest($request);

        Http::fake([
            'insurer.test/policies' => Http::sequence()
                ->push(['status' => 'ISSUED', 'provider_reference' => 'MIR-1', 'policy_number' => 'TSP-1'], 201)
                ->push(['error' => 'TEMPORARY_UNAVAILABLE'], 503)
                ->push(['error' => 'DECLINED'], 422)
                ->push(['error' => 'GATEWAY_TIMEOUT'], 504),
            'insurer.test/policies/*' => Http::response(['status' => 'ISSUED', 'provider_reference' => 'MIR-1', 'policy_number' => 'TSP-1'], 200),
        ]);

        $gateway = new HttpInsurerGateway(Http::getFacadeRoot(), 'https://insurer.test', 2);

        $this->assertSame('TSP-1', $gateway->issue($issue)->policyNumber);

        foreach ([InsurerRetriableFailure::class, InsurerPermanentFailure::class, InsurerTimeout::class] as $expected) {
            try {
                $gateway->issue($issue);
                $this->fail("Expected {$expected}");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($expected, $e);
            }
        }

        $this->assertSame(LookupResult::ISSUED, $gateway->lookup($request->uuid)->status);

        Http::assertSent(fn ($sent) => $sent->hasHeader('Idempotency-Key', $request->uuid)
            && $sent['premium'] === '225.00');
    }

    public function test_http_gateway_treats_connection_errors_as_unknown_outcome(): void
    {
        $request = $this->createRequest();

        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $gateway = new HttpInsurerGateway(Http::getFacadeRoot(), 'https://insurer.test', 1);

        $this->expectException(InsurerTimeout::class);

        $gateway->issue(IssueRequest::fromPolicyRequest($request));
    }
}
