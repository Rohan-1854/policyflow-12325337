<?php

namespace App\Models;

use App\Enums\AttemptOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $policy_request_id
 * @property int $sequence
 * @property string $kind
 * @property AttemptOutcome $outcome
 * @property int|null $http_status
 * @property array<string, mixed>|null $response
 * @property string|null $error_message
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 */
class IssuanceAttempt extends Model
{
    public const KIND_ISSUE = 'ISSUE';

    public const KIND_STATUS_LOOKUP = 'STATUS_LOOKUP';

    protected $table = 'issuance_attempts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'outcome' => AttemptOutcome::class,
            'http_status' => 'integer',
            'response' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PolicyRequest, $this> */
    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
