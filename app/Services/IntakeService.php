<?php

namespace App\Services;

use App\Domain\Pricing\PremiumCalculator;
use App\Enums\MockScenario;
use App\Enums\PolicyStatus;
use App\Exceptions\IdempotencyKeyConflict;
use App\Models\PolicyRequest;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates policy requests idempotently.
 *
 * Duplicate prevention is layered:
 *  1. A cheap lookup by (agent_id, client_request_id) handles ordinary retries.
 *  2. The UNIQUE index on those columns is the real guard. When two
 *     near-simultaneous requests both miss the lookup, exactly one INSERT
 *     wins; the loser gets a unique-constraint violation, which we turn into
 *     "return the existing request" (HTTP 200).
 *  3. A payload fingerprint detects a reused key with different data (409).
 */
class IntakeService
{
    public function __construct(private readonly PremiumCalculator $calculator)
    {
    }

    /**
     * @param  array<string, mixed>  $input  Validated request data.
     */
    public function intake(array $input): IntakeResult
    {
        $attributes = $this->buildAttributes($input);

        return $this->findReplay($attributes) ?? $this->insertOrReplay($attributes);
    }

    /**
     * Fast path for a repeated request.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function findReplay(array $attributes): ?IntakeResult
    {
        $existing = PolicyRequest::query()
            ->where('agent_id', $attributes['agent_id'])
            ->where('client_request_id', $attributes['client_request_id'])
            ->first();

        return $existing === null ? null : $this->replay($existing, $attributes['payload_fingerprint']);
    }

    /**
     * Insert, relying on the database to reject a concurrent duplicate.
     * Public so the race path can be exercised directly in tests.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function insertOrReplay(array $attributes): IntakeResult
    {
        try {
            // The transaction is closed (rolled back) before we catch, which
            // matters on PostgreSQL where a failed statement poisons the
            // surrounding transaction.
            $request = DB::transaction(function () use ($attributes): PolicyRequest {
                $request = PolicyRequest::create($attributes + ['status' => PolicyStatus::RECEIVED]);
                $request->recordInitialState('api', 'Policy request received');
                $request->transitionTo(PolicyStatus::VALIDATED, 'api', 'Validated and premium calculated', [
                    'premium' => $request->premium,
                    'travel_days' => $request->travel_days,
                ]);

                return $request;
            });

            return new IntakeResult($request, created: true);
        } catch (UniqueConstraintViolationException $e) {
            $replay = $this->findReplay($attributes);

            if ($replay === null) {
                throw $e; // some other unique column collided - a genuine error
            }

            return $replay;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function buildAttributes(array $input): array
    {
        $dob = $this->date($input['date_of_birth']);
        $start = $this->date($input['trip_start']);
        $end = $this->date($input['trip_end']);
        $sumInsured = (int) $input['sum_insured'];

        $quote = $this->calculator->quote(
            (string) $input['plan_code'],
            (string) $input['destination'],
            $sumInsured,
            $dob,
            $start,
            $end,
        );

        $business = [
            'customer_name' => (string) $input['customer_name'],
            'date_of_birth' => $dob->format('Y-m-d'),
            'destination' => $quote->destination,
            'trip_start' => $start->format('Y-m-d'),
            'trip_end' => $end->format('Y-m-d'),
            'plan_code' => $quote->planCode,
            'sum_insured' => $sumInsured,
        ];

        $scenario = null;
        if (config('insurer.mock.enabled')) {
            $scenario = MockScenario::tryFrom((string) ($input['mock_scenario'] ?? ''))
                ?? MockScenario::tryFrom((string) config('insurer.mock.default_scenario'))
                ?? MockScenario::SUCCESS;
        }

        return $business + [
            'agent_id' => (string) $input['agent_id'],
            'client_request_id' => (string) $input['client_request_id'],
            'payload_fingerprint' => self::fingerprint($business),
            'travel_days' => $quote->travelDays,
            'age_at_trip_start' => $quote->ageAtTripStart,
            'premium' => $quote->premium(),
            'premium_breakdown' => $quote->breakdown(),
            'mock_scenario' => $scenario,
            'attempt_count' => 0,
        ];
    }

    /**
     * SHA-256 over the normalised business fields. Used only for equality,
     * never displayed, so it does not expose the customer's data.
     *
     * @param  array<string, mixed>  $business
     */
    public static function fingerprint(array $business): string
    {
        ksort($business);

        return hash('sha256', json_encode($business, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function replay(PolicyRequest $existing, string $fingerprint): IntakeResult
    {
        if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyConflict($existing->uuid);
        }

        return new IntakeResult($existing, created: false);
    }

    private function date(mixed $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

        if ($date === false) {
            throw new \InvalidArgumentException('Dates must use the Y-m-d format.');
        }

        return $date;
    }
}
