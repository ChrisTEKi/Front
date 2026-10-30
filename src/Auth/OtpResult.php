<?php

namespace App\Auth;

/**
 * Issue de la vérification d'un code OTP (voir OtpService::verify()).
 */
enum OtpResult
{
    /** Code correct : il vient d'être consommé, l'action protégée peut avoir lieu. */
    case Valid;

    /** Code faux : une tentative de moins. */
    case Invalid;

    /** Aucun code actif (jamais envoyé, déjà utilisé, remplacé, ou périmé). Il faut en redemander un. */
    case Expired;

    /** Trop de saisies fausses sur ce code : il est définitivement bloqué. Il faut en redemander un. */
    case Locked;
}
