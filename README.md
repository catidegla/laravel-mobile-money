<div align="center">

# Laravel Mobile Money

**One Laravel API for West and Central African mobile money.**

Correct XOF. Numbering-plan aware phone parsing. Idempotent collections. Honest asynchronous state.

[![Tests](https://github.com/catidegla/laravel-mobile-money/actions/workflows/tests.yml/badge.svg)](https://github.com/catidegla/laravel-mobile-money/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012-ff2d20)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

</div>

---

> **Status: in development.** MTN MoMo is implemented and tested. Orange Money and Wave are next, then Moov, KKiaPay, CinetPay and PayDunya. The core layers, money, phone numbers, routing and state, are done and are what the rest builds on. See [Provider status](#provider-status) for exactly what works today.

```php
use Catidegla\MobileMoney\Facades\MobileMoney;
use Catidegla\MobileMoney\Data\{CollectionRequest, Money, Msisdn};
use Catidegla\MobileMoney\Enums\Currency;

$transaction = MobileMoney::collect(CollectionRequest::make(
    amount: Money::of(1500, Currency::XOF),
    payer: Msisdn::parse('+229 01 97 12 34 56'),
    reference: 'ORDER-42',
));

$transaction->status;      // PaymentStatus::Pending
$transaction->reference;   // poll or reconcile with this
```

No driver named. The payer's number decides which network handles it.

## Why this exists

Integrating mobile money in this region means writing the same four hundred lines against Orange Money, then again against MTN, then again against Wave, each with a different idea of what a phone number looks like and what an amount is.

The open source options are thin. The most installed single-provider PHP library has 21 stars and stopped being maintained; the unified packages that existed have gone. This is an attempt at the package that should exist.

## The parts that are easy to get wrong

### XOF is a zero-decimal currency

There is no centime of CFA franc in circulation. ISO 4217 gives XOF, XAF and GNF an exponent of **zero**, and every provider in the region quotes whole francs.

```php
Money::of(10000, Currency::XOF)->forProvider();   // "10000"
// A library that assumes money is cents sends "1000000". A hundredfold
// overcharge, silent, and invisible to a type system that models money
// as a float.
```

`Money` holds integer minor units and refuses rather than rounds:

```php
Money::of(1500.50, Currency::XOF);
// InvalidMoneyException: XOF is a zero-decimal currency, so 1500.5 cannot be
// represented. Round to a whole CFA before building the amount, and decide
// deliberately which way it rounds.

Money::of(100, Currency::XOF)->add(Money::of(100, Currency::XAF));
// InvalidMoneyException: Cannot combine XOF with XAF. XOF and XAF are both
// called the CFA franc but are separate currencies and are not interchangeable.
```

### Numbering plans move, and old numbers stop working

Benin added a mandatory `01` prefix on **30 November 2024**. Côte d'Ivoire did the same in **January 2021**. Numbers stored before those dates no longer connect, and the failure usually surfaces years later when an old row is finally charged.

```php
Msisdn::parse('97 12 34 56', 'BJ');
// InvalidPhoneNumberException: Phone number "97 12 34 56" matches the BJ
// numbering plan that was retired on 30 November 2024. Numbers gained a
// leading 01, so this is most likely 0197123456.
```

Côte d'Ivoire encodes the operator in the number, so routing needs no extra input:

```php
Msisdn::parse('+225 05 12 34 56 78')->operatorHint();  // "mtn"
Msisdn::parse('+225 07 12 34 56 78')->operatorHint();  // "moov"
Msisdn::parse('+221 77 123 45 67')->operatorHint();    // null, ask rather than guess
```

A wrong operator hint routes a payment to a provider that rejects it, so markets whose prefixes are not documented and stable return `null` instead of a guess.

### A timeout is not a failure

Mobile money is asynchronous. Initiating a collection sends a prompt to a handset; the customer then finds their phone and types a PIN, or does not.

`PaymentStatus` has an **`Unknown`** state for when a request times out and you genuinely do not know whether the provider received it.

```php
$transaction->status->allowsRetry();   // false for Unknown
```

Treating that as failed and retrying is how customers get charged twice. Query by the idempotency key first.

Providers also flatten distinctions that matter. MTN reports a customer declining the prompt and a customer never answering it both as `FAILED`; this package separates them into `Cancelled` and `Expired`, because only some are worth prompting again for.

## Provider status

| Provider | Markets | Collections | Payouts | Webhooks | Sandbox verified |
| :--- | :--- | :---: | :---: | :---: | :---: |
| MTN MoMo | BJ, CI, CM, GN, GH, UG, RW | yes | planned | planned | **not yet** |
| Orange Money | CI, SN, ML, BF, CM, GN | planned | planned | planned | no |
| Wave | SN, CI | planned | planned | planned | no |
| Moov Africa | BJ, CI, TG, BF, ML | planned | planned | planned | no |
| KKiaPay | BJ | planned | planned | planned | no |
| CinetPay | UEMOA | planned | planned | planned | no |
| PayDunya | SN, CI, BJ, TG | planned | planned | planned | no |

**On "sandbox verified".** Every driver is written against the provider's published API contract and covered by tests that assert the exact request shape. None has yet been run against a live provider sandbox, which needs merchant credentials from each one. That column will only say yes when a real transaction has cleared. Until then, treat the contract as documented rather than proven, and run your own sandbox test before going live.

## Install

```bash
composer require catidegla/laravel-mobile-money
php artisan vendor:publish --tag=mobile-money-config
```

```env
MTN_MOMO_BASE_URL=https://sandbox.momodeveloper.mtn.com
MTN_MOMO_ENVIRONMENT=sandbox
MTN_MOMO_SUBSCRIPTION_KEY=
MTN_MOMO_API_USER=
MTN_MOMO_API_KEY=
```

## Usage

### Collect

```php
$request = CollectionRequest::make(
    amount: Money::of(2500, Currency::XOF),
    payer: Msisdn::parse('0197123456', 'BJ'),
    reference: 'INV-2026-001',
    description: 'Abonnement mensuel',
);

$transaction = MobileMoney::collect($request);              // routed by number
$transaction = MobileMoney::collect($request, 'mtn_momo');  // or pinned
```

`CollectionRequest::make()` generates an idempotency key. Keep it across retries: calling `collect` twice with the same key must not create a second charge, and that guarantee is the only thing standing between a flaky connection and a double debit.

### Reconcile

```php
$transaction = MobileMoney::status($reference, 'mtn_momo');

if ($transaction->isSettled()) {
    // the only state safe to fulfil on
}
```

Webhooks in this region are not reliable enough to be the only path to a final state, so poll anything still pending. The `reconciliation` config block holds a backoff schedule and a give-up window.

### Route by hand

```php
MobileMoney::availableFor(Currency::XOF, 'CI');  // every driver serving that market
MobileMoney::routeFor($request);                 // the one it would pick
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

44 tests. The suite fakes HTTP and asserts the exact bytes sent to each provider, including that 10 000 XOF leaves as `"10000"`.

## Contributing

Adding a provider means implementing `Contracts\Provider`, plus `VerifiesWebhooks` if it signs callbacks. Two rules:

1. **Assert the wire format.** A test that only checks a driver returns a `Transaction` proves nothing. Assert the request body and headers.
2. **Map failures honestly.** If the provider flattens "declined" and "timed out" into one code, separate them. If you cannot tell, return `Unknown` rather than guessing.

## License

[MIT](LICENSE)
