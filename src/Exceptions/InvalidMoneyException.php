<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Exceptions;

use Catidegla\MobileMoney\Enums\Currency;

final class InvalidMoneyException extends MobileMoneyException
{
    public static function negative(int $minorUnits, Currency $currency): self
    {
        return new self(sprintf(
            'Amount cannot be negative, got %d %s. Use a payout rather than a negative collection.',
            $minorUnits,
            $currency->value,
        ));
    }

    public static function notNumeric(string $amount): self
    {
        return new self(sprintf('Amount "%s" is not numeric.', $amount));
    }

    /**
     * The mistake this whole value object exists to catch. Someone wrote
     * 1500.50 XOF, which cannot be sent, because the smallest unit of CFA
     * franc in circulation is one franc.
     */
    public static function fractionalZeroDecimal(float $amount, Currency $currency): self
    {
        return new self(sprintf(
            '%s is a zero-decimal currency, so %s cannot be represented. '.
            'Round to a whole %s before building the amount, and decide deliberately which way it rounds.',
            $currency->value,
            rtrim(rtrim(number_format($amount, 4, '.', ''), '0'), '.'),
            $currency->symbol(),
        ));
    }

    public static function currencyMismatch(Currency $a, Currency $b): self
    {
        $extra = $a->isCfaFranc() && $b->isCfaFranc()
            ? ' XOF and XAF are both called the CFA franc but are separate currencies and are not interchangeable.'
            : '';

        return new self(sprintf('Cannot combine %s with %s.%s', $a->value, $b->value, $extra));
    }

    public static function negativeFactor(float $factor): self
    {
        return new self(sprintf('Multiplier cannot be negative, got %s.', $factor));
    }
}
