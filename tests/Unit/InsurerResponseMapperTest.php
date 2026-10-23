<?php

namespace Tests\Unit;

use App\Services\Insurer\Exceptions\InsurerLookupFailed;
use App\Services\Insurer\Exceptions\InsurerPermanentFailure;
use App\Services\Insurer\Exceptions\InsurerRetriableFailure;
use App\Services\Insurer\Exceptions\InsurerTimeout;
use App\Services\Insurer\InsurerResponseMapper;
use App\Services\Insurer\LookupResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InsurerResponseMapperTest extends TestCase
{
    public function test_issued_and_pending_success_payloads(): void
    {
        $issued = InsurerResponseMapper::issue(201, ['status' => 'ISSUED', 'provider_reference' => 'MIR-1', 'policy_number' => 'TSP-1']);
        $this->assertTrue($issued->isIssued());
        $this->assertSame('TSP-1', $issued->policyNumber);

        $pending = InsurerResponseMapper::issue(202, ['status' => 'PENDING', 'provider_reference' => 'MIR-2']);
        $this->assertFalse($pending->isIssued());
        $this->assertNull($pending->policyNumber);
    }

    /**
     * The key safety property: anything that might have been processed is
     * "unknown" (InsurerTimeout), never a plain retriable failure.
     *
     * @return array<string, array{int, array<string, mixed>, class-string}>
     */
    public static function classifications(): array
    {
        return [
            '503 not processed' => [503, [], InsurerRetriableFailure::class],
            '429 rate limited' => [429, [], InsurerRetriableFailure::class],
            '422 declined' => [422, ['message' => 'no'], InsurerPermanentFailure::class],
            '400 bad request' => [400, [], InsurerPermanentFailure::class],
            '504 gateway timeout' => [504, [], InsurerTimeout::class],
            '500 server error' => [500, [], InsurerTimeout::class],
            '502 bad gateway' => [502, [], InsurerTimeout::class],
            '200 garbage body' => [200, ['hello' => 'world'], InsurerTimeout::class],
            '200 issued without number' => [200, ['status' => 'ISSUED', 'provider_reference' => 'X'], InsurerTimeout::class],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  class-string<\Throwable>  $expected
     */
    #[DataProvider('classifications')]
    public function test_issue_failure_classification(int $status, array $body, string $expected): void
    {
        $this->expectException($expected);

        InsurerResponseMapper::issue($status, $body);
    }

    public function test_lookup_mapping(): void
    {
        $this->assertSame(LookupResult::NOT_FOUND, InsurerResponseMapper::lookup(404, [])->status);

        $issued = InsurerResponseMapper::lookup(200, ['status' => 'ISSUED', 'provider_reference' => 'MIR-1', 'policy_number' => 'TSP-1']);
        $this->assertSame(LookupResult::ISSUED, $issued->status);
        $this->assertSame('TSP-1', $issued->policyNumber);

        $this->expectException(InsurerLookupFailed::class);
        InsurerResponseMapper::lookup(503, []);
    }
}
