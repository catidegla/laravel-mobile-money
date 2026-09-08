<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Unit;

use Catidegla\MobileMoney\Data\Msisdn;
use Catidegla\MobileMoney\Exceptions\InvalidPhoneNumberException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MsisdnTest extends TestCase
{
    public static function acceptedNumbers(): array
    {
        return [
            'benin international' => ['+229 01 97 12 34 56', null, '+2290197123456', 'BJ'],
            'benin national' => ['0197123456', 'BJ', '+2290197123456', 'BJ'],
            'benin no plus' => ['2290197123456', 'BJ', '+2290197123456', 'BJ'],
            'benin double zero' => ['00229 01 97 12 34 56', null, '+2290197123456', 'BJ'],
            'benin punctuated' => ['+229-01.97.12.34.56', null, '+2290197123456', 'BJ'],
            'ivory coast moov' => ['+225 07 12 34 56 78', null, '+2250712345678', 'CI'],
            'senegal' => ['+221 77 123 45 67', null, '+221771234567', 'SN'],
            'togo' => ['+228 90 12 34 56', null, '+22890123456', 'TG'],
            'cameroon' => ['+237 6 12 34 56 78', null, '+237612345678', 'CM'],
            'burkina faso' => ['+226 70 12 34 56', null, '+22670123456', 'BF'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedNumbers')]
    public function it_normalises_to_e164(string $input, ?string $country, string $expected, string $iso): void
    {
        $number = Msisdn::parse($input, $country);

        $this->assertSame($expected, $number->e164());
        $this->assertSame($iso, $number->country);
        $this->assertSame(ltrim($expected, '+'), $number->msisdn());
    }

    #[Test]
    public function ivorian_numbers_identify_their_operator(): void
    {
        $this->assertSame('orange', Msisdn::parse('+225 01 12 34 56 78')->operatorHint());
        $this->assertSame('mtn', Msisdn::parse('+225 05 12 34 56 78')->operatorHint());
        $this->assertSame('moov', Msisdn::parse('+225 07 12 34 56 78')->operatorHint());
    }

    #[Test]
    public function an_unknown_operator_returns_null_rather_than_a_guess(): void
    {
        // Routing a payment to the wrong network fails at the provider, so a
        // guess is worse than an admission of ignorance.
        $this->assertNull(Msisdn::parse('+221 77 123 45 67')->operatorHint());
    }

    #[Test]
    public function a_retired_benin_number_explains_the_migration(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->expectExceptionMessageMatches('/retired on 30 November 2024/');
        $this->expectExceptionMessageMatches('/0197123456/');

        Msisdn::parse('97 12 34 56', 'BJ');
    }

    #[Test]
    public function a_retired_ivorian_number_explains_the_migration(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->expectExceptionMessageMatches('/retired on 31 January 2021/');

        Msisdn::parse('12345678', 'CI');
    }

    #[Test]
    public function a_number_that_already_carries_the_prefix_is_not_told_to_add_it_again(): void
    {
        // Eight national digits starting with 01 is simply too short. Offering
        // the migration hint here would suggest a nonsense number.
        try {
            Msisdn::parse('+229 01 97 12 34');
            $this->fail('expected the number to be rejected');
        } catch (InvalidPhoneNumberException $e) {
            $this->assertStringContainsString('8 national digits', $e->getMessage());
            $this->assertStringNotContainsString('retired', $e->getMessage());
        }
    }

    #[Test]
    public function a_significant_leading_zero_is_never_stripped(): void
    {
        // Benin and Cote d'Ivoire numbers start with a real 0. Treating it as
        // trunk notation would silently produce a different, possibly live,
        // subscriber number.
        foreach ([['01971234567', 'BJ'], ['07123456789', 'CI']] as [$input, $country]) {
            try {
                $result = Msisdn::parse($input, $country);
                $this->fail("11 digits was accepted for {$country} as {$result->e164()}");
            } catch (InvalidPhoneNumberException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_number_without_a_country_is_refused(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);
        $this->expectExceptionMessageMatches('/no country code/');

        Msisdn::parse('771234567');
    }

    #[Test]
    public function a_number_outside_the_footprint_is_refused(): void
    {
        $this->expectException(InvalidPhoneNumberException::class);

        Msisdn::parse('+1 202 555 0143');
    }

    #[Test]
    public function equality_ignores_formatting(): void
    {
        $this->assertTrue(
            Msisdn::parse('+229 01 97 12 34 56')->equals(Msisdn::parse('0197123456', 'BJ')),
        );
    }
}
