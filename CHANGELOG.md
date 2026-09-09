# Changelog

All notable changes to this package are documented here. This project follows [semantic versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.0

### Added

- **`Money::fromBrick()` and `Money::toBrick()`.** [brick/money](https://github.com/brick/money) is the better money type and this package does not compete with it. If you already hold a `Brick\Money\Money`, hand it over: the minor units cross exactly as they are, so a zero-decimal currency cannot pick up a factor of a hundred at the boundary. brick/money stays an optional dependency, listed under `suggest` and required only for the tests.
- A currency brick/money carries and this package does not is refused with a message naming the code and pointing at the enum, instead of a bare `ValueError` from `Currency::from()`.

### Changed

- **Repositioned around the provider layer.** The README, the package description and the `Money` docblock all led with zero-decimal currency handling, which implied that no PHP money library models ISO 4217 exponents. brick/money ships the full table with XOF at zero, and moneyphp/money handles exponents too. The provider drivers, idempotency, webhook verification and reconciliation are what this package actually adds, so that is what it says now.

## 0.2.1

### Fixed

- **`Money::multiply()` rounds half to even rather than half up.** It is the one place in the package that has to round, because a percentage fee on an odd number of francs lands on a half and no provider can move half of one. It was using PHP's `round()`, whose default is half away from zero, and the docblock presented that as a decision rather than a default nobody had questioned. On a fee, half up sends every exact `.5` in the merchant's direction. Across the worst case now covered by a test, every odd amount from 1 to 999 halved, half up comes out 250 francs above half to even, and that difference is money taken from customers a franc at a time. The rounding mode is exposed as a second parameter, since some tax authorities mandate a specific one.

  Raised by [dshafik](https://www.reddit.com/r/PHP/comments/1wbcoh2/) on r/PHP.

  This changes the result of a public method. If you depended on half up, pass `PHP_ROUND_HALF_UP` explicitly.

## 0.2.0

First release with anything verified against a live provider sandbox rather than only against the published contract. See [SANDBOX.md](SANDBOX.md) for the full run and what it does and does not prove.

### Fixed

- **A replayed `X-Reference-Id` no longer raises an exception.** MTN answers a repeated reference with `409 RESOURCE_ALREADY_EXIST`, which is the idempotency key working: the first request was accepted and the second changed nothing. The driver treated any non `202` response as a rejection and threw, so a caller whose request timed out and who retried with the same key, exactly what the key is for, got an error. The obvious next move for that caller is to issue a fresh reference and send it again, which is a second charge. `collect()` now returns the transaction as `Pending` with `raw['duplicate'] => true` and leaves the caller to poll for the real state.

### Added

- The reason codes MTN's sandbox actually returns, with tests. `APPROVAL_REJECTED` is what a decline produces and `EXPIRED` is what a timeout produces; `PAYER_REJECTION` and `PAYER_DELAYED` appear in the published contract but were never observed. `INTERNAL_PROCESSING_ERROR` is a genuine failure and stays one, with a message telling the operator to query the status again before retrying.
- `CHANGELOG.md`.

### Changed

- `scripts/sandbox-mtn.mjs` distinguishes a reason the driver deliberately leaves as a plain failure from one it has never seen, instead of reporting both as gaps. It also explains a `401` on the token call, which almost always means the subscription key belongs to a different product.
- The provider status table says **partly** for MTN and spells out which paths were exercised, rather than implying more or less than was actually run.

## 0.1.0

Initial release.

- `collect()` and `status()` for MTN MoMo, Wave and Orange Money behind one `Provider` contract.
- `Money` in integer minor units, so XOF and its zero decimals cannot be quietly rounded through a float.
- `Msisdn` parsing and country detection for UEMOA and neighbouring markets.
- `PaymentStatus` with an `Unknown` state for requests that time out, separated from `Failed` so a timeout never invites a retry that could charge twice.
- Webhook verification: HMAC-SHA256 for Wave, `notif_token` comparison for Orange Money, with the token encrypted at rest.
- Reconciliation command with per row backoff for payments left pending.
- French and English translations, with a test asserting they stay in step.
