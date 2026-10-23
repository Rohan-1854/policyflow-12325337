<?php

namespace Database\Seeders;

use App\Services\IntakeService;
use Illuminate\Database\Seeder;

/**
 * Optional demo data: `php artisan db:seed`. Creates one VALIDATED request per
 * mock scenario (fabricated data only). Safe to run twice - intake is idempotent.
 */
class DatabaseSeeder extends Seeder
{
    public function run(IntakeService $intake): void
    {
        $scenarios = ['SUCCESS', 'TEMPORARY_FAILURE', 'TIMEOUT_AFTER_ISSUE', 'TIMEOUT_BEFORE_ISSUE', 'DELAYED_CALLBACK', 'DUPLICATE_CALLBACK', 'PERMANENT_FAILURE'];

        foreach ($scenarios as $i => $scenario) {
            $result = $intake->intake([
                'client_request_id' => 'SEED-'.$scenario,
                'agent_id' => 'AGENT-SEED',
                'customer_name' => 'Seed Customer '.($i + 1),
                'date_of_birth' => '1990-0'.(($i % 9) + 1).'-15',
                'destination' => $i % 2 === 0 ? 'INTERNATIONAL' : 'DOMESTIC',
                'trip_start' => '2026-12-01',
                'trip_end' => '2026-12-0'.(($i % 7) + 2),
                'plan_code' => ['BASIC', 'PLUS', 'PREMIUM'][$i % 3],
                'sum_insured' => [100000, 300000, 500000][$i % 3],
                'mock_scenario' => $scenario,
            ]);

            $this->command?->line(sprintf('%-22s %s  premium %s', $scenario, $result->request->uuid, $result->request->premium));
        }
    }
}
