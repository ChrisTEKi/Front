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
    public const ADMIN_BULK_STATUS = 'admin.bulk_status';             // suspension / réactivation de plusieurs administrateurs d'un coup
    public const ADMIN_ROLE_SAVED = 'admin.role.saved';
    public const SUPER_ADMIN_CREATED = 'admin.super_admin.created';
    public const USER_SUSPENDED = 'admin.user.suspended';
    public const USER_REACTIVATED = 'admin.user.reactivated';
    public const SUPPLIER_APPROVED = 'admin.supplier.approved';
    public const SUPPLIER_REJECTED = 'admin.supplier.rejected';
    public const ACCESS_DENIED = 'admin.access.denied';
    public const STEP_UP_FAILED = 'auth.step_up.failed';   // mot de passe de confirmation faux (admin ou client)

    // --- Catalogue ----------------------------------------------------------
    public const PRODUCT_CREATED = 'catalog.product.created';
    public const PRODUCT_UPDATED = 'catalog.product.updated';
    public const PRODUCT_VISIBILITY = 'catalog.product.visibility';   // masqué / réaffiché par son propriétaire
    public const PRODUCT_DELETED = 'catalog.product.deleted';
    public const PRODUCT_REMOVED = 'admin.product.removed';           // retiré de la vente par un administrateur
    public const PRODUCT_RESTORED = 'admin.product.restored';
    public const PRODUCT_FLAGGED = 'admin.product.flagged';
    public const PRODUCT_UNFLAGGED = 'admin.product.unflagged';
    public const PRODUCT_FEATURED = 'admin.product.featured';
    public const PRODUCT_UNFEATURED = 'admin.product.unfeatured';
    public const CATALOG_REMOVED = 'admin.catalog.removed';           // tout le catalogue d'un fournisseur retiré
    public const CATALOG_DELETED = 'admin.catalog.deleted';           // tout le catalogue d'un fournisseur supprimé

    // --- Exports ------------------------------------------------------------
    // ---- Contestations, sanctions, liste noire, suppressions programmées (étape « pleins pouvoirs ») ----
    public const APPEAL_SUBMITTED = 'supplier.appeal.submitted';
    public const APPEAL_ACCEPTED = 'admin.supplier.appeal.accepted';
    public const APPEAL_DISMISSED = 'admin.supplier.appeal.dismissed';
    public const ACCOUNT_BANNED = 'admin.account.banned';              // bannissement définitif + clôture immédiate
    public const ACCOUNT_UNBANNED = 'admin.account.unbanned';          // levée (Super Admin seul)
    public const FUNDS_FROZEN = 'admin.funds.frozen';                  // gel conservatoire des fonds et paiements
    public const FUNDS_UNFROZEN = 'admin.funds.unfrozen';
    public const REFUNDS_ISSUED = 'admin.refunds.issued';              // remboursement des acheteurs lésés
    public const BLACKLIST_ADDED = 'admin.blacklist.added';
    public const BLACKLIST_REMOVED = 'admin.blacklist.removed';
    public const BLACKLIST_HIT = 'security.blacklist.hit';             // une inscription a été refusée par la liste noire
    public const DELETION_SCHEDULED_BY_ADMIN = 'admin.account.deletion_scheduled';
    public const DELETION_CANCELLED_BY_ADMIN = 'admin.account.deletion_cancelled';

    public const EXPORT = 'export.csv';

    // --- Commandes ----------------------------------------------------------
    public const ORDER_PLACED = 'order.placed';
    public const ORDER_STATUS_CHANGED = 'order.status.changed';

    private function __construct()
    {
    }
}
