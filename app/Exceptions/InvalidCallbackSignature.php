<?php

namespace App\Exceptions;

final class InvalidCallbackSignature extends PolicyFlowException
{
    public function __construct()
    {
        parent::__construct('Callback signature is missing or invalid.');
    }

    public function errorCode(): string
    {
        return 'INVALID_SIGNATURE';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
