<?php

namespace App\Exceptions;

use App\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base class for expected, client-visible domain errors. Laravel calls
 * render() automatically, producing the standard error envelope.
 */
abstract class PolicyFlowException extends RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        return null;
    }

    public function render(Request $request): JsonResponse
    {
        return ApiResponse::error($this->errorCode(), $this->getMessage(), $this->httpStatus(), $this->details());
    }
}
