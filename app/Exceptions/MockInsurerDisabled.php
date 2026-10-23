<?php

namespace App\Exceptions;

final class MockInsurerDisabled extends PolicyFlowException
{
    public function __construct()
    {
        parent::__construct('The mock insurer is disabled in this environment.');
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
