<?php

namespace App\Audit;

/**
 * Catalogue des actions inscrites au journal d'audit (colonne `audit_logs.action`).
 *
 * Convention : « domaine.sujet.résultat ». Des constantes (plutôt que des chaînes libres) évitent les fautes
 * de frappe et permettent de filtrer le journal par préfixe (« auth. », « admin. », « export. »…).
 */
final class AuditAction
{
    // --- Authentification ---------------------------------------------------
    public const LOGIN_SUCCESS = 'auth.login.success';
    public const LOGIN_FAILED = 'auth.login.failed';
    public const LOGIN_BLOCKED = 'auth.login.blocked';        // limite de débit atteinte / compte suspendu / IP refusée
    public const LOGOUT = 'auth.logout';
    public const REGISTER = 'auth.register';
    public const EMAIL_VERIFIED = 'auth.email.verified';
    public const PHONE_VERIFIED = 'auth.phone.verified';
    public const TWO_FACTOR_FAILED = 'auth.2fa.failed';
    public const TWO_FACTOR_CHANGED = 'auth.2fa.changed';
    public const PASSWORD_RESET_REQUESTED = 'auth.password.reset_requested';
    public const PASSWORD_RESET_DONE = 'auth.password.reset_done';
    public const PASSWORD_CHANGED = 'auth.password.changed';
    public const SOCIAL_LOGIN = 'auth.social.login';
    public const SOCIAL_REGISTER = 'auth.social.register';

    // --- Compte client ------------------------------------------------------
    public const ACCOUNT_DELETION_REQUESTED = 'account.deletion.requested';
    public const ACCOUNT_RESTORED = 'account.deletion.cancelled';
    public const ACCOUNT_PURGED = 'account.purged';
    public const PHONE_CHANGED = 'account.phone.changed';
    public const EMAIL_CHANGED = 'account.email.changed';
    public const AVATAR_CHANGED = 'account.avatar.changed';
    public const MOBILE_MONEY_VERIFIED = 'payment.mobile_money.verified';

    // --- Administration -----------------------------------------------------
    public const ADMIN_INVITED = 'admin.invited';
    public const ADMIN_ACTIVATED = 'admin.activated';
    public const ADMIN_ACCESS_RESET = 'admin.access_reset';
    public const ADMIN_UPDATED = 'admin.updated';
    public const ADMIN_SUSPENDED = 'admin.suspended';
    public const ADMIN_REACTIVATED = 'admin.reactivated';
    public const ADMIN_ROLE_SAVED = 'admin.role.saved';
    public const SUPER_ADMIN_CREATED = 'admin.super_admin.created';
    public const USER_SUSPENDED = 'admin.user.suspended';
    public const USER_REACTIVATED = 'admin.user.reactivated';
    public const SUPPLIER_APPROVED = 'admin.supplier.approved';
    public const SUPPLIER_REJECTED = 'admin.supplier.rejected';
    public const ACCESS_DENIED = 'admin.access.denied';
    public const STEP_UP_FAILED = 'auth.step_up.failed';   // mot de passe de confirmation faux (admin ou client)

    // --- Exports ------------------------------------------------------------
    public const EXPORT = 'export.csv';

    // --- Commandes ----------------------------------------------------------
    public const ORDER_PLACED = 'order.placed';
    public const ORDER_STATUS_CHANGED = 'order.status.changed';

    private function __construct()
    {
    }
}
