<?php

declare(strict_types=1);

/*
 * Strings a payer sees.
 *
 * Developer-facing exceptions stay in English on purpose: they are read in
 * stack traces and issue reports. Anything shown to a customer belongs here.
 *
 * French is the working language across most of this footprint, so it is the
 * reference locale rather than a translation of the English.
 */

return [

    'status' => [
        'claimed' => 'Paiement en cours de demarrage. Ne payez pas une seconde fois.',
        'pending' => 'Paiement en attente. Validez la demande sur votre telephone.',
        'succeeded' => 'Paiement effectue.',
        'failed' => 'Le paiement a echoue.',
        'cancelled' => 'Vous avez refuse la demande de paiement.',
        'expired' => 'La demande de paiement a expire.',
        'unknown' => 'Nous verifions le statut de votre paiement.',
    ],

    'failure' => [
        'payer_not_found' => 'Ce numero n\'est pas enregistre pour le mobile money.',
        'not_enough_funds' => 'Solde insuffisant.',
        'payer_limit_reached' => 'Vous avez atteint la limite de votre compte.',
        'payer_rejection' => 'La demande a ete refusee.',
        'expired' => 'La demande n\'a pas ete validee a temps.',
        'payee_not_allowed' => 'Le compte destinataire ne peut pas recevoir ce paiement.',
        'internal_error' => 'Une erreur technique est survenue chez l\'operateur.',
        'generic' => 'Le paiement n\'a pas pu aboutir.',
    ],

    'action' => [
        'retry' => 'Reessayer',
        'check_phone' => 'Consultez votre telephone pour valider.',
        'contact_support' => 'Contactez le support en indiquant la reference :reference.',
    ],

    'redirect' => [
        'continue' => 'Continuer vers :provider',
        'waiting' => 'Nous attendons la confirmation de :provider.',
    ],

];
