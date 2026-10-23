<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * Integer-only money helpers. Amounts are carried in paise (1/100 rupee).
 */
final class Money
{
    /**
     * Divide a non-negative integer numerator by a positive denominator and
     * round half-up to the nearest integer, without floats.
     */
    public static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator <= 0) {
            throw new InvalidArgumentException('Only non-negative amounts and positive divisors are supported.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        // remainder / denominator >= 0.5  <=>  2 * remainder >= denominator
        return 2 * $remainder >= $denominator ? $quotient + 1 : $quotient;
    }

    /** 22500 => "225.00" */
    public static function formatPaise(int $paise): string
    {
        if ($paise < 0) {
            throw new InvalidArgumentException('Negative amounts are not supported.');
        }

        return sprintf('%d.%02d', intdiv($paise, 100), $paise % 100);
    }

    /** 180 => "1.80" */
    public static function formatHundredths(int $hundredths): string
    {
        return self::formatPaise($hundredths);
    }
}
