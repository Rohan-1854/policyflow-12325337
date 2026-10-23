<?php

namespace App\Services\Callbacks;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;

final class CallbackResult
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $result,
        public readonly ?string $note = null,
        public readonly ?PolicyRequest $request = null,
    ) {
    }

    public function httpStatus(): int
    {
        // Unmatched events are stored for investigation; 202 tells the
        // provider we have it and it should not keep retrying.
        return $this->result === ProviderEvent::RESULT_UNMATCHED ? 202 : 200;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'result' => $this->result,
            'note' => $this->note,
            'policy_request_id' => $this->request?->uuid,
            'policy_request_status' => $this->request?->status->value,
            'policy_number' => $this->request?->policy_number,
        ];
    }
}
