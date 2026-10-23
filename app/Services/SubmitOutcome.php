<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;

final class SubmitOutcome
{
    public function __construct(
        public readonly PolicyRequest $request,
        /** Number of calls made to the insurer while handling this submit. */
        public readonly int $providerCalls,
        public readonly string $note,
    ) {
    }

    /**
     * ISSUED -> 200; not final yet -> 202; insurer failure -> 502.
     */
    public function httpStatus(): int
    {
        return match ($this->request->status) {
            PolicyStatus::ISSUED => 200,
            PolicyStatus::FAILED => 502,
            PolicyStatus::PENDING_CONFIRMATION,
            PolicyStatus::NEEDS_RECONCILIATION,
            PolicyStatus::SUBMITTED => 202,
            default => 200,
        };
    }
}
