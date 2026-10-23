<?php

namespace App\Services\Insurer;

use App\Services\Insurer\Exceptions\InsurerLookupFailed;
use App\Services\Insurer\Exceptions\InsurerPermanentFailure;
use App\Services\Insurer\Exceptions\InsurerRetriableFailure;
use App\Services\Insurer\Exceptions\InsurerTimeout;

/**
 * Port to the external insurer. Two adapters exist: an in-process mock
 * (LocalMockGateway) and an HTTP client (HttpInsurerGateway).
 */
interface InsurerGateway
{
    /**
     * Ask the insurer to issue a policy. The idempotency key is the policy
     * request's UUID and is identical on every retry.
     *
     * @throws InsurerRetriableFailure  insurer says it did NOT process the request; safe to retry
     * @throws InsurerPermanentFailure  insurer declined; do not retry
     * @throws InsurerTimeout           outcome unknown; must reconcile before any re-issue
     */
    public function issue(IssueRequest $request): IssueResult;

    /**
     * Ask the insurer what it knows about an idempotency key.
     *
     * @throws InsurerLookupFailed
     */
    public function lookup(string $idempotencyKey): LookupResult;
}
