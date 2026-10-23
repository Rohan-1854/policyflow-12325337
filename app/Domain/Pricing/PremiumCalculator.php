<?php

namespace App\Domain\Pricing;

use DateTimeImmutable;
use DateTimeZone;

/**
 * premium = base_daily_rate x travel_days x age_mult x destination_mult x coverage_mult
 *
 * Pure, deterministic and framework-free. All arithmetic is integer:
 * the three multipliers are in hundredths, so the exact premium in paise is
 *
 *     rate_rupees * 100 * days * age * dest * cov / 100^3
 *
 * which is then rounded half-up once, at the end.
 */
final class PremiumCalculator
{
    private const MULTIPLIER_SCALE = 1_000_000; // 100 ^ 3

    public function quote(
        string $planCode,
        string $destination,
        int $sumInsured,
        DateTimeImmutable $dateOfBirth,
        DateTimeImmutable $tripStart,
        DateTimeImmutable $tripEnd,
    ): PremiumQuote {
        $planCode = strtoupper($planCode);
        $destination = strtoupper($destination);

        if (! isset(PricingTable::BASE_DAILY_RATE[$planCode])) {
            throw new UnsupportedPricingInput("Unsupported plan_code '{$planCode}'.");
        }

        if (! isset(PricingTable::DESTINATION_MULTIPLIER[$destination])) {
            throw new UnsupportedPricingInput("Unsupported destination '{$destination}'.");
        }

        if (! isset(PricingTable::COVERAGE_MULTIPLIER[$sumInsured])) {
            throw new UnsupportedPricingInput("Unsupported sum_insured '{$sumInsured}'.");
        }

        $travelDays = self::travelDays($tripStart, $tripEnd);
        $age = self::ageOn($dateOfBirth, $tripStart);

        $rate = PricingTable::BASE_DAILY_RATE[$planCode];
        $ageMultiplier = PricingTable::ageMultiplier($age);
        $destinationMultiplier = PricingTable::DESTINATION_MULTIPLIER[$destination];
        $coverageMultiplier = PricingTable::COVERAGE_MULTIPLIER[$sumInsured];

        // Max: 30 * 100 * 365 * 200 * 180 * 150 ~= 5.9e12, far below PHP_INT_MAX.
        $numerator = $rate * 100 * $travelDays * $ageMultiplier * $destinationMultiplier * $coverageMultiplier;
        $premiumPaise = Money::divideRoundHalfUp($numerator, self::MULTIPLIER_SCALE);

        return new PremiumQuote(
            planCode: $planCode,
            destination: $destination,
            sumInsured: $sumInsured,
            travelDays: $travelDays,
            ageAtTripStart: $age,
            baseDailyRate: $rate,
            ageMultiplier: $ageMultiplier,
            destinationMultiplier: $destinationMultiplier,
            coverageMultiplier: $coverageMultiplier,
            premiumPaise: $premiumPaise,
        );
    }

    /** Inclusive day count: trip_end - trip_start + 1, minimum 1. */
    public static function travelDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $start = self::calendarDate($start);
        $end = self::calendarDate($end);

        if ($end < $start) {
            throw new UnsupportedPricingInput('trip_end must be on or after trip_start.');
        }

        $days = (int) $start->diff($end)->days + 1;

        if ($days > PricingTable::MAX_TRAVEL_DAYS) {
            throw new UnsupportedPricingInput('Trips longer than '.PricingTable::MAX_TRAVEL_DAYS.' days are not supported.');
        }

        return max(1, $days);
    }

    /** Completed years of age on the given date. */
    public static function ageOn(DateTimeImmutable $dateOfBirth, DateTimeImmutable $on): int
    {
        $dateOfBirth = self::calendarDate($dateOfBirth);
        $on = self::calendarDate($on);

        if ($dateOfBirth > $on) {
            throw new UnsupportedPricingInput('date_of_birth must be on or before trip_start.');
        }

        return $dateOfBirth->diff($on)->y;
    }

    /** Strip time and timezone so day arithmetic is pure calendar maths (no DST effects). */
    private static function calendarDate(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
    }
}
