<?php

namespace App\Enums;

/**
 * Behaviour of the simulated insurer for one policy request.
 *
 * The five scenarios from the brief, plus two additions:
 *  - TIMEOUT_BEFORE_ISSUE: the call times out and the insurer did NOT create
 *    the policy, so reconciliation must allow a safe retry.
 *  - PERMANENT_FAILURE: the insurer declines (non-retriable).
 */
enum MockScenario: string
{
    case SUCCESS = 'SUCCESS';
    case TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    case TIMEOUT_AFTER_ISSUE = 'TIMEOUT_AFTER_ISSUE';
    case TIMEOUT_BEFORE_ISSUE = 'TIMEOUT_BEFORE_ISSUE';
    case DELAYED_CALLBACK = 'DELAYED_CALLBACK';
    case DUPLICATE_CALLBACK = 'DUPLICATE_CALLBACK';
    case PERMANENT_FAILURE = 'PERMANENT_FAILURE';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s) => $s->value, self::cases());
    }
}
