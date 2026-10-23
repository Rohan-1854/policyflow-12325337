<?php

namespace App\Services\Insurer;

use App\Services\Insurer\Exceptions\InsurerLookupFailed;
use App\Services\Insurer\Exceptions\InsurerPermanentFailure;
use App\Services\Insurer\Exceptions\InsurerRetriableFailure;
use App\Services\Insurer\Exceptions\InsurerTimeout;

/**
 * Translates raw insurer HTTP responses into domain results. Shared by both
 * gateways so the in-process mock and the HTTP client behave identically.
 *
 * The important classification is "did the insurer definitely NOT act?"
 *  - 503 / 429          -> definitely not processed -> retriable
 *  - 400 / 422          -> declined -> permanent
 *  - 500 / 502 / 504 / malformed 2xx -> we cannot know -> treat like a timeout
 */
final class InsurerResponseMapper
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function issue(int $status, array $body): IssueResult
    {
        if ($status >= 200 && $status < 300) {
            $state = $body['status'] ?? null;
            $reference = $body['provider_reference'] ?? null;
            $policyNumber = $body['policy_number'] ?? null;

            if ($state === IssueResult::ISSUED && is_string($reference) && is_string($policyNumber) && $policyNumber !== '') {
                return new IssueResult(IssueResult::ISSUED, $reference, $policyNumber, $status, $body);
            }

            if ($state === IssueResult::PENDING && is_string($reference)) {
                return new IssueResult(IssueResult::PENDING, $reference, null, $status, $body);
            }

            throw new InsurerTimeout('Insurer returned an unrecognised success payload; outcome unknown.', $status, $body);
        }

        if (in_array($status, [429, 503], true)) {
            throw new InsurerRetriableFailure('Insurer temporarily unavailable.', $status, $body);
        }

        if (in_array($status, [400, 409, 422], true)) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'Insurer declined the request.';

            throw new InsurerPermanentFailure($message, $status, $body);
        }

        throw new InsurerTimeout("Insurer responded with HTTP {$status}; outcome unknown.", $status, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function lookup(int $status, array $body): LookupResult
    {
        if ($status === 404) {
            return new LookupResult(LookupResult::NOT_FOUND, null, null, $status, $body);
        }

        if ($status === 200) {
            $state = $body['status'] ?? null;
            $known = [LookupResult::ISSUED, LookupResult::PENDING, LookupResult::DECLINED, LookupResult::NOT_FOUND];

            if (in_array($state, $known, true)) {
                $policyNumber = is_string($body['policy_number'] ?? null) ? $body['policy_number'] : null;

                if ($state === LookupResult::ISSUED && $policyNumber === null) {
                    throw new InsurerLookupFailed('Insurer reported ISSUED without a policy number.', $status, $body);
                }

                return new LookupResult(
                    $state,
                    is_string($body['provider_reference'] ?? null) ? $body['provider_reference'] : null,
                    $policyNumber,
                    $status,
                    $body,
                );
            }
        }

        throw new InsurerLookupFailed("Status lookup failed with HTTP {$status}.", $status, $body);
    }
}
