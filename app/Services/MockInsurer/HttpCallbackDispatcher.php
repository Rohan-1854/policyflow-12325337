<?php

namespace App\Services\MockInsurer;

use App\Services\Callbacks\CallbackSigner;
use Illuminate\Http\Client\Factory as HttpFactory;

/** Delivers the callback over real HTTP to POST /api/mock-insurer/callback. */
final class HttpCallbackDispatcher implements CallbackDispatcher
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CallbackSigner $signer,
        private readonly string $callbackUrl,
    ) {
    }

    public function dispatch(array $event): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        $response = $this->http
            ->acceptJson()
            ->timeout(10)
            ->withHeaders([(string) config('insurer.webhook.signature_header') => $this->signer->header($body)])
            ->withBody($body, 'application/json')
            ->post($this->callbackUrl);

        return [
            'transport' => 'http',
            'http_status' => $response->status(),
            'result' => $response->json('data.result') ?? $response->json('error.code'),
        ];
    }
}
