<?php

namespace App\Services\MockInsurer;

/** What the simulated insurer "puts on the wire": an HTTP status and a JSON body. */
final class MockInsurerResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(public readonly int $status, public readonly array $body)
    {
    }
}
