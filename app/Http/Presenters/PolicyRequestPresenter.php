<?php

namespace App\Http\Presenters;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Models\ProviderEvent;

/**
 * The documented JSON shape of a policy request (see README "API").
 */
final class PolicyRequestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(PolicyRequest $request): array
    {
        return [
            'id' => $request->uuid,
            'agent_id' => $request->agent_id,
            'client_request_id' => $request->client_request_id,
            'status' => $request->status->value,
            'customer_name' => $request->customer_name,
            'date_of_birth' => $request->date_of_birth->format('Y-m-d'),
            'destination' => $request->destination,
            'trip_start' => $request->trip_start->format('Y-m-d'),
            'trip_end' => $request->trip_end->format('Y-m-d'),
            'plan_code' => $request->plan_code,
            'sum_insured' => $request->sum_insured,
            'premium' => [
                'amount' => (string) $request->premium,
                'currency' => 'INR',
                'travel_days' => $request->travel_days,
                'age_at_trip_start' => $request->age_at_trip_start,
            ],
            'provider_reference' => $request->provider_reference,
            'policy_number' => $request->policy_number,
            'attempt_count' => $request->attempt_count,
            'failure' => $request->last_error === null ? null : [
                'message' => $request->last_error,
                'retriable' => $request->failure_retriable,
            ],
            'mock_scenario' => $request->mock_scenario?->value,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'issued_at' => $request->issued_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Summary plus premium breakdown, last insurer response and the audit trail.
     *
     * @return array<string, mixed>
     */
    public static function detail(PolicyRequest $request): array
    {
        $request->loadMissing(['history', 'attempts', 'providerEvents']);

        $data = self::summary($request);
        $data['premium']['breakdown'] = $request->premium_breakdown;
        $data['last_response'] = $request->last_response;

        $data['audit_trail'] = $request->history->map(fn (PolicyStateHistory $h): array => [
            'from' => $h->from_status,
            'to' => $h->to_status,
            'actor' => $h->actor,
            'reason' => $h->reason,
            'context' => $h->context,
            'at' => $h->created_at?->toIso8601String(),
        ])->values()->all();

        $data['issuance_attempts'] = $request->attempts->map(fn (IssuanceAttempt $a): array => [
            'sequence' => $a->sequence,
            'kind' => $a->kind,
            'outcome' => $a->outcome->value,
            'http_status' => $a->http_status,
            'error' => $a->error_message,
            'started_at' => $a->started_at?->toIso8601String(),
            'finished_at' => $a->finished_at?->toIso8601String(),
        ])->values()->all();

        $data['provider_events'] = $request->providerEvents->map(fn (ProviderEvent $e): array => [
            'event_id' => $e->event_id,
            'event_type' => $e->event_type,
            'result' => $e->processing_result,
            'note' => $e->processing_note,
            'received_at' => $e->created_at?->toIso8601String(),
        ])->values()->all();

        return $data;
    }
}
