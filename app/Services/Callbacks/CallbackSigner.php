<?php

namespace App\Services\Callbacks;

use App\Exceptions\InvalidCallbackSignature;
use Illuminate\Support\Facades\Log;

/**
 * HMAC-SHA256 over the raw request body, sent as "sha256=<hex>".
 * Verification uses a constant-time comparison and fails closed when no
 * secret is configured.
 */
final class CallbackSigner
{
    public function __construct(private readonly string $secret, private readonly bool $verify)
    {
    }

    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->secret);
    }

    public function header(string $rawBody): string
    {
        return 'sha256='.$this->sign($rawBody);
    }

    /**
     * @throws InvalidCallbackSignature
     */
    public function verify(string $rawBody, ?string $header): void
    {
        if (! $this->verify) {
            return;
        }

        if ($this->secret === '') {
            Log::warning('Insurer callback rejected: MOCK_INSURER_WEBHOOK_SECRET is not configured.');

            throw new InvalidCallbackSignature;
        }

        if ($header === null || ! str_starts_with($header, 'sha256=')) {
            throw new InvalidCallbackSignature;
        }

        if (! hash_equals($this->sign($rawBody), substr($header, 7))) {
            throw new InvalidCallbackSignature;
        }
    }
}
