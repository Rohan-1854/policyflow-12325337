<?php

namespace App\Enums;

/**
 * Lifecycle of a policy request. Transitions are explicit: anything not
 * listed in allowedTransitions() is rejected, so a confirmed ISSUED policy
 * can never regress (e.g. ISSUED -> SUBMITTED).
 *
 *   RECEIVED -> VALIDATED -> SUBMITTED -> ISSUED
 *                               |  \--> PENDING_CONFIRMATION -> ISSUED
 *                               |  \--> FAILED (retriable) -> SUBMITTED (retry)
 *                               \----> NEEDS_RECONCILIATION -> ISSUED | PENDING_CONFIRMATION | FAILED
 */
enum PolicyStatus: string
{
    case RECEIVED = 'RECEIVED';
    case VALIDATED = 'VALIDATED';
    case SUBMITTED = 'SUBMITTED';
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case ISSUED = 'ISSUED';
    case FAILED = 'FAILED';
    case NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::RECEIVED => [self::VALIDATED, self::FAILED],
            self::VALIDATED => [self::SUBMITTED],
            self::SUBMITTED => [self::ISSUED, self::PENDING_CONFIRMATION, self::FAILED, self::NEEDS_RECONCILIATION],
            self::PENDING_CONFIRMATION => [self::ISSUED, self::FAILED, self::NEEDS_RECONCILIATION],
            self::NEEDS_RECONCILIATION => [self::ISSUED, self::PENDING_CONFIRMATION, self::FAILED],
            // Retry (FAILED -> SUBMITTED) is further gated on `failure_retriable`
            // and the attempt limit in IssuanceService. A late provider
            // confirmation for a FAILED request is escalated, never applied blindly.
            self::FAILED => [self::SUBMITTED, self::NEEDS_RECONCILIATION],
            self::ISSUED => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** States where the outcome at the insurer is not yet known to us. */
    public function isAwaitingProvider(): bool
    {
        return in_array($this, [self::SUBMITTED, self::PENDING_CONFIRMATION, self::NEEDS_RECONCILIATION], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
