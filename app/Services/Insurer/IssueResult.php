<?php

namespace App\Services\Insurer;

final class IssueResult
{
    public const ISSUED = 'ISSUED';

    public const PENDING = 'PENDING';

    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly string $status,
        public readonly string $providerReference,
        public readonly ?string $policyNumber,
        public readonly int $httpStatus,
        public readonly array $body,
    ) {
    }

    public function isIssued(): bool
    {
        return $this->status === self::ISSUED;
    }
}
