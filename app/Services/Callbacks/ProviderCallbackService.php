<?php

namespace App\Services\Callbacks;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Applies insurer callbacks exactly once.
 *
 * Inserting the provider_events row and applying its effect happen in ONE
 * transaction. If the same event_id arrives again (sequentially or
 * concurrently), the INSERT hits the unique index, the whole transaction is
 * rolled back, and the delivery is acknowledged as DUPLICATE_IGNORED.
 * Because the insert and the state change commit together, a crash can never
 * leave an event "seen" but not applied.
 */
class ProviderCallbackService
{
    public function __construct(private readonly CallbackSigner $signer)
    {
    }

    /**
     * Entry point for the raw HTTP body (also used by the in-process mock).
     */
    public function receive(string $rawBody, ?string $signatureHeader): CallbackResult
    {
        $this->signer->verify($rawBody, $signatureHeader);

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['body' => 'The callback body must be a JSON object.']);
        }

        /** @var array<string, mixed> $event */
        $event = Validator::make($payload, [
            'event_id' => ['required', 'string', 'max:100'],
            'event_type' => ['required', 'string', Rule::in([ProviderEvent::TYPE_POLICY_ISSUED, ProviderEvent::TYPE_POLICY_DECLINED])],
            'provider_reference' => ['nullable', 'string', 'max:64', 'required_without:client_reference'],
            'client_reference' => ['nullable', 'string', 'max:64'],
            'policy_number' => ['nullable', 'string', 'max:64', 'required_if:event_type,'.ProviderEvent::TYPE_POLICY_ISSUED],
            'occurred_at' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return $this->process($event);
    }

    /**
     * @param  array<string, mixed>  $event  validated payload
     */
    public function process(array $event): CallbackResult
    {
        try {
            return DB::transaction(fn (): CallbackResult => $this->recordAndApply($event));
        } catch (UniqueConstraintViolationException $e) {
            $existing = ProviderEvent::query()->where('event_id', $event['event_id'])->first();

            if ($existing === null) {
                throw $e; // a different unique index fired - not a duplicate delivery
            }

            return new CallbackResult(
                $existing->event_id,
                ProviderEvent::RESULT_DUPLICATE,
                "Event already received; first processed as {$existing->processing_result}.",
                $existing->policyRequest,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordAndApply(array $event): CallbackResult
    {
        $record = ProviderEvent::create([
            'event_id' => $event['event_id'],
            'event_type' => $event['event_type'],
            'provider_reference' => $event['provider_reference'] ?? null,
            'client_reference' => $event['client_reference'] ?? null,
            'payload' => $event,
            'processing_result' => 'PROCESSING',
        ]);

        $request = $this->findAndLock($event);

        if ($request === null) {
            [$result, $note] = [ProviderEvent::RESULT_UNMATCHED, 'No policy request matches this provider/client reference.'];
        } else {
            [$result, $note] = $event['event_type'] === ProviderEvent::TYPE_POLICY_ISSUED
                ? $this->applyIssued($request, $event)
                : $this->applyDeclined($request, $event);
        }

        $record->forceFill([
            'policy_request_id' => $request?->id,
            'processing_result' => $result,
            'processing_note' => $note,
            'processed_at' => now(),
        ])->save();

        return new CallbackResult($record->event_id, $result, $note, $request);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function findAndLock(array $event): ?PolicyRequest
    {
        if (! empty($event['provider_reference'])) {
            $request = PolicyRequest::query()->where('provider_reference', $event['provider_reference'])->lockForUpdate()->first();

            if ($request !== null) {
                return $request;
            }
        }

        // The callback can overtake our own bookkeeping (it may arrive before
        // we stored the provider_reference), so also match on our id.
        if (! empty($event['client_reference'])) {
            return PolicyRequest::query()->where('uuid', $event['client_reference'])->lockForUpdate()->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string}
     */
    private function applyIssued(PolicyRequest $request, array $event): array
    {
        $policyNumber = (string) $event['policy_number'];
        $providerReference = $event['provider_reference'] ?? null;

        if ($providerReference !== null && $request->provider_reference !== null && $request->provider_reference !== $providerReference) {
            return [ProviderEvent::RESULT_CONFLICT, 'provider_reference does not match the one on record; not applied.'];
        }

        if ($request->status === PolicyStatus::ISSUED) {
            return $request->policy_number === $policyNumber
                ? [ProviderEvent::RESULT_NO_CHANGE, 'Policy already issued with this policy number.']
                : [ProviderEvent::RESULT_CONFLICT, 'Policy already issued with a different policy number; confirmed state kept.'];
        }

        if ($request->status === PolicyStatus::FAILED) {
            // Late confirmation for something we marked failed: do not flip it
            // blindly, hand it to reconciliation which asks the insurer.
            $request->provider_reference ??= $providerReference;
            $request->transitionTo(PolicyStatus::NEEDS_RECONCILIATION, 'callback', 'Insurer confirmed a request we had marked FAILED', [
                'event_id' => $event['event_id'],
            ]);

            return [ProviderEvent::RESULT_ESCALATED, 'Request was FAILED; moved to NEEDS_RECONCILIATION.'];
        }

        if (! $request->status->isAwaitingProvider()) {
            return [ProviderEvent::RESULT_CONFLICT, "Request is {$request->status->value}; it was never submitted."];
        }

        $request->provider_reference ??= $providerReference;
        $request->policy_number = $policyNumber;
        $request->issued_at = now();
        $request->failure_retriable = null;
        $request->last_error = null;
        $request->last_response = ['source' => 'callback', 'event_id' => $event['event_id'], 'event_type' => $event['event_type']];
        $request->transitionTo(PolicyStatus::ISSUED, 'callback', 'Insurer confirmed issuance via callback', [
            'event_id' => $event['event_id'],
            'policy_number' => $policyNumber,
        ]);

        return [ProviderEvent::RESULT_APPLIED, 'Policy marked ISSUED.'];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{0: string, 1: string}
     */
    private function applyDeclined(PolicyRequest $request, array $event): array
    {
        if ($request->status === PolicyStatus::ISSUED) {
            return [ProviderEvent::RESULT_CONFLICT, 'Policy is already ISSUED; a decline cannot overwrite it.'];
        }

        $reason = is_string($event['reason'] ?? null) ? $event['reason'] : 'Declined by insurer';

        if ($request->status === PolicyStatus::FAILED) {
            $request->failure_retriable = false;
            $request->last_error = $reason;
            $request->save();

            return [ProviderEvent::RESULT_APPLIED, 'Request already FAILED; marked as not retriable.'];
        }

        if (! $request->status->isAwaitingProvider()) {
            return [ProviderEvent::RESULT_CONFLICT, "Request is {$request->status->value}; it was never submitted."];
        }

        $request->provider_reference ??= ($event['provider_reference'] ?? null);
        $request->failure_retriable = false;
        $request->last_error = $reason;
        $request->transitionTo(PolicyStatus::FAILED, 'callback', 'Insurer declined via callback', ['event_id' => $event['event_id']]);

        return [ProviderEvent::RESULT_APPLIED, 'Policy marked FAILED (declined).'];
    }
}
