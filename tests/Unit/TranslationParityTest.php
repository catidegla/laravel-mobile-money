<?php

declare(strict_types=1);

namespace Catidegla\MobileMoney\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The locale files have to stay in lockstep.
 *
 * A missing key renders the key itself to the payer. A placeholder that got
 * translated along with the sentence never substitutes, so the payer reads a
 * literal ":reference" on the page. Neither throws, and neither shows up in a
 * normal test run, which is why they get their own test.
 */
final class TranslationParityTest extends TestCase
{
    private const LOCALES = ['fr', 'en'];

    /** @return array<string, string> flattened to dot notation */
    private function load(string $locale): array
    {
        $lines = require __DIR__.'/../../lang/'.$locale.'/mobile-money.php';

        $flat = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                is_array($value) ? $walk($value, $path) : $flat[$path] = (string) $value;
            }
        };
        $walk($lines, '');

        return $flat;
    }

    /** @return string[] sorted, so order of appearance does not matter */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }

    public function test_every_locale_has_the_same_keys(): void
    {
        $reference = $this->load(self::LOCALES[0]);

        foreach (array_slice(self::LOCALES, 1) as $locale) {
            $target = $this->load($locale);

            $missing = array_diff(array_keys($reference), array_keys($target));
            $extra = array_diff(array_keys($target), array_keys($reference));

            $this->assertSame([], array_values($missing), "{$locale} is missing keys");
            $this->assertSame([], array_values($extra), "{$locale} has keys not in ".self::LOCALES[0]);
        }
    }

    public function test_placeholders_are_never_translated(): void
    {
        $reference = $this->load(self::LOCALES[0]);

        foreach (array_slice(self::LOCALES, 1) as $locale) {
            $target = $this->load($locale);

            foreach ($reference as $key => $source) {
                $this->assertSame(
                    $this->placeholders($source),
                    $this->placeholders($target[$key] ?? ''),
                    "placeholders differ for {$key} in {$locale}, which means one of them will render literally",
                );
            }
        }
    }

    public function test_no_value_is_empty(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->load($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "{$locale}.{$key} is empty");
            }
        }
    }

    public function test_every_payment_status_has_a_message(): void
    {
        // A status with no string leaves the interface blank at exactly the
        // moment the customer is looking for reassurance.
        foreach (\Catidegla\MobileMoney\Enums\PaymentStatus::cases() as $status) {
            foreach (self::LOCALES as $locale) {
                $this->assertArrayHasKey(
                    'status.'.$status->value,
                    $this->load($locale),
                    "no {$locale} message for status {$status->value}",
                );
            }
        }
    }
}
