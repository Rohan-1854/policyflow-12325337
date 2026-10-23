<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use App\Services\MockInsurer\CallbackDispatcher;
use App\Services\MockInsurer\MockInsurer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP face of the simulated insurer (development only). Used when
 * INSURER_DRIVER=http, and handy for poking the mock during a demo.
 */
class MockInsurerController extends Controller
{
    /** POST /api/mock-insurer/policies */
    public function issue(Request $request, MockInsurer $insurer): JsonResponse
    {
        $payload = $request->json()->all();

        // Honour the conventional header as well as the body field.
        if (! isset($payload['idempotency_key']) && $request->hasHeader('Idempotency-Key')) {
            $payload['idempotency_key'] = $request->header('Idempotency-Key');
        }

        $response = $insurer->issue($payload);

        return new JsonResponse($response->body, $response->status);
    }

    /** GET /api/mock-insurer/policies/{idempotencyKey} - provider status lookup */
    public function lookup(string $idempotencyKey, MockInsurer $insurer): JsonResponse
    {
        $response = $insurer->lookup($idempotencyKey);

        return new JsonResponse($response->body, $response->status);
    }

    /** POST /api/mock-insurer/deliver-callbacks - send pending callbacks now */
    public function deliverCallbacks(Request $request, MockInsurer $insurer, CallbackDispatcher $dispatcher): JsonResponse
    {
        $key = $request->input('idempotency_key');
        $deliveries = $insurer->deliverPendingCallbacks($dispatcher, is_string($key) ? $key : null);

        return new JsonResponse(['data' => $deliveries, 'meta' => ['deliveries' => count($deliveries)]]);
    }
}
