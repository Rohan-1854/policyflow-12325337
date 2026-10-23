<?php

namespace App\Http\Middleware;

use App\Exceptions\MockInsurerDisabled;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMockInsurerEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('insurer.mock.enabled')) {
            throw new MockInsurerDisabled;
        }

        return $next($request);
    }
}
