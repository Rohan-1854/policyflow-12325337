<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'PolicyFlow',
    'description' => 'Fault-tolerant insurance issuance and reconciliation engine',
    'api' => url('/api/policy-requests'),
    'health' => url('/up'),
]));
