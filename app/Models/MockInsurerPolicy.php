<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Row in the simulated insurer's own policy book.
 *
 * @property int $id
 * @property string $idempotency_key
 * @property string $provider_reference
 * @property string|null $policy_number
 * @property string $scenario
 * @property string $status
 * @property int $issue_calls
 * @property int $lookup_calls
 * @property int $callbacks_due
 * @property int $callbacks_delivered
 * @property string|null $callback_event_id
 * @property array<string, mixed>|null $request_summary
 * @property \Carbon\CarbonImmutable|null $issued_at
 */
class MockInsurerPolicy extends Model
{
    public const STATUS_NOT_ISSUED = 'NOT_ISSUED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ISSUED = 'ISSUED';

    public const STATUS_DECLINED = 'DECLINED';

    protected $table = 'mock_insurer_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_calls' => 'integer',
            'lookup_calls' => 'integer',
            'callbacks_due' => 'integer',
            'callbacks_delivered' => 'integer',
            'request_summary' => 'array',
            'issued_at' => 'immutable_datetime',
        ];
    }
}
