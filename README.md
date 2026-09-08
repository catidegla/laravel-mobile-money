<div align="center">

# Laravel Mobile Money

**One Laravel API for West and Central African mobile money.**

Correct XOF. Numbering-plan aware phone parsing. Idempotent collections. Honest asynchronous state.

[![Tests](https://github.com/catidegla/laravel-mobile-money/actions/workflows/tests.yml/badge.svg)](https://github.com/catidegla/laravel-mobile-money/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-12-ff2d20)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

</div>

---

> **Status: in development.** MTN MoMo, Wave and Orange Money are implemented and tested. Moov, KKiaPay, CinetPay and PayDunya are next. See [Provider status](#provider-status) for exactly what works today, and read the note under it before going live.

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

There is no shortage of PHP packages in this space. They fall into two groups, and neither one does that job.

**Aggregator SDKs** are the well trodden option, and several are in good health: MeSomb shipped 3.1.2 in March 2026, NotchPay 2.0 in May 2025, Moneroo v0.2.0 in November 2025. Reaching for one means contracting with the aggregator, routing settlement through them and paying a share of every transaction. That is a reasonable trade and if it suits you, take it. It is a commercial decision more than a technical one, and this package is not an argument against it.

**Single provider SDKs** go direct, which is the other side of that trade, but each covers one provider. `faso-dev/orange-money-burkina-sdk` is Orange Money in Burkina Faso, `opsofts/laravel-mtn-momo` is MTN collections. Reaching three networks means installing three of them and then writing the layer underneath yourself: one amount type, one phone parser, one status enum, one idempotency story. That layer is most of the work and nearly all of the risk, and it is what the rest of this README is about.

This package is the second option with that layer already written. Direct to each provider, nobody in the payment path who does not have to be, one interface across all of them.

Figures were checked on Packagist on 8 September 2026. Note also that `mmchrist89/laravel-mobile-money` shares this package's name under a different vendor; it targets MTN and Airtel, has no tagged release, and has not changed since February 2026.

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

| Provider | Markets | Flow | Collections | Webhooks | Payouts | Sandbox verified |
| :--- | :--- | :--- | :---: | :---: | :---: | :---: |
| MTN MoMo | BJ, CI, CM, GN, GH, UG, RW | handset prompt | yes | planned | planned | **not yet** |
| Wave | SN, CI | redirect | yes | yes, HMAC signed | planned | **not yet** |
| Orange Money | CI, SN, ML, BF, CM, GN | redirect | yes | yes, token matched | planned | **not yet** |
| Moov Africa | BJ, CI, TG, BF, ML | | planned | planned | planned | no |
| KKiaPay | BJ | | planned | planned | planned | no |
| CinetPay | UEMOA | | planned | planned | planned | no |
| PayDunya | SN, CI, BJ, TG | | planned | planned | planned | no |

**The three flows are genuinely different**, and calling code has to branch on it. MTN pushes a prompt to the handset and returns nothing to redirect to. Wave and Orange both return a URL. `$transaction->requiresRedirect()` tells you which you got.

**Webhook verification differs too, and one is weaker than the other.** Wave signs the body with HMAC-SHA256 and a rotating secret. Orange does not sign anything: it issues a `notif_token` when the payment is created and sends the same token back, so verification means comparing it against the one you stored. That makes the token a bearer secret travelling in the request body, only as safe as the transport. Serve the notification URL over HTTPS and treat the token as a credential.

Because Orange needs a lookup this package cannot perform on its own, you have to teach it how:

```php
MobileMoney::driver('orange_money')->resolveNotifTokenUsing(
    fn (string $orderId) => Payment::where('reference', $orderId)->value('notif_token'),
);
```

Without a resolver, verification returns false for every callback. That is deliberate. The alternative is an endpoint that marks any order paid on request.

**On "sandbox verified".** Every driver is written against the provider's published API contract and covered by tests that assert the exact request shape. None has yet been run against a live provider sandbox, which needs merchant credentials from each one. That column will only say yes when a real transaction has cleared. Until then, treat the contract as documented rather than proven, and run your own sandbox test before going live.

## Install

```bash
composer require catidegla/laravel-mobile-money
php artisan vendor:publish --tag=mobile-money-config
```

Requires PHP 8.2 and **Laravel 12**. Laravel 11 is deliberately not supported: advisory
`PKSA-mdq4-51ck-6kdq` covers the whole `>=11.0.0,<12.0.0` range with no fix on that branch, so
Composer's default advisory policy refuses to install it. Supporting 11 would mean asking you to
turn that protection off.

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

### Webhooks

The package registers `POST /mobile-money/webhook/{provider}` on the `api` middleware group, so callbacks are not rejected by CSRF. Verification runs before the body is read, and listeners fire on the transition rather than on each delivery, so a provider retrying a callback does not deliver the order twice.

```php
Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event) {
    $event->record;       // the local row
    $event->transaction;  // what the provider said
});
```

Every rejection answers `202` with the same body, whether the signature failed, the provider is unknown, or the order does not exist. Different responses would let someone probing the endpoint work out which orders exist and which part of their forgery to fix.

### Reconciliation

Callbacks in this region are lost often enough that relying on them alone strands orders, and the customer whose money left their wallet will not accept "we did not get the notification". Treat polling as the primary path.

```php
// routes/console.php
Schedule::command('mobile-money:reconcile')->everyMinute();
```

The backoff lives on each row, so running every minute costs nothing for payments that are not due. Polling gives up once the configured schedule is exhausted, and `PaymentStatus::Unknown`, the state a timeout leaves behind, is picked up rather than treated as final.

### Persistence

`MobileMoneyTransaction` stores amounts as integer minor units, keeps the idempotency key unique, and encrypts Orange Money's `notif_token` at rest, since anyone holding it can forge a notification for that order.

It refuses to move a settled payment backwards. Out of order delivery is normal, and a stale failure arriving after a poll already confirmed success must not reopen the order.

## Testing

```bash
composer install
vendor/bin/phpunit
```

100 tests. The suite fakes HTTP and asserts the exact bytes sent to each provider, including that 10 000 XOF leaves as `"10000"`.

The webhook tests are the ones worth reading. They cover a tampered body, a signature from the wrong secret, a replayed callback outside the tolerance window, a missing or malformed header, both signatures during a key rotation, and the case where verification must fail closed because no secret is configured. There is also a test proving the raw request body is used rather than re-encoded JSON, since re-encoding can reorder keys and silently break every signature.

## Contributing

Adding a provider means implementing `Contracts\Provider`, plus `VerifiesWebhooks` if it signs callbacks. Two rules:

1. **Assert the wire format.** A test that only checks a driver returns a `Transaction` proves nothing. Assert the request body and headers.
2. **Map failures honestly.** If the provider flattens "declined" and "timed out" into one code, separate them. If you cannot tell, return `Unknown` rather than guessing.

## License

[MIT](LICENSE)
