<?php

namespace App\Services;

use App\Models\PolicyRequest;

final class IntakeResult
{
    public function __construct(
        public readonly PolicyRequest $request,
        public readonly bool $created,
    ) {
    }
}
