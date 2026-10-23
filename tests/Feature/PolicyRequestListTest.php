<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyRequestListTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_supports_status_filter_and_pagination(): void
    {
        $issued = $this->createRequest(['client_request_id' => 'LIST-1']);
        $this->createRequest(['client_request_id' => 'LIST-2']);
        $this->createRequest(['client_request_id' => 'LIST-3', 'agent_id' => 'AGENT-2']);
        $this->submit($issued)->assertOk();

        $this->getJson('/api/policy-requests?status=ISSUED')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $issued->uuid)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.filters.status', 'ISSUED');

        $this->getJson('/api/policy-requests?status=VALIDATED')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/policy-requests?agent_id=AGENT-2')->assertJsonPath('meta.total', 1);

        $this->getJson('/api/policy-requests?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->getJson('/api/policy-requests?status=DONE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status'], 'error.details.fields');

        $this->getJson('/api/policy-requests?per_page=1000')->assertUnprocessable();
    }
}
