<?php

namespace Tests\Unit;

use App\Enums\PolicyStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PolicyStatusTest extends TestCase
{
    public function test_issued_is_terminal(): void
    {
        $this->assertTrue(PolicyStatus::ISSUED->isTerminal());

        foreach (PolicyStatus::cases() as $to) {
            $this->assertFalse(PolicyStatus::ISSUED->canTransitionTo($to), "ISSUED -> {$to->value} must be rejected");
        }
    }

    /**
     * @return array<string, array{PolicyStatus, PolicyStatus}>
     */
    public static function invalidRegressions(): array
    {
        return [
            'ISSUED -> SUBMITTED' => [PolicyStatus::ISSUED, PolicyStatus::SUBMITTED],
            'ISSUED -> FAILED' => [PolicyStatus::ISSUED, PolicyStatus::FAILED],
            'ISSUED -> PENDING_CONFIRMATION' => [PolicyStatus::ISSUED, PolicyStatus::PENDING_CONFIRMATION],
            'PENDING_CONFIRMATION -> SUBMITTED' => [PolicyStatus::PENDING_CONFIRMATION, PolicyStatus::SUBMITTED],
            'NEEDS_RECONCILIATION -> SUBMITTED' => [PolicyStatus::NEEDS_RECONCILIATION, PolicyStatus::SUBMITTED],
            'VALIDATED -> ISSUED (skipping submit)' => [PolicyStatus::VALIDATED, PolicyStatus::ISSUED],
            'FAILED -> ISSUED (unverified)' => [PolicyStatus::FAILED, PolicyStatus::ISSUED],
            'RECEIVED -> SUBMITTED' => [PolicyStatus::RECEIVED, PolicyStatus::SUBMITTED],
        ];
    }

    #[DataProvider('invalidRegressions')]
    public function test_invalid_transitions_are_rejected(PolicyStatus $from, PolicyStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public function test_happy_and_recovery_paths_are_allowed(): void
    {
        $this->assertTrue(PolicyStatus::RECEIVED->canTransitionTo(PolicyStatus::VALIDATED));
        $this->assertTrue(PolicyStatus::VALIDATED->canTransitionTo(PolicyStatus::SUBMITTED));
        $this->assertTrue(PolicyStatus::SUBMITTED->canTransitionTo(PolicyStatus::ISSUED));
        $this->assertTrue(PolicyStatus::SUBMITTED->canTransitionTo(PolicyStatus::NEEDS_RECONCILIATION));
        $this->assertTrue(PolicyStatus::NEEDS_RECONCILIATION->canTransitionTo(PolicyStatus::ISSUED));
        $this->assertTrue(PolicyStatus::PENDING_CONFIRMATION->canTransitionTo(PolicyStatus::ISSUED));
        $this->assertTrue(PolicyStatus::FAILED->canTransitionTo(PolicyStatus::SUBMITTED));
    }
}
