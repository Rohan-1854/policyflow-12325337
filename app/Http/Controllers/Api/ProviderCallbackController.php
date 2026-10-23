<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Callbacks\ProviderCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/mock-insurer/callback - receives insurer events (our webhook).
 * The raw body is used for HMAC verification, so it is read before any parsing.
 */
class ProviderCallbackController extends Controller
{
    public function __invoke(Request $request, ProviderCallbackService $callbacks): JsonResponse
    {
        $result = $callbacks->receive(
            $request->getContent(),
            $request->header((string) config('insurer.webhook.signature_header')),
        );

        return ApiResponse::data($result->toArray(), $result->httpStatus());
    }
}
