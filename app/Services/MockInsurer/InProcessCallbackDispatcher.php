<?php

namespace App\Services\MockInsurer;

use App\Services\Callbacks\CallbackSigner;
use App\Services\Callbacks\ProviderCallbackService;

/**
 * Signs the event exactly like the HTTP path and hands the raw body to the
 * same service the callback controller uses, so signature verification and
 * idempotency are exercised without a second web server.
 */
final class InProcessCallbackDispatcher implements CallbackDispatcher
{
    public function __construct(
        private readonly ProviderCallbackService $receiver,
        private readonly CallbackSigner $signer,
    ) {
    }

    public function dispatch(array $event): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $result = $this->receiver->receive($body, $this->signer->header($body));

        return ['transport' => 'in-process', 'result' => $result->result];
    }
}
