<?php

namespace App\Console\Commands;

use App\Services\Callbacks\CallbackSigner;
use App\Services\MockInsurer\CallbackDispatcher;
use App\Services\MockInsurer\HttpCallbackDispatcher;
use App\Services\MockInsurer\MockInsurer;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;

class DeliverMockCallbacks extends Command
{
    protected $signature = 'mock-insurer:deliver-callbacks
                            {--key= : Only this idempotency key (policy request id)}
                            {--http : POST to APP_URL/api/mock-insurer/callback instead of in-process}';

    protected $description = 'Make the mock insurer confirm PENDING policies and send their (possibly duplicated) callbacks';

    public function handle(MockInsurer $insurer, CallbackDispatcher $inProcess, HttpFactory $http, CallbackSigner $signer): int
    {
        if (! config('insurer.mock.enabled')) {
            $this->error('The mock insurer is disabled in this environment.');

            return self::FAILURE;
        }

        $dispatcher = $this->option('http')
            ? new HttpCallbackDispatcher($http, $signer, rtrim((string) config('app.url'), '/').'/api/mock-insurer/callback')
            : $inProcess;

        $key = $this->option('key');
        $deliveries = $insurer->deliverPendingCallbacks($dispatcher, is_string($key) ? $key : null);

        if ($deliveries === []) {
            $this->info('No pending callbacks.');

            return self::SUCCESS;
        }

        $this->table(
            ['event_id', 'delivery', 'transport', 'result'],
            array_map(fn (array $d) => [$d['event_id'], $d['delivery'], $d['transport'] ?? '', (string) ($d['result'] ?? '')], $deliveries),
        );

        return self::SUCCESS;
    }
}
