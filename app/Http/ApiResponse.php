<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * Single place that defines the API envelope.
 *
 * Success: { "data": {...}, "meta": {...} }
 * Error:   { "error": { "code": "...", "message": "...", "details": {...} } }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function data(array $data, int $status = 200, array $meta = [], array $headers = []): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, string $message, int $status, ?array $details = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }
}
