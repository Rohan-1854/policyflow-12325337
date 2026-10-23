<?php

namespace App\Services;

use App\Enums\AttemptOutcome;
use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransition;
use App\Exceptions\IssuanceNotRetriable;
use App\Exceptions\SubmissionInProgress;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Services\Insurer\Exceptions\InsurerException;
use App\Services\Insurer\Exceptions\InsurerLookupFailed;
use App\Services\Insurer\Exceptions\InsurerPermanentFailure;
use App\Services\Insurer\Exceptions\InsurerRetriableFailure;
use App\Services\Insurer\Exceptions\InsurerTimeout;
use App\Services\Insurer\InsurerGateway;
use App\Services\Insurer\IssueRequest;
use App\Services\Insurer\IssueResult;
use App\Services\Insurer\LookupResult;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drives a policy request through issuance.
 *
 * Every submit has three phases:
 *
 *  1. Decide (short transaction, row locked with SELECT ... FOR UPDATE):
 *     look at the current state and decide whether to call the insurer at
 *     all. Repeated HTTP requests for an ISSUED / PENDING request stop here,
 *     so they never cause a provider call. If we do proceed, we move to
 *     SUBMITTED and write an IN_FLIGHT attempt row before releasing the lock;
 *     a concurrent submit then sees SUBMITTED and gets 409.
 *
 *  2. Call the insurer with NO database lock held. The idempotency key is the
 *     request UUID, identical on every retry, and the payload is the stored
 *     premium snapshot.
 *
 *  3. Record (short transaction, row locked again): store the attempt result
 *     and transition - but only if the request is still in the SUBMITTED
 *     state this attempt created. If a callback already resolved it, we
 *     record the attempt and leave the state alone (no regressions).
 *
 * A timeout is never treated as failure: the request moves to
 * NEEDS_RECONCILIATION and we ask the insurer for the status of the key
 * before anything is ever re-issued.
 */
class IssuanceService
{
    public function __construct(private readonly InsurerGateway $gateway)
    {
    }

    public function submit(PolicyRequest $request): SubmitOutcome
    {
        /** @var array{action: string, request: PolicyRequest, attempt?: IssuanceAttempt, note?: string} $plan */
        $plan = DB::transaction(fn (): array => $this->planSubmission($request->id));

        return match ($plan['action']) {
            'noop' => new SubmitOutcome($plan['request'], 0, $plan['note'] ?? ''),
            'reconcile' => $this->reconcile($plan['request'], 'api'),
            'issue' => $this->performIssue($plan['request'], $plan['attempt']),
        };
    }

    /**
     * Ask the insurer for the status of a request whose outcome we do not
     * know, and apply the answer. Never issues.
     */
    public function reconcile(PolicyRequest $request, string $actor): SubmitOutcome
    {
        $attempt = DB::transaction(function () use ($request): ?IssuanceAttempt {
            $locked = $this->lock($request->id);

            if (! $locked->status->isAwaitingProvider()) {
                return null;
            }

            return $this->startAttempt($locked, IssuanceAttempt::KIND_STATUS_LOOKUP);
        });

        if ($attempt === null) {
            return new SubmitOutcome($request->fresh() ?? $request, 0, 'Nothing to reconcile.');
        }

        try {
            $lookup = $this->gateway->lookup($request->uuid);
        } catch (InsurerException $e) {
            $updated = $this->finishAttempt($request->id, $attempt, AttemptOutcome::LOOKUP_FAILED, $e->httpStatus, $e->body, $e->getMessage(),
                function (PolicyRequest $locked) use ($e): void {
                    // Stay in the current state; the outcome is still unknown.
                    if ($locked->status->isAwaitingProvider()) {
                        $locked->last_error = 'Status lookup failed: '.$e->getMessage();
                        $locked->save();
                    }
                },
                requireCurrentIssue: false,
            );

            return new SubmitOutcome($updated, 1, 'Insurer status lookup failed; the request will be reconciled again later.');
        }

        $updated = $this->finishAttempt(
            $request->id,
            $attempt,
            $this->lookupOutcome($lookup),
            $lookup->httpStatus,
            $lookup->body,
            null,
            fn (PolicyRequest $locked) => $this->applyLookup($locked, $lookup, $actor),
            requireCurrentIssue: false,
        );

        return new SubmitOutcome($updated, 1, 'Reconciled with insurer status lookup: '.$lookup->status.'.');
    }

