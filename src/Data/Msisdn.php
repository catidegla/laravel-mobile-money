<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Data;

use Catidegla\MobileMoney\Exceptions\InvalidPhoneNumberException;
use JsonSerializable;
use Stringable;

/**
 * A mobile subscriber number, normalised to E.164.
 *
 * Users type phone numbers every way imaginable: with spaces, with dots, with
 * a leading zero, with the country code, without it. Providers accept exactly
 * one shape, and a mistyped MSISDN is a payment that silently goes to a
 * stranger or fails with an unhelpful provider error.
 *
 * Numbering plans in this region also move. Benin added a mandatory 01 prefix
 * on 30 November 2024 and old eight digit numbers stopped connecting, and Cote
 * d'Ivoire did the same thing in January 2021. Both migrations are still
 * producing broken stored numbers, so they get an explicit error rather than a
 * generic "invalid number".
 */
final class Msisdn implements JsonSerializable, Stringable
{
    /**
     * Dialling code, accepted national number lengths, and the migration note
     * that applies when a number matches a retired length.
     *
     * @var array<string, array{code: string, lengths: int[], retired?: array{length: int, prefix: string, date: string}}>
     */
    private const PLANS = [
        // UEMOA, XOF
        //
        // trunkZero is false where a leading zero is a significant digit rather
        // than national trunk notation. Benin and Cote d'Ivoire both prepended
        // a 0-leading pair during their migrations, so stripping it would turn
        // a valid number into a different, possibly live, subscriber.
        'BJ' => ['code' => '229', 'lengths' => [10], 'trunkZero' => false, 'retired' => ['length' => 8, 'prefix' => '01', 'date' => '30 November 2024']],
        'BF' => ['code' => '226', 'lengths' => [8]],
        'CI' => ['code' => '225', 'lengths' => [10], 'trunkZero' => false, 'retired' => ['length' => 8, 'prefix' => '', 'date' => '31 January 2021']],
        'GW' => ['code' => '245', 'lengths' => [7, 9]],
        'ML' => ['code' => '223', 'lengths' => [8]],
        'NE' => ['code' => '227', 'lengths' => [8]],
        'SN' => ['code' => '221', 'lengths' => [9]],
        'TG' => ['code' => '228', 'lengths' => [8]],

        // CEMAC, XAF
        'CM' => ['code' => '237', 'lengths' => [9]],
        'CF' => ['code' => '236', 'lengths' => [8]],
        'TD' => ['code' => '235', 'lengths' => [8]],
        'CG' => ['code' => '242', 'lengths' => [9]],
        'GQ' => ['code' => '240', 'lengths' => [9]],
        'GA' => ['code' => '241', 'lengths' => [8, 9]],

        'GN' => ['code' => '224', 'lengths' => [9]],
    ];

    /**
     * Operator prefixes, only where they are documented and stable.
     *
     * Cote d'Ivoire assigns the leading pair by operator, which lets a caller
     * route a payment without asking the user which network they are on. Other
     * markets are deliberately absent rather than guessed: a wrong operator
     * hint routes a payment to a provider that will reject it.
     *
     * @var array<string, array<string, string>>
     */
    private const OPERATOR_PREFIXES = [
        'CI' => [
            '01' => 'orange',
            '05' => 'mtn',
            '07' => 'moov',
            '27' => 'orange',
        ],
    ];

    private function __construct(
        public readonly string $countryCode,
        public readonly string $nationalNumber,
        public readonly string $country,
    ) {}

    /**
     * @param string      $input   anything a user or a database might hold
     * @param string|null $default ISO 3166-1 alpha-2, used when the input has no country code
     */
    public static function parse(string $input, ?string $default = null): self
    {
        $original = $input;
        $digits = preg_replace('/[^\d+]/', '', $input) ?? '';

        if ($digits === '') {
            throw InvalidPhoneNumberException::empty($original);
        }

        $hadPlus = str_starts_with($digits, '+');
        $digits = ltrim($digits, '+');

        // "00229..." is the other way of writing "+229...".
        if (! $hadPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hadPlus = true;
        }

        if ($hadPlus) {
            return self::fromInternational($digits, $original);
        }

        if ($default === null) {
            throw InvalidPhoneNumberException::noCountry($original);
        }

        $country = strtoupper($default);
        if (! isset(self::PLANS[$country])) {
            throw InvalidPhoneNumberException::unsupportedCountry($country);
        }

        // A number stored with its own country code but no plus, for example
        // "22901971234 56" pasted from a provider callback.
        $code = self::PLANS[$country]['code'];
        if (str_starts_with($digits, $code) && self::lengthAllowed($country, strlen($digits) - strlen($code))) {
            $digits = substr($digits, strlen($code));
        }

        return self::build($country, $digits, $original);
    }

