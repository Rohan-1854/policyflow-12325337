<?php

namespace App\Services\MockInsurer;

use App\Enums\MockScenario;
use App\Models\MockInsurerPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic simulated insurer.
 *
 * It behaves like a well-behaved real insurer in one important way: it is
 * idempotent on `idempotency_key`. Once a key is ISSUED, every later issue
 * call returns the same policy. The scenario only controls what happens on
 * the way there (failures, timeouts, callbacks).
 *
 * | Scenario             | First issue call                     | Later issue calls     |
 * |----------------------|--------------------------------------|-----------------------|
 * | SUCCESS              | issue -> 201 ISSUED                  | 200 same policy       |
 * | TEMPORARY_FAILURE    | 503 for the first N calls            | then issue -> 201     |
 * | TIMEOUT_AFTER_ISSUE  | issue, then 504 (client sees nothing)| 200 same policy       |
 * | TIMEOUT_BEFORE_ISSUE | 504, nothing created                 | issue -> 201          |
 * | DELAYED_CALLBACK     | 202 PENDING, 1 callback later        | 202 PENDING           |
 * | DUPLICATE_CALLBACK   | 202 PENDING, same callback N times   | 202 PENDING           |
 * | PERMANENT_FAILURE    | 422 DECLINED                         | 422 DECLINED          |
 */
