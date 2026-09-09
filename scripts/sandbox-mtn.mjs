#!/usr/bin/env node

/**
 * Provision an MTN MoMo sandbox account and discover its reason codes.
 *
 * The one thing you have to do by hand is create the developer account and
 * subscribe to the Collections product, because neither has an API. Everything
 * after that is here.
 *
 *   node scripts/sandbox-mtn.mjs <subscription-key>
 *
 * What it does, in order:
 *
 *   1. Creates an API user, which in the sandbox means inventing a UUID and
 *      telling MTN that it is now your user id.
 *   2. Generates the API key for it. Shown once, so it is printed.
 *   3. Fetches an access token.
 *   4. Sends a collection request to each documented test number, polls the
 *      result, and prints the status and reason each one produced.
 *
 * Step 4 is the point. src/Providers/MtnMomoProvider.php maps four reason
 * strings to Cancelled and Expired, and those four came from the published API
 * contract rather than from a live response. If MTN returns anything else,
 * every customer decline is currently reported as a plain failure and the
 * distinction this package sells does not exist. This tells you which it is.
 *
 * Nothing here touches production. The sandbox settles in EUR regardless of
 * market, so this verifies the wire contract and never the XOF handling.
 */

import { randomUUID } from 'node:crypto';

const BASE = 'https://sandbox.momodeveloper.mtn.com';
const CALLBACK_HOST = 'webhook.site';

/**
 * The documented sandbox numbers. Any number not on this list succeeds
 * immediately with no prompt, so a run using only ordinary numbers proves
 * almost nothing: every branch that matters stays unexecuted.
 *
 * The mapping from number to reason is not published anywhere I could find,
 * which is exactly why this script exists.
 */
const TEST_NUMBERS = [
  '46733123450',
  '46733123451',
  '46733123452',
  '46733123453',
  '46733123454', // documented as paying after roughly 30 seconds
];

const CONTROL_NUMBER = '22997123456'; // not a test number, so it should succeed

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function die(message, detail) {
  console.error(`\n  ${message}`);
  if (detail) console.error(`  ${detail}`);
  process.exit(1);
}