    /**
     * @return array{action: string, request: PolicyRequest, attempt?: IssuanceAttempt, note?: string}
     */
    private function planSubmission(int $id): array
    {
        $request = $this->lock($id);

        switch ($request->status) {
            case PolicyStatus::ISSUED:
                return ['action' => 'noop', 'request' => $request, 'note' => 'Policy already issued; no insurer call made.'];

            case PolicyStatus::PENDING_CONFIRMATION:
                return ['action' => 'noop', 'request' => $request, 'note' => 'Awaiting insurer confirmation; no insurer call made.'];

            case PolicyStatus::NEEDS_RECONCILIATION:
                return ['action' => 'reconcile', 'request' => $request];

            case PolicyStatus::SUBMITTED:
                $leaseEnds = $request->submitted_at?->addSeconds((int) config('insurer.issuance.submission_lease_seconds'));

                if ($leaseEnds !== null && $leaseEnds->isFuture()) {
                    throw new SubmissionInProgress($request->uuid, max(1, (int) ceil(abs(now()->diffInSeconds($leaseEnds)))));
                }

                // The worker that submitted it died or hung: we cannot know
                // whether the insurer acted, so reconcile instead of re-issuing.
                $request->transitionTo(PolicyStatus::NEEDS_RECONCILIATION, 'api', 'Previous submission exceeded its lease; outcome unknown');

                return ['action' => 'reconcile', 'request' => $request];

            case PolicyStatus::FAILED:
                if ($request->failure_retriable !== true) {
                    throw new IssuanceNotRetriable($request->uuid, 'ISSUANCE_FAILED_PERMANENTLY', 'The insurer declined this request; it cannot be retried.');
                }

                if ($request->attempt_count >= (int) config('insurer.issuance.max_attempts')) {
                    throw new IssuanceNotRetriable($request->uuid, 'RETRY_LIMIT_REACHED', 'The maximum number of issuance attempts has been reached.');
                }
                break;

            case PolicyStatus::VALIDATED:
                break;

            case PolicyStatus::RECEIVED:
                throw new InvalidStateTransition($request->status, PolicyStatus::SUBMITTED);
        }

        $isRetry = $request->status === PolicyStatus::FAILED;

        $request->attempt_count++;
        $request->submitted_at = now();
        $request->failure_retriable = null;
        $request->transitionTo(
            PolicyStatus::SUBMITTED,
            'api',
            $isRetry ? 'Retrying after temporary failure' : 'Submitted to insurer',
            ['issue_attempt' => $request->attempt_count, 'premium' => (string) $request->premium],
        );

        return [
            'action' => 'issue',
            'request' => $request,
            'attempt' => $this->startAttempt($request, IssuanceAttempt::KIND_ISSUE),
        ];
    }

