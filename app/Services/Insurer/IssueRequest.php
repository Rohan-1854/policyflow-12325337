<?php

namespace App\Services\Insurer;

use App\Models\PolicyRequest;

/**
 * Payload sent to the insurer. Built from the stored snapshot, so a retry
 * always sends exactly the premium that was originally accepted.
 */
final class IssueRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(public readonly string $idempotencyKey, public readonly array $payload)
    {
    }

    public static function fromPolicyRequest(PolicyRequest $request): self
    {
        return new self($request->uuid, [
            'idempotency_key' => $request->uuid,
            'scenario' => $request->mock_scenario?->value,
            'plan_code' => $request->plan_code,
            'destination' => $request->destination,
            'sum_insured' => $request->sum_insured,
            'premium' => (string) $request->premium,
            'currency' => 'INR',
            'trip_start' => $request->trip_start->format('Y-m-d'),
            'trip_end' => $request->trip_end->format('Y-m-d'),
            'insured' => [
                'name' => $request->customer_name,
                'date_of_birth' => $request->date_of_birth->format('Y-m-d'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
