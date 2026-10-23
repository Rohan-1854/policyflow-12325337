<?php

namespace App\Console\Commands;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finds requests whose insurer outcome is unknown or overdue and resolves
 * them with a status lookup. Never issues. Safe to run repeatedly/concurrently
 * because every state change is re-checked under a row lock.
 */
class ReconcilePolicyRequests extends Command
{
    protected $signature = 'policyflow:reconcile
                            {--limit=100 : Maximum requests to process in one run}
                            {--stale-minutes= : Override ISSUANCE_PENDING_STALE_MINUTES}';

    protected $description = 'Resolve NEEDS_RECONCILIATION, stale PENDING_CONFIRMATION and abandoned SUBMITTED requests via insurer status lookup';

    public function handle(IssuanceService $issuance): int
    {
        $staleMinutes = (int) ($this->option('stale-minutes') ?? config('insurer.issuance.pending_stale_minutes'));
        $leaseSeconds = (int) config('insurer.issuance.submission_lease_seconds');

        $candidates = PolicyRequest::query()
            ->where(function ($q) use ($staleMinutes, $leaseSeconds): void {
                $q->where('status', PolicyStatus::NEEDS_RECONCILIATION->value)
                    ->orWhere(fn ($q) => $q->where('status', PolicyStatus::PENDING_CONFIRMATION->value)
                        ->where('updated_at', '<=', now()->subMinutes($staleMinutes)))
                    ->orWhere(fn ($q) => $q->where('status', PolicyStatus::SUBMITTED->value)
                        ->where('submitted_at', '<=', now()->subSeconds($leaseSeconds)));
            })
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($candidates as $request) {
            $before = $request->status->value;

            try {
                $outcome = $issuance->reconcile($request, 'reconciler');
                $rows[] = [$request->uuid, $before, $outcome->request->status->value, $outcome->note];
            } catch (Throwable $e) {
                report($e);
                $rows[] = [$request->uuid, $before, 'ERROR', $e->getMessage()];
            }
        }

        $this->table(['id', 'before', 'after', 'note'], $rows);

        return self::SUCCESS;
    }
}
