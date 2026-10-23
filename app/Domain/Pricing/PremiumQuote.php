<?php

namespace App\Domain\Pricing;

/**
 * Immutable result of a premium calculation. This is what gets snapshotted
 * onto the policy request, so a later rate-card change never alters a
 * premium that was already accepted.
 */
final class PremiumQuote
{
    public function __construct(
        public readonly string $planCode,
        public readonly string $destination,
        public readonly int $sumInsured,
        public readonly int $travelDays,
        public readonly int $ageAtTripStart,
        public readonly int $baseDailyRate,
        public readonly int $ageMultiplier,
        public readonly int $destinationMultiplier,
        public readonly int $coverageMultiplier,
        public readonly int $premiumPaise,
    ) {
    }

    /** Premium as a fixed 2-decimal string, e.g. "225.00". */
    public function premium(): string
    {
        return Money::formatPaise($this->premiumPaise);
    }

    /**
     * @return array<string, mixed>
     */
    public function breakdown(): array
    {
        return [
            'currency' => 'INR',
            'formula' => 'base_daily_rate x travel_days x age_multiplier x destination_multiplier x coverage_multiplier',
            'plan_code' => $this->planCode,
            'base_daily_rate' => Money::formatPaise($this->baseDailyRate * 100),
            'travel_days' => $this->travelDays,
            'age_at_trip_start' => $this->ageAtTripStart,
            'age_multiplier' => Money::formatHundredths($this->ageMultiplier),
            'destination' => $this->destination,
            'destination_multiplier' => Money::formatHundredths($this->destinationMultiplier),
            'sum_insured' => $this->sumInsured,
            'coverage_multiplier' => Money::formatHundredths($this->coverageMultiplier),
            'premium' => $this->premium(),
        ];
    }
}
