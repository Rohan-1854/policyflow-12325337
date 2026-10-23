<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An insurer callback, stored exactly once per event_id.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property string|null $provider_reference
 * @property string|null $client_reference
 * @property int|null $policy_request_id
 * @property array<string, mixed> $payload
 * @property string $processing_result
 * @property string|null $processing_note
 * @property \Carbon\CarbonImmutable|null $processed_at
 */
class ProviderEvent extends Model
{
    public const TYPE_POLICY_ISSUED = 'POLICY_ISSUED';

    public const TYPE_POLICY_DECLINED = 'POLICY_DECLINED';

    public const RESULT_APPLIED = 'APPLIED';

    public const RESULT_NO_CHANGE = 'NO_CHANGE';

    public const RESULT_CONFLICT = 'CONFLICT_IGNORED';

    public const RESULT_ESCALATED = 'ESCALATED';

    public const RESULT_UNMATCHED = 'UNMATCHED';

    public const RESULT_DUPLICATE = 'DUPLICATE_IGNORED';

    protected $table = 'provider_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
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
