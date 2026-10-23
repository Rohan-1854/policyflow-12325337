<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableSnapshotViolation;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\IntakeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PolicyRequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_request_is_created_with_expected_premium_and_status(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.agent_id', 'AGENT-17')
            ->assertJsonPath('data.client_request_id', 'AGT-2026-0001')
            ->assertJsonPath('data.premium.amount', '225.00')
            ->assertJsonPath('data.premium.currency', 'INR')
            ->assertJsonPath('data.premium.travel_days', 5)
            ->assertJsonPath('data.premium.age_at_trip_start', 28)
            ->assertJsonPath('data.premium.breakdown.base_daily_rate', '20.00')
            ->assertJsonPath('data.premium.breakdown.age_multiplier', '1.00')
            ->assertJsonPath('data.premium.breakdown.destination_multiplier', '1.80')
            ->assertJsonPath('data.premium.breakdown.coverage_multiplier', '1.25')
            ->assertJsonPath('data.policy_number', null)
            ->assertJsonPath('data.attempt_count', 0);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->json('data.id'));
        $this->assertSame(['RECEIVED', 'VALIDATED'], array_column($response->json('data.audit_trail'), 'to'));

        $stored = PolicyRequest::sole();
        $this->assertSame('225.00', (string) $stored->premium);
        $this->assertNotNull($stored->created_at);
        $this->assertNotNull($stored->updated_at);
    }

    public function test_codes_are_normalised_to_upper_case(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload(['plan_code' => 'plus', 'destination' => ' international ']))
            ->assertCreated()
            ->assertJsonPath('data.plan_code', 'PLUS')
            ->assertJsonPath('data.destination', 'INTERNATIONAL')
            ->assertJsonPath('data.premium.amount', '225.00');
    }

    public function test_trip_end_before_trip_start_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload(['trip_start' => '2026-11-14', 'trip_end' => '2026-11-10']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['trip_end'], 'error.details.fields');

        $this->assertDatabaseCount('policy_requests', 0);
    }

    public function test_malformed_and_impossible_dates_are_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload(['trip_start' => '2026-13-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trip_start'], 'error.details.fields');

        $this->postJson('/api/policy-requests', $this->validPayload(['date_of_birth' => now()->addYear()->format('Y-m-d')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_birth'], 'error.details.fields');

        // Born after the trip starts.
        $this->postJson('/api/policy-requests', $this->validPayload([
            'date_of_birth' => '2021-01-01', 'trip_start' => '2020-01-01', 'trip_end' => '2020-01-05',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['date_of_birth'], 'error.details.fields');

        // Longer than the supported maximum.
        $this->postJson('/api/policy-requests', $this->validPayload(['trip_start' => '2026-01-01', 'trip_end' => '2027-01-01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trip_end'], 'error.details.fields');
    }

    public function test_unsupported_plan_is_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload(['plan_code' => 'GOLD']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_code'], 'error.details.fields');

        $this->assertDatabaseCount('policy_requests', 0);
    }

    public function test_unsupported_coverage_and_destination_are_rejected(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload(['sum_insured' => 200000, 'destination' => 'MOON']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sum_insured', 'destination'], 'error.details.fields');
    }

    public function test_missing_fields_are_all_reported(): void
    {
        $this->postJson('/api/policy-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'client_request_id', 'agent_id', 'customer_name', 'date_of_birth',
                'destination', 'trip_start', 'trip_end', 'plan_code', 'sum_insured',
            ], 'error.details.fields');
    }

    public function test_repeated_submission_returns_the_same_request_without_creating_another(): void
    {
        $first = $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();

        $second = $this->postJson('/api/policy-requests', $this->validPayload())
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('meta.idempotent_replay', true)
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.premium.amount', '225.00');

        $this->postJson('/api/policy-requests', $this->validPayload())->assertOk();

        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertSame(2, PolicyStateHistory::count(), 'replays must not add audit rows');
        $this->assertSame($first->json('data.created_at'), $second->json('data.created_at'));
    }

    public function test_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $first = $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();

        $this->postJson('/api/policy-requests', $this->validPayload(['sum_insured' => 500000]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED')
            ->assertJsonPath('error.details.existing_id', $first->json('data.id'));

        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertSame('225.00', (string) PolicyRequest::sole()->premium);
    }

    public function test_the_same_client_request_id_from_another_agent_is_a_different_request(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload())->assertCreated();
        $this->postJson('/api/policy-requests', $this->validPayload(['agent_id' => 'AGENT-99']))->assertCreated();

        $this->assertDatabaseCount('policy_requests', 2);
    }

    /**
     * Near-simultaneous duplicates: both requests miss the "does it exist?"
     * lookup and race to INSERT. We reproduce the losing side directly: the
     * INSERT hits the unique index and must resolve to the existing request.
     */
    public function test_losing_a_concurrent_insert_race_returns_the_existing_request(): void
    {
        $service = app(IntakeService::class);
        $attributes = $service->buildAttributes($this->validPayload());

        $winner = $service->insertOrReplay($attributes);
        $loser = $service->insertOrReplay($attributes); // skips the pre-check, like a racing request

        $this->assertTrue($winner->created);
        $this->assertFalse($loser->created);
        $this->assertSame($winner->request->uuid, $loser->request->uuid);
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertSame(2, PolicyStateHistory::count(), 'the loser must leave no partial rows behind');
    }

    public function test_uniqueness_is_enforced_by_the_database_itself(): void
    {
        $request = $this->createRequest();
        $row = (array) DB::table('policy_requests')->where('id', $request->id)->first();
        unset($row['id']);
        $row['uuid'] = '00000000-0000-4000-8000-000000000000';

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('policy_requests')->insert($row);
    }

    public function test_premium_snapshot_is_immutable(): void
    {
        $request = $this->createRequest();
        $request->premium = '1.00';

        $this->expectException(ImmutableSnapshotViolation::class);

        $request->save();
    }

    public function test_mock_scenario_is_refused_when_the_mock_is_disabled(): void
    {
        config(['insurer.mock.enabled' => false]);

        $this->postJson('/api/policy-requests', $this->validPayload(['mock_scenario' => 'SUCCESS']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mock_scenario'], 'error.details.fields');
    }

    public function test_unknown_request_id_returns_404_envelope(): void
    {
        $this->getJson('/api/policy-requests/9b2b7d0e-0000-4000-8000-000000000000')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->getJson('/api/policy-requests/not-a-uuid')->assertNotFound();
    }
}
