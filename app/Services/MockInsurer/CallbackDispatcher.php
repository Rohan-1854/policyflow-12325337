<?php

namespace App\Services\MockInsurer;

/** How the mock insurer delivers a callback event to us. */
interface CallbackDispatcher
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed> summary of what the receiver answered
     */
    public function dispatch(array $event): array;
}
