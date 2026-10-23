<?php

namespace App\Services\Insurer;

use App\Services\Insurer\Exceptions\InsurerLookupFailed;
use App\Services\Insurer\Exceptions\InsurerTimeout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * Talks to an insurer over HTTP (the mock insurer routes by default).
 * A connection error or timeout on issue() is an UNKNOWN outcome, never a
 * plain failure: the insurer may have issued the policy before we gave up.
 */
final class HttpInsurerGateway implements InsurerGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {
    }

    public function issue(IssueRequest $request): IssueResult
    {
        try {
            $response = $this->client()
                ->withHeaders(['Idempotency-Key' => $request->idempotencyKey])
                ->post($this->baseUrl.'/policies', $request->toArray());
        } catch (ConnectionException $e) {
            throw new InsurerTimeout('Insurer call failed or timed out: '.$e->getMessage());
        }

        return InsurerResponseMapper::issue($response->status(), $this->json($response->json()));
    }

    public function lookup(string $idempotencyKey): LookupResult
    {
        try {
            $response = $this->client()->get($this->baseUrl.'/policies/'.rawurlencode($idempotencyKey));
        } catch (ConnectionException $e) {
            throw new InsurerLookupFailed('Status lookup failed or timed out: '.$e->getMessage());
        }

        return InsurerResponseMapper::lookup($response->status(), $this->json($response->json()));
    }

    private function client(): PendingRequest
    {
        return $this->http->acceptJson()->asJson()->timeout($this->timeoutSeconds)->connectTimeout(min(3, $this->timeoutSeconds));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $decoded): array
    {
        return is_array($decoded) ? $decoded : [];
    }
}
