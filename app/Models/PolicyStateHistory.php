<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail entry.
 *
 * @property int $id
 * @property int $policy_request_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $actor
 * @property string $reason
 * @property array<string, mixed>|null $context
 * @property \Carbon\CarbonImmutable $created_at
 */
class PolicyStateHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'policy_state_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => false); // audit rows are never edited
    }

    /** @return BelongsTo<PolicyRequest, $this> */
    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
