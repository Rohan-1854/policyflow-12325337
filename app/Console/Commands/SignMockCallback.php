<?php

namespace App\Console\Commands;

use App\Services\Callbacks\CallbackSigner;
use Illuminate\Console\Command;

/** Helper for manual testing: prints the signature header for a callback body. */
class SignMockCallback extends Command
{
    protected $signature = 'mock-insurer:sign {body : The exact JSON body you will POST}';

    protected $description = 'Print the X-Insurer-Signature header value for a callback body';

    public function handle(CallbackSigner $signer): int
    {
        $body = (string) $this->argument('body');

        if (! is_array(json_decode($body, true))) {
            $this->error('Body must be a JSON object.');

            return self::FAILURE;
        }

        $this->line(config('insurer.webhook.signature_header').': '.$signer->header($body));

        return self::SUCCESS;
    }
}
