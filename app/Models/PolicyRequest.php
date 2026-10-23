<?php

namespace App\Models;

use App\Enums\MockScenario;
use App\Enums\PolicyStatus;
use App\Exceptions\ImmutableSnapshotViolation;
use App\Exceptions\InvalidStateTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $agent_id
 * @property string $client_request_id
 * @property string $payload_fingerprint
 * @property string $customer_name
 * @property \Carbon\CarbonImmutable $date_of_birth
 * @property string $destination
 * @property \Carbon\CarbonImmutable $trip_start
 * @property \Carbon\CarbonImmutable $trip_end
 * @property string $plan_code
 * @property int $sum_insured
 * @property int $travel_days
 * @property int $age_at_trip_start
 * @property string $premium
 * @property array<string, mixed> $premium_breakdown
 * @property PolicyStatus $status
 * @property MockScenario|null $mock_scenario
 * @property string|null $provider_reference
 * @property string|null $policy_number
 * @property int $attempt_count
 * @property bool|null $failure_retriable
 * @property string|null $last_error
 * @property array<string, mixed>|null $last_response
 * @property \Carbon\CarbonImmutable|null $submitted_at
 * @property \Carbon\CarbonImmutable|null $issued_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class PolicyRequest extends Model
{
    /**
     * Business inputs and the accepted premium. Once the row exists these can
     * never change - retries and reconciliation always reuse this snapshot.
     */
    public const IMMUTABLE_ATTRIBUTES = [
        'uuid',
        'agent_id',
        'client_request_id',
        'payload_fingerprint',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'age_at_trip_start',
        'premium',
        'premium_breakdown',
        'mock_scenario',
    ];

    protected $table = 'policy_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'trip_start' => 'immutable_date',
            'trip_end' => 'immutable_date',
            'sum_insured' => 'integer',
            'travel_days' => 'integer',
            'age_at_trip_start' => 'integer',
            'premium' => 'decimal:2',
            'premium_breakdown' => 'array',
            'status' => PolicyStatus::class,
            'mock_scenario' => MockScenario::class,
            'attempt_count' => 'integer',
            'failure_retriable' => 'boolean',
            'last_response' => 'array',
            'submitted_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PolicyRequest $request): void {
            $request->uuid ??= (string) Str::uuid();
        });

        static::updating(function (PolicyRequest $request): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($request->isDirty($attribute)) {
                    throw new ImmutableSnapshotViolation($attribute);
                }
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<PolicyStateHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('id');
    }

    /** @return HasMany<IssuanceAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class)->orderBy('sequence');
    }

    /** @return HasMany<ProviderEvent, $this> */
    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class)->orderBy('id');
    }

    /**
     * Move to a new status and append an audit row.
     *
     * Callers must hold a row lock (SELECT ... FOR UPDATE inside a
     * transaction) so two processes cannot both act on the same "from" state.
     *
     * @param  array<string, mixed>  $context  Never put customer PII in here.
     */
    public function transitionTo(PolicyStatus $to, string $actor, string $reason, array $context = []): void
    {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStateTransition($from, $to);
        }

        $this->status = $to;
        $this->save();

        $this->history()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor' => $actor,
            'reason' => Str::limit($reason, 250),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /** First audit row, written when the request is persisted. */
    public function recordInitialState(string $actor, string $reason): void
    {
        $this->history()->create([
            'from_status' => null,
            'to_status' => $this->status->value,
            'actor' => $actor,
            'reason' => $reason,
            'context' => null,
        ]);
    }

    /** Next sequence number for an issuance_attempts row. Call under row lock. */
    public function nextAttemptSequence(): int
    {
        return ((int) $this->attempts()->max('sequence')) + 1;
    }
}