class MockInsurer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function issue(array $payload): MockInsurerResponse
    {
        $key = $payload['idempotency_key'] ?? null;

        if (! is_string($key) || $key === '' || strlen($key) > 64) {
            return new MockInsurerResponse(400, ['error' => 'BAD_REQUEST', 'message' => 'idempotency_key is required.', 'retriable' => false]);
        }

        $scenario = MockScenario::tryFrom((string) ($payload['scenario'] ?? ''))
            ?? MockScenario::tryFrom((string) config('insurer.mock.default_scenario'))
            ?? MockScenario::SUCCESS;

        return DB::transaction(function () use ($key, $scenario, $payload): MockInsurerResponse {
            $now = now();

            // Concurrency-safe "create if missing": the unique index decides.
            DB::table('mock_insurer_policies')->insertOrIgnore([
                'idempotency_key' => $key,
                'provider_reference' => self::providerReferenceFor($key),
                'scenario' => $scenario->value,
                'status' => MockInsurerPolicy::STATUS_NOT_ISSUED,
                'issue_calls' => 0,
                'lookup_calls' => 0,
                'callbacks_due' => 0,
                'callbacks_delivered' => 0,
                // The mock keeps no customer PII.
                'request_summary' => json_encode([
                    'plan_code' => $payload['plan_code'] ?? null,
                    'destination' => $payload['destination'] ?? null,
                    'sum_insured' => $payload['sum_insured'] ?? null,
                    'premium' => $payload['premium'] ?? null,
                    'trip_start' => $payload['trip_start'] ?? null,
                    'trip_end' => $payload['trip_end'] ?? null,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var MockInsurerPolicy $policy */
            $policy = MockInsurerPolicy::query()->where('idempotency_key', $key)->lockForUpdate()->firstOrFail();
            $policy->issue_calls++;

            $response = $this->respond($policy);
            $policy->save();

            return $response;
        });
    }

    public function lookup(string $idempotencyKey): MockInsurerResponse
    {
        return DB::transaction(function () use ($idempotencyKey): MockInsurerResponse {
            $policy = MockInsurerPolicy::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($policy === null) {
                return new MockInsurerResponse(404, ['status' => 'NOT_FOUND', 'idempotency_key' => $idempotencyKey]);
            }

            $policy->lookup_calls++;
            $policy->save();

            if ($policy->status === MockInsurerPolicy::STATUS_NOT_ISSUED) {
                return new MockInsurerResponse(404, ['status' => 'NOT_FOUND', 'idempotency_key' => $idempotencyKey]);
            }

            return new MockInsurerResponse(200, $this->describe($policy));
        });
    }

    /**
     * Confirm every PENDING policy and deliver its callback(s): once for
     * DELAYED_CALLBACK, N identical times for DUPLICATE_CALLBACK.
     *
     * @return list<array<string, mixed>> one entry per delivery
     */
    public function deliverPendingCallbacks(CallbackDispatcher $dispatcher, ?string $idempotencyKey = null): array
    {
        $deliveries = [];

        $query = MockInsurerPolicy::query()
            // ISSUED is included so an interrupted delivery run resumes.
            ->whereIn('status', [MockInsurerPolicy::STATUS_PENDING, MockInsurerPolicy::STATUS_ISSUED])
            ->whereColumn('callbacks_delivered', '<', 'callbacks_due')
            ->orderBy('id');

        if ($idempotencyKey !== null) {
            $query->where('idempotency_key', $idempotencyKey);
        }

        foreach ($query->pluck('id') as $id) {
            /** @var MockInsurerPolicy $policy */
            $policy = DB::transaction(function () use ($id): MockInsurerPolicy {
                $policy = MockInsurerPolicy::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                // The insurer decides: it confirms the policy now. The callback
                // event id is fixed, so every redelivery is byte-identical.
                $this->markIssued($policy);
                $policy->callback_event_id ??= 'evt_'.substr(hash('sha256', $policy->provider_reference.'|POLICY_ISSUED'), 0, 24);
                $policy->save();

                return $policy;
            });

            $event = [
                'event_id' => $policy->callback_event_id,
                'event_type' => 'POLICY_ISSUED',
                'provider_reference' => $policy->provider_reference,
                'client_reference' => $policy->idempotency_key,
                'policy_number' => $policy->policy_number,
                'occurred_at' => $policy->issued_at?->toIso8601String(),
            ];

            $remaining = $policy->callbacks_due - $policy->callbacks_delivered;

            for ($i = 0; $i < $remaining; $i++) {
                $result = $dispatcher->dispatch($event);
                MockInsurerPolicy::query()->whereKey($policy->id)->increment('callbacks_delivered');
                $deliveries[] = ['event_id' => $event['event_id'], 'delivery' => $i + 1] + $result;
            }
        }

        return $deliveries;
    }

    public static function providerReferenceFor(string $idempotencyKey): string
    {
        return 'MIR-'.strtoupper(substr(hash('sha256', 'mock-insurer|'.$idempotencyKey), 0, 16));
    }

    private function respond(MockInsurerPolicy $policy): MockInsurerResponse
    {
        // Idempotent replay of a known outcome, regardless of scenario.
        switch ($policy->status) {
            case MockInsurerPolicy::STATUS_ISSUED:
                return new MockInsurerResponse(200, $this->describe($policy) + ['replayed' => true]);
            case MockInsurerPolicy::STATUS_PENDING:
                return new MockInsurerResponse(202, $this->describe($policy) + ['replayed' => true]);
            case MockInsurerPolicy::STATUS_DECLINED:
                return $this->declined($policy);
        }

        $scenario = MockScenario::tryFrom($policy->scenario) ?? MockScenario::SUCCESS;

        switch ($scenario) {
            case MockScenario::TEMPORARY_FAILURE:
                if ($policy->issue_calls <= max(0, (int) config('insurer.mock.temporary_failures'))) {
                    return new MockInsurerResponse(503, [
                        'error' => 'TEMPORARY_UNAVAILABLE',
                        'message' => 'Insurer is temporarily unavailable. Nothing was created; please retry.',
                        'retriable' => true,
                    ]);
                }
                $this->markIssued($policy);

                return new MockInsurerResponse(201, $this->describe($policy));

            case MockScenario::TIMEOUT_AFTER_ISSUE:
                $this->markIssued($policy);

                // The policy now exists at the insurer, but the caller never hears about it.
                return new MockInsurerResponse(504, ['error' => 'GATEWAY_TIMEOUT', 'message' => 'Upstream timed out.']);

            case MockScenario::TIMEOUT_BEFORE_ISSUE:
                if ($policy->issue_calls === 1) {
                    return new MockInsurerResponse(504, ['error' => 'GATEWAY_TIMEOUT', 'message' => 'Upstream timed out.']);
                }
                $this->markIssued($policy);

                return new MockInsurerResponse(201, $this->describe($policy));

            case MockScenario::DELAYED_CALLBACK:
            case MockScenario::DUPLICATE_CALLBACK:
                $policy->status = MockInsurerPolicy::STATUS_PENDING;
                $policy->callbacks_due = $scenario === MockScenario::DUPLICATE_CALLBACK
                    ? max(2, (int) config('insurer.mock.duplicate_deliveries'))
                    : 1;

                return new MockInsurerResponse(202, $this->describe($policy) + [
                    'message' => 'Accepted. Confirmation will be sent to the callback URL.',
                ]);

            case MockScenario::PERMANENT_FAILURE:
                $policy->status = MockInsurerPolicy::STATUS_DECLINED;

                return $this->declined($policy);

            case MockScenario::SUCCESS:
            default:
                $this->markIssued($policy);

                return new MockInsurerResponse(201, $this->describe($policy));
        }
    }

    private function markIssued(MockInsurerPolicy $policy): void
    {
        if ($policy->status === MockInsurerPolicy::STATUS_ISSUED) {
            return;
        }

        $policy->status = MockInsurerPolicy::STATUS_ISSUED;
        // Derived from the row's auto-increment id, so it is unique by construction
        // (and also protected by a unique index).
        $policy->policy_number = sprintf('TSP-%s-%07d', now()->format('Y'), $policy->id);
        $policy->issued_at = now();
    }

    private function declined(MockInsurerPolicy $policy): MockInsurerResponse
    {
        return new MockInsurerResponse(422, [
            'error' => 'DECLINED',
            'status' => 'DECLINED',
            'message' => 'Underwriting declined this request.',
            'provider_reference' => $policy->provider_reference,
            'retriable' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(MockInsurerPolicy $policy): array
    {
        return [
            'status' => $policy->status,
            'idempotency_key' => $policy->idempotency_key,
            'provider_reference' => $policy->provider_reference,
            'policy_number' => $policy->policy_number,
            'issued_at' => $policy->issued_at?->toIso8601String(),
        ];
    }
}
