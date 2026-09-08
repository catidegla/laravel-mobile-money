<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Unit;

use Catidegla\MobileMoney\Data\Money;
use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Exceptions\InvalidMoneyException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The zero-decimal behaviour is the reason this class exists, so it gets the
 * most attention. Getting it wrong sends a request for a hundred times the
 * intended amount, and nothing in a normal test suite would catch that.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function xof_has_no_minor_units(): void
    {
        $amount = Money::of(1500, Currency::XOF);

        $this->assertSame(1500, $amount->minorUnits);
        $this->assertSame('1500', $amount->forProvider());
        $this->assertSame(1500.0, $amount->toMajorUnits());
    }

    #[Test]
    public function a_cents_based_currency_still_works(): void
    {
        $amount = Money::of(15.50, Currency::USD);

        $this->assertSame(1550, $amount->minorUnits);
        $this->assertSame('15.50', $amount->forProvider());
    }

    #[Test]
    public function ten_thousand_xof_is_sent_as_ten_thousand(): void
    {
        // A library that assumes money is cents would send 1000000 here, which
        // is a hundredfold overcharge that no type system would object to.
        $this->assertSame('10000', Money::of(10000, Currency::XOF)->forProvider());
    }

    #[Test]
    public function a_fractional_amount_in_a_zero_decimal_currency_is_refused(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessageMatches('/zero-decimal/');

        Money::of(1500.50, Currency::XOF);
    }

    #[Test]
    public function a_whole_float_in_a_zero_decimal_currency_is_fine(): void
    {
        $this->assertSame(1500, Money::of(1500.0, Currency::XOF)->minorUnits);
    }

    #[Test]
    public function xof_and_xaf_do_not_mix(): void
    {
        $this->expectException(InvalidMoneyException::class);
        $this->expectExceptionMessageMatches('/not interchangeable/');

        Money::of(100, Currency::XOF)->add(Money::of(100, Currency::XAF));
    }

    #[Test]
    public function negative_amounts_are_refused(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::ofMinor(-1, Currency::XOF);
    }

    #[Test]
    public function the_provider_representation_round_trips(): void
    {
        foreach ([1, 5, 100, 2500, 999999] as $value) {
            $original = Money::of($value, Currency::XOF);
            $restored = Money::ofMinor((int) $original->forProvider(), Currency::XOF);

            $this->assertTrue($original->equals($restored), "round trip failed for {$value}");
        }
    }

    #[Test]
    public function fees_round_to_whole_francs(): void
    {
        // 1.5% of 10 000 is exactly 150, and a provider cannot move a fraction
        // of a franc, so the result must be an integer either way.
        $fee = Money::of(10000, Currency::XOF)->multiply(0.015);

        $this->assertSame(150, $fee->minorUnits);
        $this->assertSame(0.0, fmod($fee->toMajorUnits(), 1.0));
    }

    #[Test]
    public function arithmetic_never_drifts(): void
    {
        // The classic float failure: 0.1 + 0.2 !== 0.3.
        $total = Money::of(0.10, Currency::USD)
            ->add(Money::of(0.20, Currency::USD));

        $this->assertSame(30, $total->minorUnits);
        $this->assertSame('0.30', $total->forProvider());
    }

    #[Test]
    public function french_formatting_puts_the_symbol_last(): void
    {
        $formatted = Money::of(1500, Currency::XOF)->format('fr');

        $this->assertStringEndsWith('CFA', $formatted);
        $this->assertStringContainsString('1', $formatted);
        $this->assertStringNotContainsString(',00', $formatted);
    }

    #[Test]
    public function currencies_declare_their_iso_exponent(): void
    {
        foreach ([Currency::XOF, Currency::XAF, Currency::GNF] as $zeroDecimal) {
            $this->assertSame(0, $zeroDecimal->minorUnitDigits());
            $this->assertSame(1, $zeroDecimal->minorUnitFactor());
            $this->assertTrue($zeroDecimal->isZeroDecimal());
        }

        $this->assertSame(2, Currency::USD->minorUnitDigits());
        $this->assertSame(100, Currency::USD->minorUnitFactor());
        $this->assertFalse(Currency::USD->isZeroDecimal());
    }
}
