<?php

namespace App\Domain\Pricing;

/**
 * The fictional rate card from the brief.
 *
 * Every multiplier is stored as an integer number of hundredths
 * ("1.80" => 180) so premium maths never touches floating point.
 */
final class PricingTable
{
    /** Base premium per travel day, whole rupees. */
    public const BASE_DAILY_RATE = [
        'BASIC' => 12,
        'PLUS' => 20,
        'PREMIUM' => 30,
    ];

    /** Destination multiplier in hundredths. */
    public const DESTINATION_MULTIPLIER = [
        'DOMESTIC' => 100,
        'INTERNATIONAL' => 180,
    ];

    /** Coverage (sum insured, rupees) => multiplier in hundredths. */
    public const COVERAGE_MULTIPLIER = [
        100000 => 100,
        300000 => 125,
        500000 => 150,
    ];

    /**
     * Age bands evaluated on trip start: [min age, max age (null = open), hundredths].
     *
     * @var list<array{0:int, 1:int|null, 2:int}>
     */
    public const AGE_BANDS = [
        [0, 17, 110],
        [18, 60, 100],
        [61, 70, 150],
        [71, null, 200],
    ];

    /** Longest trip we will quote; protects integer maths and catches typos. */
    public const MAX_TRAVEL_DAYS = 365;

    /** @return list<string> */
    public static function planCodes(): array
    {
        return array_keys(self::BASE_DAILY_RATE);
    }

    /** @return list<string> */
    public static function destinations(): array
    {
        return array_keys(self::DESTINATION_MULTIPLIER);
    }

    /** @return list<int> */
    public static function sumsInsured(): array
    {
        return array_keys(self::COVERAGE_MULTIPLIER);
    }

    public static function ageMultiplier(int $age): int
    {
        foreach (self::AGE_BANDS as [$min, $max, $multiplier]) {
            if ($age >= $min && ($max === null || $age <= $max)) {
                return $multiplier;
            }
        }

        throw new UnsupportedPricingInput("No age band for age {$age}.");
    }
}
