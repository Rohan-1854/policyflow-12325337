<?php

/**
 * Fires N identical POST /api/policy-requests at the same moment (curl_multi)
 * and reports how many were created (201) vs replayed (200). Exactly one 201
 * and a single row in the database is the expected result.
 *
 * Usage:
 *   php examples/concurrency/parallel_intake.php [N=100] [base_url=http://127.0.0.1:8000]
 *
 * IMPORTANT: `php artisan serve` handles one request at a time by default,
 * which serialises everything and proves nothing about races. Use a server
 * with several workers, e.g. on Linux/macOS:
 *   PHP_CLI_SERVER_WORKERS=8 php artisan serve
 * (PHP_CLI_SERVER_WORKERS is not supported on Windows; use Docker/WSL, Herd,
 * Laragon with Apache/Nginx + PHP-FPM, or Octane there.)
 */

$n = (int) ($argv[1] ?? 100);
$base = rtrim($argv[2] ?? 'http://127.0.0.1:8000', '/');

$payload = json_encode([
    'client_request_id' => 'LOAD-'.date('YmdHis'),
    'agent_id' => 'AGENT-LOAD',
    'customer_name' => 'Load Test Customer',
    'date_of_birth' => '1990-01-01',
    'destination' => 'DOMESTIC',
    'trip_start' => '2026-12-01',
    'trip_end' => '2026-12-05',
    'plan_code' => 'BASIC',
    'sum_insured' => 100000,
]);

$multi = curl_multi_init();
$handles = [];

for ($i = 0; $i < $n; $i++) {
    $h = curl_init($base.'/api/policy-requests');
    curl_setopt_array($h, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_multi_add_handle($multi, $h);
    $handles[] = $h;
}

$start = microtime(true);
do {
    $status = curl_multi_exec($multi, $running);
    if ($running) {
        curl_multi_select($multi);
    }
} while ($running && $status === CURLM_OK);
$elapsed = microtime(true) - $start;

$codes = [];
$ids = [];
foreach ($handles as $h) {
    $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
    $codes[$code] = ($codes[$code] ?? 0) + 1;
    $body = json_decode((string) curl_multi_getcontent($h), true);
    if (isset($body['data']['id'])) {
        $ids[$body['data']['id']] = true;
    }
    curl_multi_remove_handle($multi, $h);
    curl_close($h);
}
curl_multi_close($multi);

ksort($codes);
printf("%d requests in %.2fs\n", $n, $elapsed);
foreach ($codes as $code => $count) {
    printf("  HTTP %s: %d\n", $code ?: 'n/a', $count);
}
printf("Distinct policy request ids returned: %d (expected 1)\n", count($ids));
printf("Verdict: %s\n", (($codes[201] ?? 0) === 1 && count($ids) === 1) ? 'PASS' : 'CHECK OUTPUT');
