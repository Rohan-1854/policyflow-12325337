<?php

namespace App\Exceptions;

final class IssuanceNotRetriable extends PolicyFlowException
{
    public function __construct(
        public readonly string $id,
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reasonCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['id' => $this->id];
    }
}
