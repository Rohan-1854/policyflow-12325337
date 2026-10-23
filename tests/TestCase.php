<?php

namespace Tests;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * The example payload from the assignment brief (premium = 225.00).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    /**
     * Create a policy request through the public API and return it.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createRequest(array $overrides = []): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload($overrides));
        $response->assertCreated();

        return PolicyRequest::where('uuid', $response->json('data.id'))->firstOrFail();
    }

    protected function submit(PolicyRequest $request): TestResponse
    {
        return $this->postJson("/api/policy-requests/{$request->uuid}/submit");
    }

    protected function mockPolicyFor(PolicyRequest $request): ?MockInsurerPolicy
    {
        return MockInsurerPolicy::where('idempotency_key', $request->uuid)->first();
    }

    /**
     * Post a signed provider callback the way the insurer would.
     *
     * @param  array<string, mixed>  $event
     */
    protected function postSignedCallback(array $event, ?string $secret = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret ?? (string) config('insurer.webhook.secret'));

        return $this->call(
            'POST',
            '/api/mock-insurer/callback',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_INSURER_SIGNATURE' => $signature,
            ],
            $body,
        );
    }
}
