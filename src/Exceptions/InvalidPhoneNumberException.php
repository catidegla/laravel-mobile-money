<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Exceptions;

final class InvalidPhoneNumberException extends MobileMoneyException
{
    public static function empty(string $input): self
    {
        return new self(sprintf('Phone number "%s" contains no digits.', $input));
    }

    public static function noCountry(string $input): self
    {
        return new self(sprintf(
            'Phone number "%s" has no country code, and no default country was given. '.
            'Pass a country, for example Msisdn::parse($number, "BJ").',
            $input,
        ));
    }

    public static function unsupportedCountry(string $country): self
    {
        return new self(sprintf('Country "%s" is not in the supported numbering plans.', $country));
    }

    public static function unknownDiallingCode(string $input): self
    {
        return new self(sprintf(
            'Phone number "%s" does not start with a dialling code this package covers.',
            $input,
        ));
    }

    public static function wrongLength(string $input, string $country, array $expected, int $actual): self
    {
        return new self(sprintf(
            'Phone number "%s" has %d national digits, but %s numbers have %s.',
            $input,
            $actual,
            $country,
            count($expected) === 1 ? $expected[0] : implode(' or ', $expected),
        ));
    }

    /**
     * The number is well formed for a numbering plan that no longer exists.
     *
     * Worth its own message because the generic "wrong length" tells you
     * nothing, and this failure usually appears years after the migration when
     * an old row is finally used.
     */
    public static function retiredPlan(
        string $input,
        string $country,
        string $prefix,
        string $date,
        string $national,
    ): self {
        $suggestion = $prefix !== ''
            ? sprintf(' Numbers gained a leading %s, so this is most likely %s%s.', $prefix, $prefix, $national)
            : '';

        return new self(sprintf(
            'Phone number "%s" matches the %s numbering plan that was retired on %s.%s '.
            'Numbers in the old format no longer connect, so this one needs correcting at the source rather than padding.',
            $input,
            $country,
            $date,
            $suggestion,
        ));
    }
}
