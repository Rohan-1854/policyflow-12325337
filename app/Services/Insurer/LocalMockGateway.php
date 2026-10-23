<?php

namespace App\Services\Insurer;

use App\Services\MockInsurer\MockInsurer;

/**
 * Calls the simulated insurer in-process. It goes through the same response
 * mapping as the HTTP gateway, so the issuance logic cannot tell them apart.
 * This avoids the deadlock you get when `php artisan serve` (single worker)
 * makes an HTTP call to itself.
 */
final class LocalMockGateway implements InsurerGateway
{
    public function __construct(private readonly MockInsurer $insurer)
    {
    }

    public function issue(IssueRequest $request): IssueResult
    {
        $response = $this->insurer->issue($request->toArray());

        return InsurerResponseMapper::issue($response->status, $response->body);
    }

    public function lookup(string $idempotencyKey): LookupResult
    {
        $response = $this->insurer->lookup($idempotencyKey);

        return InsurerResponseMapper::lookup($response->status, $response->body);
    }
}