    private function performIssue(PolicyRequest $request, IssuanceAttempt $attempt): SubmitOutcome
    {
        try {
            $result = $this->gateway->issue(IssueRequest::fromPolicyRequest($request));
        } catch (InsurerTimeout $e) {
            $this->finishAttempt($request->id, $attempt, AttemptOutcome::TIMEOUT, $e->httpStatus, $e->body, $e->getMessage(),
                function (PolicyRequest $locked) use ($e): void {
                    $locked->last_error = 'Insurer did not respond: '.$e->getMessage();
                    $locked->last_response = $e->body === [] ? null : $e->body;
                    $locked->transitionTo(PolicyStatus::NEEDS_RECONCILIATION, 'api', 'Insurer call timed out; checking status before any re-issue');
                },
            );

            // Resolve right away: ask the insurer what happened to this key.
            $reconciled = $this->reconcile($request, 'api');

            return new SubmitOutcome($reconciled->request, 1 + $reconciled->providerCalls, 'Insurer call timed out. '.$reconciled->note);
        } catch (InsurerRetriableFailure $e) {
            $updated = $this->finishAttempt($request->id, $attempt, AttemptOutcome::RETRIABLE_FAILURE, $e->httpStatus, $e->body, $e->getMessage(),
                function (PolicyRequest $locked) use ($e): void {
                    $locked->failure_retriable = true;
                    $locked->last_error = $e->getMessage();
                    $locked->last_response = $e->body;
                    $locked->transitionTo(PolicyStatus::FAILED, 'api', 'Temporary insurer failure (retriable)', ['http_status' => $e->httpStatus]);
                },
            );

            return new SubmitOutcome($updated, 1, 'Temporary insurer failure; submit again to retry with the same premium and idempotency key.');
        } catch (InsurerPermanentFailure $e) {
            $updated = $this->finishAttempt($request->id, $attempt, AttemptOutcome::PERMANENT_FAILURE, $e->httpStatus, $e->body, $e->getMessage(),
                function (PolicyRequest $locked) use ($e): void {
                    $locked->failure_retriable = false;
                    $locked->last_error = $e->getMessage();
                    $locked->last_response = $e->body;
                    $locked->provider_reference ??= is_string($e->body['provider_reference'] ?? null) ? $e->body['provider_reference'] : null;
                    $locked->transitionTo(PolicyStatus::FAILED, 'api', 'Insurer declined the request', ['http_status' => $e->httpStatus]);
                },
            );

            return new SubmitOutcome($updated, 1, 'Insurer declined the request.');
        }

        $updated = $this->finishAttempt(
            $request->id,
            $attempt,
            $result->isIssued() ? AttemptOutcome::ISSUED : AttemptOutcome::PENDING,
            $result->httpStatus,
            $result->body,
            null,
            fn (PolicyRequest $locked) => $this->applyIssueResult($locked, $result),
        );

        return new SubmitOutcome($updated, 1, $result->isIssued() ? 'Policy issued.' : 'Insurer accepted the request; waiting for confirmation callback.');
    }

    private function applyIssueResult(PolicyRequest $request, IssueResult $result): void
    {
        $request->provider_reference ??= $result->providerReference;
        $request->last_response = $result->body;
        $request->last_error = null;

        if ($result->isIssued()) {
            $request->policy_number = $result->policyNumber;
            $request->issued_at = now();
            $request->transitionTo(PolicyStatus::ISSUED, 'api', 'Insurer issued the policy', ['policy_number' => $result->policyNumber]);

            return;
        }

        $request->transitionTo(PolicyStatus::PENDING_CONFIRMATION, 'api', 'Insurer accepted; awaiting confirmation');
    }

    private function applyLookup(PolicyRequest $request, LookupResult $lookup, string $actor): void
    {
        if (! $request->status->isAwaitingProvider()) {
            return; // resolved by someone else meanwhile
        }

        $request->provider_reference ??= $lookup->providerReference;
        $request->last_response = $lookup->body;

        switch ($lookup->status) {
            case LookupResult::ISSUED:
                $request->policy_number = $lookup->policyNumber;
                $request->issued_at = now();
                $request->last_error = null;
                $request->transitionTo(PolicyStatus::ISSUED, $actor, 'Reconciled: insurer reports the policy as issued', [
                    'policy_number' => $lookup->policyNumber,
                ]);

                return;

            case LookupResult::PENDING:
                if ($request->status !== PolicyStatus::PENDING_CONFIRMATION) {
                    $request->transitionTo(PolicyStatus::PENDING_CONFIRMATION, $actor, 'Reconciled: insurer has the request and will confirm later');
                } else {
                    $request->save();
                }

                return;

            case LookupResult::DECLINED:
                $request->failure_retriable = false;
                $request->last_error = 'Insurer declined the request.';
                $request->transitionTo(PolicyStatus::FAILED, $actor, 'Reconciled: insurer reports the request as declined');

                return;

            case LookupResult::NOT_FOUND:
            default:
                // The insurer never created anything for this key, so a retry
                // (with the SAME key and premium) cannot create a duplicate.
                $request->failure_retriable = true;
                $request->last_error = 'Insurer has no record of this request; it is safe to retry.';
                $request->transitionTo(PolicyStatus::FAILED, $actor, 'Reconciled: insurer has no record; safe to retry');

                return;
        }
    }