async function call(path, { method = 'GET', headers = {}, body, key, token, reference } = {}) {
  const h = { 'Ocp-Apim-Subscription-Key': key, ...headers };

  if (token) h.Authorization = `Bearer ${token}`;
  if (reference) h['X-Reference-Id'] = reference;
  if (body !== undefined) h['Content-Type'] = 'application/json';

  const response = await fetch(BASE + path, {
    method,
    headers: h,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const text = await response.text();
  let parsed = null;
  try { parsed = text ? JSON.parse(text) : null; } catch { /* not json, keep text */ }

  return { status: response.status, body: parsed, text };
}

async function main() {
  const key = process.argv[2] || process.env.MTN_MOMO_SUBSCRIPTION_KEY;

  if (!key) {
    die(
      'Usage: node scripts/sandbox-mtn.mjs <subscription-key>',
      'Get the key from your profile at momodeveloper.mtn.com after subscribing to Collections.',
    );
  }

  console.log('\n  MTN MoMo sandbox provisioning\n');

  /* ---------------------------------------------------------- 1. API user */

  const apiUser = randomUUID();

  const created = await call('/v1_0/apiuser', {
    method: 'POST',
    key,
    reference: apiUser,
    body: { providerCallbackHost: CALLBACK_HOST },
  });

  if (created.status !== 201) {
    die(
      `Creating the API user returned ${created.status}, expected 201.`,
      created.status === 401
        ? 'That subscription key was rejected. Check you copied it from the Collections product.'
        : created.text.slice(0, 200),
    );
  }

  console.log(`  API user   ${apiUser}`);

  /* ----------------------------------------------------------- 2. API key */

  const keyed = await call(`/v1_0/apiuser/${apiUser}/apikey`, { method: 'POST', key });

  if (keyed.status !== 201 || !keyed.body?.apiKey) {
    die(`Generating the API key returned ${keyed.status}, expected 201.`, keyed.text.slice(0, 200));
  }

  const apiKey = keyed.body.apiKey;
  console.log(`  API key    ${apiKey}`);

  /* ------------------------------------------------------------- 3. Token */

  const basic = Buffer.from(`${apiUser}:${apiKey}`).toString('base64');

  const tokened = await call('/collection/token/', {
    method: 'POST',
    key,
    headers: { Authorization: `Basic ${basic}`, 'X-Target-Environment': 'sandbox' },
  });

  if (!tokened.body?.access_token) {
    die(`Fetching a token returned ${tokened.status}.`, tokened.text.slice(0, 200));
  }

  const token = tokened.body.access_token;
  console.log('  Token      acquired\n');

  console.log('  Add this to .env:\n');
  console.log('    MOBILE_MONEY_PROVIDER=mtn_momo');
  console.log('    MTN_MOMO_BASE_URL=https://sandbox.momodeveloper.mtn.com');
  console.log('    MTN_MOMO_ENVIRONMENT=sandbox');
  console.log(`    MTN_MOMO_SUBSCRIPTION_KEY=${key}`);
  console.log(`    MTN_MOMO_API_USER=${apiUser}`);
  console.log(`    MTN_MOMO_API_KEY=${apiKey}`);
  console.log('    MTN_MOMO_CURRENCY=EUR   # the sandbox settles in EUR whatever market you target\n');

  /* ------------------------------------------------- 4. Reason discovery */

  console.log('  Probing the test numbers. 46733123454 is documented as taking ~30s.\n');

  const results = [];

  for (const msisdn of [...TEST_NUMBERS, CONTROL_NUMBER]) {
    const reference = randomUUID();

    const requested = await call('/collection/v1_0/requesttopay', {
      method: 'POST',
      key,
      token,
      reference,
      headers: { 'X-Target-Environment': 'sandbox' },
      body: {
        amount: '100',
        currency: 'EUR',
        externalId: reference.slice(0, 12),
        payer: { partyIdType: 'MSISDN', partyId: msisdn },
        payerMessage: 'agent-kit sandbox probe',
        payeeNote: 'agent-kit sandbox probe',
      },
    });

    if (requested.status !== 202) {
      results.push({ msisdn, accepted: requested.status, status: '-', reason: requested.text.slice(0, 60) });
      continue;
    }

    // Give the slow one time to resolve; the others answer immediately.
    await sleep(msisdn === '46733123454' ? 35000 : 1500);

    const polled = await call(`/collection/v1_0/requesttopay/${reference}`, {
      key,
      token,
      headers: { 'X-Target-Environment': 'sandbox' },
    });

    results.push({
      msisdn,
      accepted: requested.status,
      status: polled.body?.status ?? `HTTP ${polled.status}`,
      reason: polled.body?.reason ?? '',
    });

    console.log(`  ${msisdn}  ${polled.body?.status ?? polled.status}${polled.body?.reason ? '  ' + polled.body.reason : ''}`);
  }

  /* -------------------------------------------------------- 5. The table */

  console.log('\n  Paste this into SANDBOX.md and open an issue with it:\n');
  console.log('  | Test MSISDN | POST | status | reason |');
  console.log('  | :--- | ---: | :--- | :--- |');
  for (const r of results) {
    console.log(`  | ${r.msisdn} | ${r.accepted} | ${r.status} | ${r.reason || '(none)'} |`);
  }

  const known = new Set(['PAYER_REJECTION', 'APPROVAL_REJECTED', 'EXPIRED', 'PAYER_DELAYED']);
  const unknown = results.map((r) => r.reason).filter((r) => r && !known.has(r.toUpperCase()));

  console.log('');

  if (unknown.length) {
    console.log('  Reason codes NOT in the driver\'s REASON_MAP:');
    for (const r of [...new Set(unknown)]) console.log(`    ${r}`);
    console.log('\n  Each of those is currently reported as a plain failure. Add them to');
    console.log('  MtnMomoProvider::REASON_MAP with a test, or open an issue.');
  } else {
    console.log('  Every reason returned is already handled by REASON_MAP.');
  }

  console.log('\n  This verified the wire contract. It did not verify XOF handling,');
  console.log('  because the sandbox settles in EUR. See SANDBOX.md.\n');
}

main().catch((error) => die('Unexpected failure.', error.message));
