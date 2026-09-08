<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Enums;

/**
 * Currencies reachable through West and Central African mobile money, with
 * their ISO 4217 minor unit exponent.
 *
 * The exponent is the whole reason this enum exists. XOF, XAF and GNF have an
 * exponent of zero: there is no such thing as a centime of CFA franc in
 * circulation, and every provider API in this region quotes amounts in whole
 * francs. Libraries that assume "money is always cents" and multiply by 100
 * send a request for 100 times the intended amount, or divide a response by
 * 100 and report 5 francs as 0.05.
 *
 * Both mistakes are silent. Neither is caught by a type system that models
 * money as a float.
 */
enum Currency: string
{
    /** West African CFA franc. Benin, Burkina Faso, Cote d'Ivoire, Guinea-Bissau, Mali, Niger, Senegal, Togo. */
    case XOF = 'XOF';

    /** Central African CFA franc. Cameroon, CAR, Chad, Congo, Equatorial Guinea, Gabon. */
    case XAF = 'XAF';

    /** Guinean franc. */
    case GNF = 'GNF';

    /** Rwandan franc. */
    case RWF = 'RWF';

    /** Ugandan shilling. */
    case UGX = 'UGX';

    case NGN = 'NGN';
    case GHS = 'GHS';
    case KES = 'KES';
    case USD = 'USD';
    case EUR = 'EUR';

    /**
     * ISO 4217 exponent: how many decimal places this currency subdivides into.
     */
    public function minorUnitDigits(): int
    {
        return match ($this) {
            self::XOF, self::XAF, self::GNF, self::RWF, self::UGX => 0,
            self::NGN, self::GHS, self::KES, self::USD, self::EUR => 2,
        };
    }

    /**
     * How many minor units make one major unit. 1 for the franc currencies,
     * 100 for the rest.
     */
    public function minorUnitFactor(): int
    {
        return 10 ** $this->minorUnitDigits();
    }

    public function isZeroDecimal(): bool
    {
        return $this->minorUnitDigits() === 0;
    }

    /**
     * The two CFA francs are pegged to the euro at a fixed statutory rate and
     * are not interchangeable with each other.
     */
    public function isCfaFranc(): bool
    {
        return $this === self::XOF || $this === self::XAF;
    }

    public function symbol(): string
    {
        return match ($this) {
            self::XOF, self::XAF => 'CFA',
            self::GNF => 'FG',
            self::RWF => 'FRw',
            self::UGX => 'USh',
            self::NGN => 'NGN',
            self::GHS => 'GHS',
            self::KES => 'KSh',
            self::USD => '$',
            self::EUR => 'EUR',
        };
    }

    /**
     * ISO 3166-1 alpha-2 codes where this currency is legal tender, used to
     * validate that a phone number and an amount belong to the same market.
     */
    public function countries(): array
    {
        return match ($this) {
            self::XOF => ['BJ', 'BF', 'CI', 'GW', 'ML', 'NE', 'SN', 'TG'],
            self::XAF => ['CM', 'CF', 'TD', 'CG', 'GQ', 'GA'],
            self::GNF => ['GN'],
            self::RWF => ['RW'],
            self::UGX => ['UG'],
            self::NGN => ['NG'],
            self::GHS => ['GH'],
            self::KES => ['KE'],
            self::USD, self::EUR => [],
        };
    }
}
