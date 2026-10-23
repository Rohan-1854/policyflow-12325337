<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PolicyRequestPresenter;
use App\Http\Requests\StorePolicyRequestRequest;
use App\Models\PolicyRequest;
use App\Services\IntakeService;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    /** GET /api/policy-requests?status=ISSUED&agent_id=AGENT-17&per_page=20&page=1 */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PolicyStatus::values())],
            'agent_id' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = PolicyRequest::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['agent_id'] ?? null, fn ($q, $agent) => $q->where('agent_id', $agent))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return ApiResponse::data(
            $page->getCollection()->map(fn (PolicyRequest $r) => PolicyRequestPresenter::summary($r))->values()->all(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'filters' => array_filter([
                    'status' => $filters['status'] ?? null,
                    'agent_id' => $filters['agent_id'] ?? null,
                ]),
            ],
        );
    }

    /** POST /api/policy-requests - 201 when created, 200 when safely repeated. */
    public function store(StorePolicyRequestRequest $request, IntakeService $intake): JsonResponse
    {
        $result = $intake->intake($request->validated());
        $policyRequest = $result->request->fresh() ?? $result->request;

        return ApiResponse::data(
            PolicyRequestPresenter::detail($policyRequest),
            $result->created ? 201 : 200,
            ['idempotent_replay' => ! $result->created],
            [
                'Location' => url('/api/policy-requests/'.$policyRequest->uuid),
                'Idempotent-Replayed' => $result->created ? 'false' : 'true',
            ],
        );
    }

    /** GET /api/policy-requests/{id} */
    public function show(PolicyRequest $policyRequest): JsonResponse
    {
        return ApiResponse::data(PolicyRequestPresenter::detail($policyRequest));
    }

    /** POST /api/policy-requests/{id}/submit - start or safely resume issuance. */
    public function submit(PolicyRequest $policyRequest, IssuanceService $issuance): JsonResponse
    {
        $outcome = $issuance->submit($policyRequest);
        $fresh = $outcome->request->fresh() ?? $outcome->request;

        return ApiResponse::data(
            PolicyRequestPresenter::detail($fresh),
            $outcome->httpStatus(),
            [
                'provider_calls' => $outcome->providerCalls,
                'note' => $outcome->note,
                'retriable' => $fresh->status === PolicyStatus::FAILED ? (bool) $fresh->failure_retriable : null,
            ],
        );
    }
}
