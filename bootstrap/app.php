<?php

use App\Exceptions\PolicyFlowException;
use App\Http\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain errors (409 conflicts, 401 bad signature, ...) are expected
        // outcomes, not bugs, so they are not written to the error log.
        $exceptions->dontReport([PolicyFlowException::class]);

        $isApi = static fn (Request $request): bool => $request->is('api/*');

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error(
                'VALIDATION_FAILED',
                'The request payload is invalid.',
                422,
                ['fields' => $e->errors()],
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            return ApiResponse::error(
                'HTTP_'.$status,
                $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.',
                $status,
                null,
                $e->getHeaders(),
            );
        });

        // Last resort: never leak stack traces or SQL (which may contain
        // customer data) to API clients unless debug mode is on.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request) || config('app.debug') || $e instanceof HttpResponseException) {
                return null;
            }

            return ApiResponse::error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
        });
    })->create();
