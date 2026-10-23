<?php

namespace App\Enums;

/** Result of one call to the insurer, as recorded in issuance_attempts. */
enum AttemptOutcome: string
{
    case IN_FLIGHT = 'IN_FLIGHT';
    case ISSUED = 'ISSUED';
    case PENDING = 'PENDING';
    case RETRIABLE_FAILURE = 'RETRIABLE_FAILURE';
    case PERMANENT_FAILURE = 'PERMANENT_FAILURE';
    case TIMEOUT = 'TIMEOUT';
    case NOT_FOUND = 'NOT_FOUND';
    case LOOKUP_FAILED = 'LOOKUP_FAILED';
}
