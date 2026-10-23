<?php

namespace Tests\Feature;

use App\Models\MockInsurerPolicy;
use App\Services\MockInsurer\CallbackDispatcher;
use App\Services\MockInsurer\MockInsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_pending_request_whose_callback_was_lost_is_reconciled(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'DELAYED_CALLBACK']);
        $this->submit($request)->assertStatus(202);

        // The insurer confirms, but the callback never reaches us.
        $lost = new class implements CallbackDispatcher
        {
            public function dispatch(array $event): array
            {
                return ['transport' => 'lost', 'result' => null];
            }
        };
        app(MockInsurer::class)->deliverPendingCallbacks($lost);
        $this->assertSame(MockInsurerPolicy::STATUS_ISSUED, $this->mockPolicyFor($request)->status);
        $this->assertSame('PENDING_CONFIRMATION', $request->fresh()->status->value);

        // Not stale yet -> untouched.
        $this->artisan('policyflow:reconcile')->assertExitCode(0);
        $this->assertSame('PENDING_CONFIRMATION', $request->fresh()->status->value);

        $this->travel(20)->minutes();

        $this->artisan('policyflow:reconcile')->assertExitCode(0);

        $fresh = $request->fresh();
        $this->assertSame('ISSUED', $fresh->status->value);
        $this->assertSame($this->mockPolicyFor($request)->policy_number, $fresh->policy_number);
        $this->assertSame('reconciler', $fresh->history->last()->actor);
        $this->assertSame(1, $this->mockPolicyFor($request)->issue_calls);
    }
}
