<?php

namespace Tests\Unit;

use App\Domain\Pricing\Money;
use App\Domain\Pricing\PremiumCalculator;
use App\Domain\Pricing\UnsupportedPricingInput;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private PremiumCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PremiumCalculator;
    }

    private static function d(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    public function test_example_from_the_brief_is_225(): void
    {
        $quote = $this->calculator->quote('PLUS', 'INTERNATIONAL', 300000, self::d('1998-04-10'), self::d('2026-11-10'), self::d('2026-11-14'));

        $this->assertSame(5, $quote->travelDays);
        $this->assertSame(28, $quote->ageAtTripStart);
        $this->assertSame('225.00', $quote->premium());
        $this->assertSame(22500, $quote->premiumPaise);
        $this->assertSame('1.80', $quote->breakdown()['destination_multiplier']);
        $this->assertSame('1.25', $quote->breakdown()['coverage_multiplier']);
    }

    /**
     * Age is taken on trip start; boundaries are inclusive.
     *
     * @return array<string, array{int, string}>
     */
    public static function ageBands(): array
    {
        return [
            'infant' => [0, '1.10'],
            'age 17' => [17, '1.10'],
            'age 18' => [18, '1.00'],
            'age 60' => [60, '1.00'],
            'age 61' => [61, '1.50'],
            'age 70' => [70, '1.50'],
            'age 71' => [71, '2.00'],
            'age 95' => [95, '2.00'],
        ];
    }

    #[DataProvider('ageBands')]
    public function test_age_multiplier_bands(int $age, string $expectedMultiplier): void
    {
        $start = self::d('2026-11-10');
        $dob = $start->modify("-{$age} years");

        $quote = $this->calculator->quote('BASIC', 'DOMESTIC', 100000, $dob, $start, $start);

        $this->assertSame($age, $quote->ageAtTripStart);
        $this->assertSame($expectedMultiplier, $quote->breakdown()['age_multiplier']);
    }

    public function test_age_is_measured_on_trip_start_not_today(): void
    {
        // Turns 18 the day after the trip starts -> still 17 -> 1.10.
        $quote = $this->calculator->quote('BASIC', 'DOMESTIC', 100000, self::d('2008-11-11'), self::d('2026-11-10'), self::d('2026-11-10'));

        $this->assertSame(17, $quote->ageAtTripStart);
        $this->assertSame('13.20', $quote->premium()); // 12 x 1 x 1.10
    }

    /**
     * @return array<string, array{string, string, int, string, string, string}>
     */
    public static function premiumCases(): array
    {
        return [
            // plan, destination, sum, start, end, expected
            'same-day trip counts as 1 day' => ['BASIC', 'DOMESTIC', 100000, '2026-01-01', '2026-01-01', '12.00'],
            'premium plan, 500k, intl' => ['PREMIUM', 'INTERNATIONAL', 500000, '2026-01-01', '2026-01-10', '810.00'], // 30*10*1*1.8*1.5
            'plus, 300k, domestic' => ['PLUS', 'DOMESTIC', 300000, '2026-03-01', '2026-03-03', '75.00'], // 20*3*1.25
            'across month/leap boundary' => ['BASIC', 'DOMESTIC', 100000, '2028-02-28', '2028-03-01', '36.00'], // 3 days
        ];
    }

    #[DataProvider('premiumCases')]
    public function test_premium_formula(string $plan, string $destination, int $sum, string $start, string $end, string $expected): void
    {
        $quote = $this->calculator->quote($plan, $destination, $sum, self::d('1990-06-15'), self::d($start), self::d($end));

        $this->assertSame($expected, $quote->premium());
    }

    public function test_senior_international_premium_combines_all_multipliers(): void
    {
        // 30 x 7 x 2.00 x 1.80 x 1.50 = 1134.00
        $quote = $this->calculator->quote('PREMIUM', 'INTERNATIONAL', 500000, self::d('1950-01-01'), self::d('2026-05-01'), self::d('2026-05-07'));

        $this->assertSame('1134.00', $quote->premium());
    }

    public function test_input_codes_are_case_insensitive(): void
    {
        $quote = $this->calculator->quote('plus', 'international', 300000, self::d('1998-04-10'), self::d('2026-11-10'), self::d('2026-11-14'));

        $this->assertSame('225.00', $quote->premium());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function unsupported(): array
    {
        return [
            'plan' => ['GOLD', 'DOMESTIC', 100000],
            'destination' => ['BASIC', 'MARS', 100000],
            'coverage' => ['BASIC', 'DOMESTIC', 200000],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_values_are_rejected(string $plan, string $destination, int $sum): void
    {
        $this->expectException(UnsupportedPricingInput::class);

        $this->calculator->quote($plan, $destination, $sum, self::d('1990-01-01'), self::d('2026-01-01'), self::d('2026-01-02'));
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->expectException(UnsupportedPricingInput::class);

        $this->calculator->quote('BASIC', 'DOMESTIC', 100000, self::d('1990-01-01'), self::d('2026-01-05'), self::d('2026-01-04'));
    }

    public function test_birth_after_trip_start_is_rejected(): void
    {
        $this->expectException(UnsupportedPricingInput::class);

        $this->calculator->quote('BASIC', 'DOMESTIC', 100000, self::d('2026-02-01'), self::d('2026-01-05'), self::d('2026-01-06'));
    }

    public function test_rounding_is_half_up_on_integers(): void
    {
        $this->assertSame(1, Money::divideRoundHalfUp(5, 10));   // 0.5 -> 1
        $this->assertSame(0, Money::divideRoundHalfUp(4, 10));   // 0.4 -> 0
        $this->assertSame(2, Money::divideRoundHalfUp(15, 10));  // 1.5 -> 2
        $this->assertSame(22500, Money::divideRoundHalfUp(22_500_000_000, 1_000_000));
        $this->assertSame('0.05', Money::formatPaise(5));
        $this->assertSame('1234.50', Money::formatPaise(123450));
    }
}