    /**
     * Phase 3: record an attempt's result and apply its effect under lock.
     *
     * @param  array<string, mixed>  $body
     * @param  Closure(PolicyRequest): void  $apply
     */
    private function finishAttempt(
        int $requestId,
        IssuanceAttempt $attempt,
        AttemptOutcome $outcome,
        ?int $httpStatus,
        array $body,
        ?string $error,
        Closure $apply,
        bool $requireCurrentIssue = true,
    ): PolicyRequest {
        return DB::transaction(function () use ($requestId, $attempt, $outcome, $httpStatus, $body, $error, $apply, $requireCurrentIssue): PolicyRequest {
            $locked = $this->lock($requestId);

            $attempt->forceFill([
                'outcome' => $outcome,
                'http_status' => $httpStatus,
                'response' => $body === [] ? null : $body,
                'error_message' => $error === null ? null : mb_substr($error, 0, 490),
                'finished_at' => now(),
            ])->save();

            if ($requireCurrentIssue && ! $this->isCurrentIssueAttempt($locked, $attempt)) {
                Log::info('Issuance result arrived after the request had moved on; recorded without a state change.', [
                    'policy_request' => $locked->uuid,
                    'attempt' => $attempt->sequence,
                    'outcome' => $outcome->value,
                    'status' => $locked->status->value,
                ]);

                // Insurer says it acted, but we think it failed: let reconciliation decide.
                if ($locked->status === PolicyStatus::FAILED && in_array($outcome, [AttemptOutcome::ISSUED, AttemptOutcome::PENDING], true)) {
                    $locked->transitionTo(PolicyStatus::NEEDS_RECONCILIATION, 'api', 'Late insurer success for a request marked FAILED');
                }

                return $locked;
            }

            $apply($locked);

            return $locked;
        });
    }

    private function isCurrentIssueAttempt(PolicyRequest $request, IssuanceAttempt $attempt): bool
    {
        if ($request->status !== PolicyStatus::SUBMITTED) {
            return false;
        }

        $latestIssueId = $request->attempts()->reorder()->where('kind', IssuanceAttempt::KIND_ISSUE)->max('id');

        return (int) $latestIssueId === (int) $attempt->id;
    }

    private function startAttempt(PolicyRequest $request, string $kind): IssuanceAttempt
    {
        return $request->attempts()->create([
            'sequence' => $request->nextAttemptSequence(),
            'kind' => $kind,
            'outcome' => AttemptOutcome::IN_FLIGHT,
            'started_at' => now(),
        ]);
    }

    private function lookupOutcome(LookupResult $lookup): AttemptOutcome
    {
        return match ($lookup->status) {
            LookupResult::ISSUED => AttemptOutcome::ISSUED,
            LookupResult::PENDING => AttemptOutcome::PENDING,
            LookupResult::DECLINED => AttemptOutcome::PERMANENT_FAILURE,
            default => AttemptOutcome::NOT_FOUND,
        };
    }

    private function lock(int $id): PolicyRequest
    {
        /** @var PolicyRequest */
        return PolicyRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
