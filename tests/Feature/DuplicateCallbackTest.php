<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Models\ProviderEvent;
use App\Services\MockInsurer\CallbackDispatcher;
use App\Services\MockInsurer\MockInsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reliability case: "Duplicate callback".
 * The same provider event is delivered several times; it must be applied once.
 */
class DuplicateCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_callback_delivered_three_times_is_applied_once(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'DUPLICATE_CALLBACK']);

        $this->submit($request)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'PENDING_CONFIRMATION');

        $deliveries = app(MockInsurer::class)->deliverPendingCallbacks(app(CallbackDispatcher::class));

        $this->assertSame(['APPLIED', 'DUPLICATE_IGNORED', 'DUPLICATE_IGNORED'], array_column($deliveries, 'result'));

        $request->refresh();
        $this->assertSame('ISSUED', $request->status->value);
        $this->assertSame($this->mockPolicyFor($request)->policy_number, $request->policy_number);
        $this->assertSame(1, ProviderEvent::count());
        $this->assertSame(1, PolicyStateHistory::where('to_status', 'ISSUED')->count());
    }

    public function test_duplicate_http_deliveries_are_acknowledged_but_ignored(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'DELAYED_CALLBACK']);
        $this->submit($request)->assertStatus(202);
        $request->refresh();

        $event = [
            'event_id' => 'evt-duplicate-001',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $request->provider_reference,
            'client_reference' => $request->uuid,
            'policy_number' => 'TSP-2026-0000777',
        ];

        $this->postSignedCallback($event)->assertOk()->assertJsonPath('data.result', 'APPLIED')
            ->assertJsonPath('data.policy_request_status', 'ISSUED');
        $this->postSignedCallback($event)->assertOk()->assertJsonPath('data.result', 'DUPLICATE_IGNORED');
        $this->postSignedCallback($event)->assertOk()->assertJsonPath('data.result', 'DUPLICATE_IGNORED');

        $this->assertSame(1, ProviderEvent::count());
        $this->assertSame('TSP-2026-0000777', $request->fresh()->policy_number);
        $this->assertSame(1, PolicyStateHistory::where('to_status', 'ISSUED')->count());
    }

    public function test_same_confirmation_under_a_new_event_id_is_a_no_op(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'DELAYED_CALLBACK']);
        $this->submit($request);
        $request->refresh();

        $base = ['event_type' => 'POLICY_ISSUED', 'provider_reference' => $request->provider_reference, 'policy_number' => 'TSP-2026-0000100'];

        $this->postSignedCallback($base + ['event_id' => 'evt-a'])->assertJsonPath('data.result', 'APPLIED');
        $this->postSignedCallback($base + ['event_id' => 'evt-b'])->assertJsonPath('data.result', 'NO_CHANGE');

        $this->assertSame(1, PolicyStateHistory::where('to_status', 'ISSUED')->count());
    }

    public function test_unsigned_or_tampered_callbacks_are_rejected(): void
    {
        $request = $this->createRequest(['mock_scenario' => 'DELAYED_CALLBACK']);
        $this->submit($request);
        $request->refresh();

        $event = [
            'event_id' => 'evt-forged',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $request->provider_reference,
            'policy_number' => 'TSP-FORGED',
        ];

        $this->postJson('/api/mock-insurer/callback', $event)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->postSignedCallback($event, 'wrong-secret')->assertUnauthorized();

        $this->assertSame(0, ProviderEvent::count());
        $this->assertSame('PENDING_CONFIRMATION', $request->fresh()->status->value);
    }

    public function test_malformed_and_unmatched_callbacks(): void
    {
        $this->postSignedCallback(['event_id' => 'evt-x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_type'], 'error.details.fields');

        $this->postSignedCallback([
            'event_id' => 'evt-unknown',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => 'MIR-DOES-NOT-EXIST',
            'policy_number' => 'TSP-1',
        ])->assertStatus(202)->assertJsonPath('data.result', 'UNMATCHED');

        $this->assertSame('UNMATCHED', ProviderEvent::sole()->processing_result);
    }

    public function test_callback_that_overtakes_our_own_bookkeeping_is_matched_by_client_reference(): void
    {
        $request = $this->createRequest();
        // Simulate: we are mid-call (SUBMITTED, no provider_reference stored yet).
        DB::table('policy_requests')->where('id', $request->id)->update([
            'status' => 'SUBMITTED', 'submitted_at' => now(), 'attempt_count' => 1,
        ]);

        $this->postSignedCallback([
            'event_id' => 'evt-fast',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => 'MIR-FAST',
            'client_reference' => $request->uuid,
            'policy_number' => 'TSP-2026-0000555',
        ])->assertOk()->assertJsonPath('data.result', 'APPLIED');

        $fresh = PolicyRequest::find($request->id);
        $this->assertSame('ISSUED', $fresh->status->value);
        $this->assertSame('MIR-FAST', $fresh->provider_reference);
    }
}
