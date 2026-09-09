# Verifying against a provider sandbox

The provider status table in the README says a driver is verified only when a real transaction has cleared against that provider's sandbox. This is the procedure for changing that, starting with MTN because its sandbox is self service and needs no commercial agreement.

It was last run on **9 September 2026**, and it half worked. Read the next section before you spend an hour on it, because the interesting part is why.

## MTN's Collections sandbox is full

Subscribing to the **Collections** product at [momodeveloper.mtn.com](https://momodeveloper.mtn.com) fails. The portal button works, the request goes out, and the server answers `400` while the page shows nothing at all. The error is only visible if you catch the rejected promise:

> Unable to subscribe to a product. Error: You've reached the maximum number of Subscriptions (25000) in Product. Please delete one or more Subscription(s) from Product to continue, or consider upgrading your plan to increase the limit.

That is the Azure API Management [per product subscription cap](https://learn.microsoft.com/en-us/azure/azure-resource-manager/management/azure-subscription-service-limits#limits---api-management-classic-tiers), hit on MTN's side. It is not your account, and nothing you do to your account will change it. Until MTN prunes those 25,000 subscriptions or moves to a tier with a higher ceiling, **nobody can newly subscribe to Collections**, which means nobody can newly test `/collection/v1_0/requesttopay`.

The cap is per product, not per tenant. Disbursements, Remittances and the Collection Widget each have their own ceiling, and Disbursements still had room. Subscription keys are scoped strictly to their product, so a Disbursements key gets `401 Access denied due to invalid subscription key` on `/collection/token/`. It cannot stand in for the real thing.

What it can do is exercise everything the two APIs share, which turned out to be most of the wire contract. That is what the results below come from, and each row says which endpoint produced it.

## What a sandbox run can and cannot prove

**It cannot prove the XOF handling**, which is the headline feature of this package. MTN's sandbox settles in EUR regardless of market, so a run exercises the two decimal path and never touches the zero decimal one. Sending `XOF` gets you this, which is worth knowing on its own:

```
HTTP 500  {"message":"Currency not supported.","code":"INVALID_CURRENCY"}
```

Note the `500`. A client error reported as a server error will send any retry middleware that treats 5xx as transient into a loop against a request that can never succeed. The XOF behaviour stays covered by unit tests and unverified against a live provider until somebody runs it in production with a market environment such as `mtnbenin`.

**It can prove** the request and response envelope, the idempotency behaviour, and which `reason` strings MTN actually returns. That last one is the most valuable thing here, and it is where this run found a bug.

## Results, 9 September 2026

Provisioning, on `/v1_0/apiuser`, which is shared by every product:

| Call | Observed | Matches the docs |
| :--- | :--- | :--- |
| `POST /v1_0/apiuser` | `201`, empty body | yes |
| `POST /v1_0/apiuser/{id}/apikey` | `201`, JSON with `apiKey` | yes |
| `POST /disbursement/token/` | `200`, JSON with `access_token` | yes |

The transaction envelope, on `/disbursement/v1_0/transfer` because Collections was unreachable. The test MSISDNs are the same set MTN documents for `requesttopay`:

| Test MSISDN | POST | Poll | `status` | `reason` |
| :--- | ---: | ---: | :--- | :--- |
| 46733123450 | 202 | 200 | `FAILED` | `INTERNAL_PROCESSING_ERROR` |
| 46733123451 | 202 | 200 | `FAILED` | `APPROVAL_REJECTED` |
| 46733123452 | 202 | 200 | `FAILED` | `EXPIRED` |
| 46733123453 | 202 | 200 | `PENDING` | still pending after 45s |
| 46733123454 | 202 | 200 | `PENDING`, then `SUCCESSFUL` | settled between 4s and 45s |
| 22997123456 (not a test number) | 202 | 200 | `SUCCESSFUL` | immediately |

Four things in that table are worth reading twice.

**Every POST answered `202` with an empty body**, including the ones that were already doomed. A driver that reports success straight from the initiating call is inventing a state it was never told about.

**`46733123453` never resolves.** It stays `PENDING` indefinitely, which makes it the number to use when testing what your reconciliation does with a payment that simply never lands.

**Any number that is not a test number succeeds immediately**, with no prompt and no delay. A run that only uses ordinary numbers proves close to nothing: every call returns `SUCCESSFUL` and none of the branches that matter ever execute.

**`PAYER_REJECTION` and `PAYER_DELAYED` never appeared.** Both are in the published contract and both are mapped by the driver, but the sandbox answers a decline with `APPROVAL_REJECTED` and a timeout with `EXPIRED`. If you are relying on the first pair, you are relying on documentation rather than observation.

`INTERNAL_PROCESSING_ERROR` was not in `REASON_MAP` and still is not, deliberately: it is a real failure, not a decline in disguise, so softening it would be wrong. It has a readable message in `describeReason()` that tells the operator to query the status again before retrying.

## The bug this found

Replaying an `X-Reference-Id` that MTN has already seen returns:

```
HTTP 409  {"message":"Duplicated reference id. Creation of resource failed.","code":"RESOURCE_ALREADY_EXIST"}
```

So idempotency is enforced on MTN's side, which is the good news. The bad news was on ours. `collect()` treated anything other than `202` as a rejection and threw, so a caller whose request timed out and who retried with the same key, exactly what the key is for, got an exception. The obvious next move for that caller is to issue a fresh reference and send it again, and that is a second charge: precisely the outcome the idempotency key exists to prevent.

A `409` now returns the transaction as `Pending` with `raw['duplicate'] => true`, and the caller polls for the real state. `a_replayed_reference_is_not_reported_as_a_new_failure` covers it.

This is the argument for running a sandbox against your own package. The bug was in the one path a unit test suite is least likely to cover, because you have to know the provider answers `409` before you can think to fake it.

## Getting credentials

1. Create an account at [momodeveloper.mtn.com](https://momodeveloper.mtn.com) and confirm the email.
2. Subscribe to the **Collections** product, and see the section above for why that currently fails. Your profile then shows a primary and secondary subscription key. Either works. This is `Ocp-Apim-Subscription-Key` in every call below.
3. Everything else you provision yourself with two API calls.

## The short version

Everything except creating the account is scripted:

```bash
node scripts/sandbox-mtn.mjs <your-subscription-key>
```

That provisions the API user and key, fetches a token, prints the `.env` block, then probes every documented test number and prints a table like the one above. It also names any reason string the driver does not already handle, and tells you if your key is for the wrong product.

The two manual steps are creating the developer account and subscribing, because neither has an API. The rest of this document explains what the script is doing and why the reason codes matter.

### Provision an API user, by hand

The script does all of this. It is written out because a runbook you cannot follow without running someone else's script is not a runbook.

The sandbox has no UI for this. You invent a UUID, and that UUID becomes your API user id.

```bash
REF=$(uuidgen)          # keep this, it is your MTN_MOMO_API_USER
SUB=your-subscription-key

curl -i -X POST https://sandbox.momodeveloper.mtn.com/v1_0/apiuser \
  -H "X-Reference-Id: $REF" \
  -H "Ocp-Apim-Subscription-Key: $SUB" \
  -H "Content-Type: application/json" \
  -d '{"providerCallbackHost":"webhook.site"}'
```

Expect `201` with no body, which is what it does. `providerCallbackHost` is a host, not a URL, so no scheme and no path. Use a [webhook.site](https://webhook.site) host while testing so you can see callbacks arrive.

### Generate the API key

```bash
curl -i -X POST "https://sandbox.momodeveloper.mtn.com/v1_0/apiuser/$REF/apikey" \
  -H "Ocp-Apim-Subscription-Key: $SUB"
```

Expect `201` and a JSON body with `apiKey`. That is `MTN_MOMO_API_KEY`. It is shown once.

### Configure the package

```dotenv
MOBILE_MONEY_PROVIDER=mtn_momo
MTN_MOMO_BASE_URL=https://sandbox.momodeveloper.mtn.com
MTN_MOMO_ENVIRONMENT=sandbox
MTN_MOMO_SUBSCRIPTION_KEY=your-subscription-key
MTN_MOMO_API_USER=the-uuid-you-generated
MTN_MOMO_API_KEY=the-key-from-the-previous-step

# The sandbox settles in EUR whatever market you are targeting.
MTN_MOMO_CURRENCY=EUR
MTN_MOMO_CALLBACK_URL=https://webhook.site/your-unique-id
```

The driver fetches and caches its own access token, so there is no token step to perform by hand.

## What to run

```php
use Catidegla\MobileMoney\Facades\MobileMoney;
use Catidegla\MobileMoney\Data\{CollectionRequest, Money, Msisdn};
use Catidegla\MobileMoney\Enums\Currency;

$transaction = MobileMoney::driver('mtn_momo')->collect(
    CollectionRequest::make(
        amount: Money::of(100, Currency::EUR),
        payer: Msisdn::parse('+46733123450'),
        reference: 'SANDBOX-1',
    ),
);

// Expect Pending. requesttopay answers 202 with no body, so anything
// else here means the driver is inventing a state.
$transaction->status;

// Poll with the same reference.
MobileMoney::driver('mtn_momo')->status($transaction->reference);
```

Five things to confirm, in this order. The first four now have observed answers on the disbursement side; the fifth is the one that costs real money if it is wrong.

1. **`collect` returns `Pending`, never `Succeeded`.** A `202` means accepted, not paid. If the driver ever reports success straight from `collect`, that is the worst possible bug in a payment package.
2. **Polling with the same reference returns the transaction.** The `X-Reference-Id` is the only handle; there is no other id.
3. **Each test number's `reason`,** against the table above. Differences between the collection and disbursement vocabularies are exactly what is still unknown.
4. **`46733123454` moves from pending to a final state** after about half a minute, which is the only way to exercise reconciliation against a real clock.
5. **The same reference sent twice does not create a second charge.** Expect `409 RESOURCE_ALREADY_EXIST`, and expect the driver to answer `Pending` rather than throw.

## When it is done

Update the provider status table in the README, and say exactly what was verified rather than implying more:

> MTN MoMo: collection wire contract verified against the sandbox on `<date>`. Zero decimal handling still unverified, because the sandbox settles in EUR.

Then open an issue or a pull request with the reason code table, because the next person should not have to rediscover it.

## Wave and Orange

Both need a commercial relationship before you get credentials, so neither is self service. Wave issues API keys through its business onboarding, and Orange Money issues them per market through the local operator. If you have either, the same five checks apply, with one addition for each: Wave signs webhooks with HMAC-SHA256 so verify a real signature rather than a synthetic one, and Orange sends back the `notif_token` it issued at creation, so confirm it matches what was stored and that a mismatched token is rejected.
