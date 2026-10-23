<?php

namespace App\Services\Insurer\Exceptions;

use RuntimeException;

abstract class InsurerException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly array $body = [])
    {
        parent::__construct($message);
    }
}
