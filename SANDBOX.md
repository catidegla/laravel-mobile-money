# Verifying against a provider sandbox

The provider status table in the README says a driver is verified only when a real transaction has cleared against that provider's sandbox. Nothing has yet. This is the exact procedure for changing that, starting with MTN because its sandbox is self service and needs no commercial agreement.

If you run this, open an issue with what you observed, including anything below that turned out to be wrong.

## What a sandbox run can and cannot prove

Worth being clear before you spend an hour on it.

**It can prove** that the request shape is accepted, that `requesttopay` really does answer `202` with an empty body, that the `X-Reference-Id` you generated is the only handle to the transaction afterwards, and which `reason` strings MTN actually returns when a payment does not succeed. That last one is the most valuable thing here, and the section on reason codes explains why.

**It cannot prove the XOF handling**, which is the headline feature of this package. MTN's sandbox settles in **EUR regardless of market**, so a sandbox run exercises the two-decimal path and never touches the zero-decimal one. The XOF behaviour stays covered by unit tests and unverified against a live provider until somebody runs it in production with a market environment such as `mtnbenin`.

So a successful sandbox run flips the wire contract to verified. It does not flip the currency handling. Do not let the README claim otherwise.

## The short version

Everything except creating the account is scripted:

```bash
node scripts/sandbox-mtn.mjs <your-subscription-key>
```

That provisions the API user and key, fetches a token, prints the `.env` block, then probes every documented test number and prints the reason code table below. It also names any reason string the driver does not currently handle.

The two manual steps are creating the developer account and subscribing to Collections, because neither has an API. The rest of this document explains what the script is doing and why the reason codes matter.

## Getting credentials

1. Create an account at [momodeveloper.mtn.com](https://momodeveloper.mtn.com) and confirm the email.
2. Subscribe to the **Collections** product. Your profile then shows a primary and secondary subscription key. Either works. This is `Ocp-Apim-Subscription-Key` in every call below.
3. Everything else you provision yourself with two API calls.

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

Expect `201` with no body. `providerCallbackHost` is a host, not a URL, so no scheme and no path. Use a [webhook.site](https://webhook.site) host while testing so you can see callbacks arrive.

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

## The test numbers

This is the part that makes the exercise worth doing.

**Any number that is not a test number succeeds immediately**, with no prompt and no delay. So a run that only uses ordinary numbers proves almost nothing: every call returns `SUCCESSFUL` and the interesting branches never execute.

The test numbers are `46733123450` through `46733123454`, and `46733123454` is documented as paying after roughly 30 seconds, which is the one that exercises the pending to success transition and therefore the reconciliation path.

**The mapping from number to failure reason is not published.** MTN's own testing page is behind a JavaScript portal and the community documentation does not list it. So treat this as the experiment: call each number, poll the status, and record the exact `reason` string that comes back.

## The reason codes, and why this matters most

This package makes a claim that nothing has yet checked. MTN reports a customer declining the prompt and a customer never answering it both as `FAILED`, and the driver refines that using the `reason` field:

```php
'PAYER_REJECTION'   => PaymentStatus::Cancelled,
'APPROVAL_REJECTED' => PaymentStatus::Cancelled,
'EXPIRED'           => PaymentStatus::Expired,
'PAYER_DELAYED'     => PaymentStatus::Expired,
```

Those four strings came from the published API contract, not from a live response. If MTN actually returns something else, every transaction that is really a decline gets reported as a plain failure, and the distinction this package sells does not exist in practice.

So the single most valuable output of a sandbox run is a table like this, filled in from what you observed:

| Test MSISDN | HTTP status of poll | `status` | `reason` | Maps to |
| :--- | :--- | :--- | :--- | :--- |
| 46733123450 | | | | |
| 46733123451 | | | | |
| 46733123452 | | | | |
| 46733123453 | | | | |
| 46733123454 | | | | |

Any reason string not already in `REASON_MAP` is a bug. Add it, with a test.

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

Five things to confirm, in this order:

1. **`collect` returns `Pending`, never `Succeeded`.** A `202` means accepted, not paid. If the driver ever reports success straight from `collect`, that is the worst possible bug in a payment package.
2. **Polling with the same reference returns the transaction.** The `X-Reference-Id` is the only handle; there is no other id.
3. **Each test number's `reason`,** recorded in the table above.
4. **`46733123454` moves from pending to a final state** after about half a minute, which is the only way to exercise reconciliation against a real clock.
5. **The same reference sent twice does not create a second charge.** This is the idempotency claim, and it is the one that costs real money if it is wrong.

## When it is done

Update the provider status table in the README, and say exactly what was verified rather than implying more:

> MTN MoMo: wire contract verified against the sandbox on `<date>`. Zero decimal handling still unverified, because the sandbox settles in EUR.

Then open an issue or a pull request with the reason code table, because the next person should not have to rediscover it.

## Wave and Orange

Both need a commercial relationship before you get credentials, so neither is self service. Wave issues API keys through its business onboarding, and Orange Money issues them per market through the local operator. If you have either, the same five checks apply, with one addition for each: Wave signs webhooks with HMAC-SHA256 so verify a real signature rather than a synthetic one, and Orange sends back the `notif_token` it issued at creation, so confirm it matches what was stored and that a mismatched token is rejected.
