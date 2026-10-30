<?php

namespace App\Auth;

/**
 * Étapes du parcours de connexion/inscription. Le SERVEUR décide de l'étape suivante (jamais le navigateur) ;
 * chaque valeur correspond à un panneau de l'interface (templates/partials/_auth_form.html.twig, `data-auth-panel`).
 */
enum FlowStep: string
{
    /** Saisir e-mail + mot de passe (ou aucune session de parcours en cours). */
    case Login = 'login';

    /** Saisir le code reçu par e-mail pour prouver la propriété de l'adresse. */
    case VerifyEmail = 'verify_email';

    /** Saisir le code reçu par SMS pour prouver la propriété du numéro. */
    case VerifySms = 'verify_sms';

    /** Saisir le code de double authentification (e-mail ou SMS). */
    case TwoFactor = 'two_factor';

    /** Compte en cours de suppression : l'utilisateur doit choisir de le restaurer ou de partir. */
    case RestoreAccount = 'restore_account';

    /** Toutes les étapes sont franchies : l'utilisateur est connecté, l'interface redirige. */
    case Done = 'done';

    /** Accès refusé (compte suspendu, réseau non autorisé pour un admin…). */
    case Blocked = 'blocked';
}
