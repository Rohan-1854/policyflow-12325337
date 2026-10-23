<?php

namespace App\Exceptions;

use App\Enums\PolicyStatus;

final class InvalidStateTransition extends PolicyFlowException
{
    public function __construct(public readonly PolicyStatus $from, public readonly PolicyStatus $to)
    {
        parent::__construct("Transition {$from->value} -> {$to->value} is not allowed.");
    }

    public function errorCode(): string
    {
        return 'INVALID_STATE_TRANSITION';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['from' => $this->from->value, 'to' => $this->to->value];
    }
}
