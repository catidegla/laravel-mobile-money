<?php

declare(strict_types=1);

/*
 * Keys and placeholders must match lang/fr exactly. A placeholder translated
 * along with the sentence never gets substituted, and the payer sees a literal
 * ":reference" on the page.
 */

return [

    'status' => [
        'claimed' => 'Payment starting. Do not pay again until this resolves.',
        'pending' => 'Payment pending. Approve the request on your phone.',
        'succeeded' => 'Payment complete.',
        'failed' => 'The payment failed.',
        'cancelled' => 'You declined the payment request.',
        'expired' => 'The payment request expired.',
        'unknown' => 'We are checking the status of your payment.',
    ],

    'failure' => [
        'payer_not_found' => 'This number is not registered for mobile money.',
        'not_enough_funds' => 'Not enough balance.',
        'payer_limit_reached' => 'You have reached your account limit.',
        'payer_rejection' => 'The request was declined.',
        'expired' => 'The request was not approved in time.',
        'payee_not_allowed' => 'The receiving account cannot accept this payment.',
        'internal_error' => 'The operator reported a technical error.',
        'generic' => 'The payment could not be completed.',
    ],

    'action' => [
        'retry' => 'Try again',
        'check_phone' => 'Check your phone to approve.',
        'contact_support' => 'Contact support quoting reference :reference.',
    ],

    'redirect' => [
        'continue' => 'Continue to :provider',
        'waiting' => 'Waiting for confirmation from :provider.',
    ],

];
