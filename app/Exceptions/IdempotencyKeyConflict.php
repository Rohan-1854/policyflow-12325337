<?php

namespace App\Exceptions;

final class IdempotencyKeyConflict extends PolicyFlowException
{
    public function __construct(public readonly string $existingId)
    {
        parent::__construct('This agent_id + client_request_id was already used with a different payload.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['existing_id' => $this->existingId];
    }
}
