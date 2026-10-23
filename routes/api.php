<?php

use App\Http\Controllers\Api\PolicyRequestController;
use App\Http\Controllers\Api\ProviderCallbackController;
use App\Http\Controllers\Mock\MockInsurerController;
use App\Http\Middleware\EnsureMockInsurerEnabled;
use Illuminate\Support\Facades\Route;

/*
| Public contract (see README "API").
*/
Route::prefix('policy-requests')->group(function (): void {
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('{policyRequest}', [PolicyRequestController::class, 'show'])->whereUuid('policyRequest');
    Route::post('{policyRequest}/submit', [PolicyRequestController::class, 'submit'])->whereUuid('policyRequest');
});

// Insurer -> PolicyFlow webhook. HMAC-signed; part of the required contract.
Route::post('mock-insurer/callback', ProviderCallbackController::class);

/*
| The simulated insurer itself (development only).
*/
Route::prefix('mock-insurer')->middleware(EnsureMockInsurerEnabled::class)->group(function (): void {
    Route::post('policies', [MockInsurerController::class, 'issue']);
    Route::get('policies/{idempotencyKey}', [MockInsurerController::class, 'lookup'])->where('idempotencyKey', '[A-Za-z0-9._:\-]{1,64}');
    Route::post('deliver-callbacks', [MockInsurerController::class, 'deliverCallbacks']);
});
