<?php

namespace App\Exceptions;

final class SubmissionInProgress extends PolicyFlowException
{
    public function __construct(public readonly string $id, public readonly int $retryAfterSeconds)
    {
        parent::__construct('A submission for this request is already in progress.');
    }

    public function errorCode(): string
    {
        return 'SUBMISSION_IN_PROGRESS';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['id' => $this->id, 'retry_after_seconds' => $this->retryAfterSeconds];
    }
}
