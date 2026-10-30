<?php

namespace App\Enum;

/**
 * Finalité d'un code à usage unique (OTP) — colonne `one_time_codes.purpose`.
 *
 * Un code émis pour une finalité ne peut JAMAIS servir pour une autre
 * (ex. : un code de connexion 2FA ne permet pas de réinitialiser un mot de passe).
 */
enum OtpPurpose: string
{
    /** Prouver qu'on possède l'adresse e-mail saisie à l'inscription. */
    case EmailVerification = 'email_verification';

    /** Prouver qu'on possède le numéro de téléphone mobile (code reçu par SMS). */
    case PhoneVerification = 'phone_verification';

    /** Deuxième facteur lors de la connexion (e-mail ou SMS). */
    case TwoFactor = 'two_factor';

    /** Réinitialisation d'un mot de passe oublié (code reçu par e-mail). */
    case PasswordReset = 'password_reset';

    /** Prouver qu'on possède le numéro Mobile Money utilisé pour payer une commande. */
    case PaymentPhone = 'payment_phone';

    /** Invitation d'un nouvel administrateur : jeton long envoyé par e-mail (lien). */
    case AdminInvitation = 'admin_invitation';

    /** Confirmation de suppression de compte pour les comptes sans mot de passe (connexion sociale). */
    case AccountDeletion = 'account_deletion';

    /**
     * Confirmer un réglage sensible (2FA, téléphone) pour un compte SANS mot de passe (connexion Google/Apple) :
     * un code reçu par e-mail remplace la saisie du mot de passe.
     */
    case AccountSecurity = 'account_security';

    /**
     * Changement d'e-mail ou de téléphone, étape 1 : code envoyé à l'ANCIENNE adresse e-mail pour prouver que le titulaire
     * du compte est bien celui qui demande le changement (voir ContactChangeService).
     */
    case ContactChangeOld = 'contact_change_old';

    /** Changement d'e-mail ou de téléphone, étape 2 : code envoyé à la NOUVELLE adresse (ou au nouveau numéro, par SMS). */
    case ContactChangeNew = 'contact_change_new';

    /** Les codes à 6 chiffres sont saisis à la main ; l'invitation est un long jeton dans un lien. */
    public function isLongToken(): bool
    {
        return self::AdminInvitation === $this;
    }
}
