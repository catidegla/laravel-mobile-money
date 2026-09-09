<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Data;

use Catidegla\MobileMoney\Enums\Currency;
use Catidegla\MobileMoney\Exceptions\InvalidMoneyException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money, held as an integer number of minor units.
 *
 * Never floats. 0.1 + 0.2 is not 0.3 in binary floating point, and a payment
 * library is the last place you want that to be true.
 *
 * For XOF and the other zero-decimal currencies the minor unit and the major
 * unit are the same thing, so `Money::of(500, Currency::XOF)` is five hundred
 * francs, not five francs. This is the distinction that generic payment
 * libraries lose.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $minorUnits,
        public readonly Currency $currency,
    ) {
        if ($minorUnits < 0) {
            throw InvalidMoneyException::negative($minorUnits, $currency);
        }
    }

    /**
     * Build from a major unit amount, the way a human writes it.
     *
     * 1500 XOF  -> 1500 francs
     * 15.50 USD -> 1550 cents
     *
     * A fractional amount in a zero-decimal currency is rejected rather than
     * rounded, because silently rounding someone's money is worse than failing.
     */
    public static function of(int|float|string $amount, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from(strtoupper($currency));

        if (is_string($amount)) {
            if (! is_numeric($amount)) {
                throw InvalidMoneyException::notNumeric($amount);
            }
            $amount = str_contains($amount, '.') ? (float) $amount : (int) $amount;
        }

        if (is_float($amount)) {
            if ($currency->isZeroDecimal() && fmod($amount, 1.0) !== 0.0) {
                throw InvalidMoneyException::fractionalZeroDecimal($amount, $currency);
            }

            // Round at the last possible moment, once, on a value that is about
            // to become an integer anyway.
            $minor = (int) round($amount * $currency->minorUnitFactor());
        } else {
            $minor = $amount * $currency->minorUnitFactor();
        }

        return new self($minor, $currency);
    }

    /**
     * Build from an already-minor-unit integer, which is what provider APIs
     * and our own database column hold.
     */
    public static function ofMinor(int $minorUnits, Currency|string $currency): self
    {
        return new self(
            $minorUnits,
            $currency instanceof Currency ? $currency : Currency::from(strtoupper($currency)),
        );
    }

    /**
     * The amount as the provider expects it in a request body.
     *
     * Every provider in this package quotes XOF in whole francs, so for CFA
     * this is the integer unchanged. Returning a string avoids a JSON encoder
     * turning 1500.00 into 1500 or 1.5e3 depending on the platform.
     */
    public function forProvider(): string
    {
        if ($this->currency->isZeroDecimal()) {
            return (string) $this->minorUnits;
        }

        return number_format(
            $this->minorUnits / $this->currency->minorUnitFactor(),
            $this->currency->minorUnitDigits(),
            '.',
            '',
        );
    }

    /** The amount a human would write, as a float. Display only, never arithmetic. */
    public function toMajorUnits(): float
    {
        return $this->minorUnits / $this->currency->minorUnitFactor();
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a rate, for fees.
     *
     * The result has to land on a whole minor unit, which for XOF means a whole
     * franc, because no provider can move half of one. Something has to give,
     * and the question is which way.
     *
     * Half to even, not half up. Half up sends every exact .5 in the same
     * direction, which on a fee is always the merchant's direction, and across
     * a few hundred thousand transactions that is a real transfer of money
     * taken a franc at a time from people who will never notice. Half to even
     * sends half of them each way and the bias cancels.
     *
     * Pass a different mode if a tax authority requires one, because some do.
     */
    public function multiply(float $factor, int $rounding = PHP_ROUND_HALF_EVEN): self
    {
        if ($factor < 0) {
            throw InvalidMoneyException::negativeFactor($factor);
        }

        return new self((int) round($this->minorUnits * $factor, 0, $rounding), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    /**
     * Human formatting. French is the working language across most of this
     * footprint, and it puts the symbol after the amount with a space as the
     * thousands separator: "1 500 CFA", not "CFA1,500".
     */
    public function format(string $locale = 'fr'): string
    {
        $digits = $this->currency->minorUnitDigits();

        if ($locale === 'fr') {
            $number = number_format($this->toMajorUnits(), $digits, ',', "\u{202F}");

            return $number.' '.$this->currency->symbol();
        }

        $number = number_format($this->toMajorUnits(), $digits, '.', ',');

        return $this->currency->isCfaFranc()
            ? $number.' '.$this->currency->symbol()
            : $this->currency->symbol().$number;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw InvalidMoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'minor_units' => $this->minorUnits,
            'currency' => $this->currency->value,
            'formatted' => $this->format(),
        ];
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
