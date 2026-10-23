<?php

namespace App\Services\Insurer;

final class LookupResult
{
    public const ISSUED = 'ISSUED';

    public const PENDING = 'PENDING';

    public const DECLINED = 'DECLINED';

    public const NOT_FOUND = 'NOT_FOUND';

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference,
        public readonly ?string $policyNumber,
        public readonly int $httpStatus,
        public readonly array $body,
    ) {
    }
}