    private static function fromInternational(string $digits, string $original): self
    {
        // Longest dialling code first so 229 is not shadowed by 22.
        $plans = self::PLANS;
        uasort($plans, static fn ($a, $b) => strlen($b['code']) <=> strlen($a['code']));

        foreach ($plans as $country => $plan) {
            if (! str_starts_with($digits, $plan['code'])) {
                continue;
            }

            return self::build($country, substr($digits, strlen($plan['code'])), $original);
        }

        throw InvalidPhoneNumberException::unknownDiallingCode($original);
    }

    private static function build(string $country, string $national, string $original): self
    {
        $plan = self::PLANS[$country];

        // A single leading zero is national trunk notation in the markets that
        // use one. Where trunkZero is false the digit is significant and must
        // survive untouched.
        if (($plan['trunkZero'] ?? true)
            && ! self::lengthAllowed($country, strlen($national))
            && str_starts_with($national, '0')
            && self::lengthAllowed($country, strlen($national) - 1)) {
            $national = substr($national, 1);
        }

        if (! self::lengthAllowed($country, strlen($national))) {
            // The number matches the plan that was retired, which is the most
            // common cause of a stored number failing years after the change.
            //
            // Length alone is not enough. A number that already carries the
            // migration prefix is simply the wrong length, and suggesting it be
            // prefixed again would be nonsense.
            $alreadyMigrated = isset($plan['retired'])
                && $plan['retired']['prefix'] !== ''
                && str_starts_with($national, $plan['retired']['prefix']);

            if (isset($plan['retired']) && ! $alreadyMigrated && strlen($national) === $plan['retired']['length']) {
                throw InvalidPhoneNumberException::retiredPlan(
                    $original,
                    $country,
                    $plan['retired']['prefix'],
                    $plan['retired']['date'],
                    $national,
                );
            }

            throw InvalidPhoneNumberException::wrongLength($original, $country, $plan['lengths'], strlen($national));
        }

        return new self($plan['code'], $national, $country);
    }

    private static function lengthAllowed(string $country, int $length): bool
    {
        return in_array($length, self::PLANS[$country]['lengths'], true);
    }

    /** E.164, the form to store. */
    public function e164(): string
    {
        return '+'.$this->countryCode.$this->nationalNumber;
    }

    /**
     * International without the plus. MTN MoMo wants this shape for partyId,
     * and several others accept nothing else.
     */
    public function msisdn(): string
    {
        return $this->countryCode.$this->nationalNumber;
    }

    public function national(): string
    {
        return $this->nationalNumber;
    }

    /**
     * Which network this number is on, when the numbering plan says so
     * unambiguously. Null means ask the user rather than guess.
     */
    public function operatorHint(): ?string
    {
        $prefixes = self::OPERATOR_PREFIXES[$this->country] ?? [];

        foreach ($prefixes as $prefix => $operator) {
            if (str_starts_with($this->nationalNumber, $prefix)) {
                return $operator;
            }
        }

        return null;
    }

    /** Grouped for display, without claiming a locale-specific convention. */
    public function format(): string
    {
        $groups = str_split($this->nationalNumber, 2);

        return '+'.$this->countryCode.' '.implode(' ', $groups);
    }

    public function equals(self $other): bool
    {
        return $this->e164() === $other->e164();
    }

    public static function supportedCountries(): array
    {
        return array_keys(self::PLANS);
    }

    public function jsonSerialize(): array
    {
        return [
            'e164' => $this->e164(),
            'country' => $this->country,
            'operator' => $this->operatorHint(),
        ];
    }

    public function __toString(): string
    {
        return $this->e164();
    }
}
