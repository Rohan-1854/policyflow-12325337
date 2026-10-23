<?php

namespace App\Providers;

use App\Services\Callbacks\CallbackSigner;
use App\Services\Insurer\HttpInsurerGateway;
use App\Services\Insurer\InsurerGateway;
use App\Services\Insurer\LocalMockGateway;
use App\Services\MockInsurer\CallbackDispatcher;
use App\Services\MockInsurer\InProcessCallbackDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CallbackSigner::class, fn (): CallbackSigner => new CallbackSigner(
            (string) config('insurer.webhook.secret'),
            (bool) config('insurer.webhook.verify_signature'),
        ));

        $this->app->bind(InsurerGateway::class, function (Application $app): InsurerGateway {
            return match (config('insurer.driver')) {
                'http' => new HttpInsurerGateway(
                    $app->make(HttpFactory::class),
                    (string) config('insurer.http.base_url'),
                    (int) config('insurer.http.timeout_seconds'),
                ),
                'local' => config('insurer.mock.enabled')
                    ? $app->make(LocalMockGateway::class)
                    : throw new RuntimeException('INSURER_DRIVER=local needs the mock insurer, which is disabled in this environment.'),
                default => throw new RuntimeException('Unknown INSURER_DRIVER '.var_export(config('insurer.driver'), true).'.'),
            };
        });

        $this->app->bind(CallbackDispatcher::class, InProcessCallbackDispatcher::class);
    }

    public function boot(): void
    {
        //
    }
}
