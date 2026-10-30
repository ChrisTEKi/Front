/**
 * Espace administrateur : onglets, actions (approuver un fournisseur, suspendre un compte, suivre une commande,
 * inviter un administrateur, gérer les profils de droits) et page d'activation d'une invitation.
 *
 * Ce fichier ne décide JAMAIS d'un droit : il envoie des requêtes, et c'est le serveur qui accepte ou refuse
 * (droit fin + jeton CSRF + mot de passe de confirmation pour les actions sensibles). Masquer un bouton ici
 * n'est qu'un confort d'affichage ; Twig ne le dessine d'ailleurs que si l'admin possède le droit.
 *
 * Les textes affichés viennent du serveur (réponses JSON déjà traduites, attributs data-* des gabarits) :
 * aucune phrase n'est écrite en dur dans ce fichier.
 */
import { postJson } from './api.js';

/** Clé de sessionStorage : message à montrer APRÈS le rechargement de la page (succès d'une action). */
const FLASH_KEY = 'gobuy.admin.flash';

export function initAdminDashboard() {
    initAdminInvitation();

    const root = document.querySelector('[data-admin-dashboard]');
    if (!root) return;

    const endpoints = readEndpoints();
    initTabs(root);
    showPendingFlash(root);

    const url = (name, id) => endpoints[name].replace('__ID__', encodeURIComponent(id));
    const failure = (root, result) => explain(root, result);

    // ------------------------------------------------------------ Fournisseurs
    const reasonDialog = document.querySelector('[data-dialog="reason"]');
    let reasonContext = null;
    if (reasonDialog) {
        bindDialog(reasonDialog, root, async (form) => postJson(url('supplierDecision', reasonContext.id), {
            action: reasonContext.decision,
            reason: form.elements.reason.value,
        }));
    }

    root.querySelectorAll('[data-supplier-decision]').forEach((btn) => btn.addEventListener('click', async () => {
        const decision = btn.getAttribute('data-supplier-decision');
        const id = btn.getAttribute('data-id');
        if ('approve' === decision) {
            btn.disabled = true;
            const result = await postJson(url('supplierDecision', id), { action: 'approve' });
            btn.disabled = false;
            result.ok ? done(root) : failure(root, result);

            return;
        }
        // Refus / retrait d'approbation : un motif écrit est obligatoire (il est montré au fournisseur).
        reasonContext = { id, decision };
        reasonDialog?.showModal();
    }));

    // ------------------------------------------------------------ Clients / fournisseurs : suspension
    root.querySelectorAll('[data-user-status]').forEach((btn) => btn.addEventListener('click', async () => {
        const action = btn.getAttribute('data-user-status');
        const question = btn.getAttribute('data-confirm');
        if (question && !window.confirm(question)) return;
        btn.disabled = true;
        const result = await postJson(url('userStatus', btn.getAttribute('data-id')), { action });
        btn.disabled = false;
        result.ok ? done(root) : failure(root, result);
    }));

    // ------------------------------------------------------------ Commandes
    const orderDialog = document.querySelector('[data-dialog="order"]');
    if (orderDialog) {
        let order = null;
        bindDialog(orderDialog, root, async (form) => {
            const payload = {};
            if (form.elements.status.value !== order.status) payload.status = form.elements.status.value;
            if (form.elements.description.value.trim() !== '') payload.description = form.elements.description.value;
            if (form.elements.courierName.value.trim() !== '') {
                payload.courierName = form.elements.courierName.value;
                payload.courierPhone = form.elements.courierPhone.value;
            }
            // Rien à enregistrer : on ferme simplement la fenêtre.
            if (!payload.status && !payload.courierName) return { ok: true, status: 200, body: { ok: true }, noop: true };

            return postJson(url('orderStatus', order.number), payload);
        });
        root.querySelectorAll('[data-order-edit]').forEach((btn) => btn.addEventListener('click', () => {
            order = { number: btn.getAttribute('data-number'), status: btn.getAttribute('data-status') };
            const form = orderDialog.querySelector('form');
            orderDialog.querySelector('[data-order-number]').textContent = order.number;
            form.elements.status.value = order.status;
            form.elements.courierName.value = btn.getAttribute('data-courier') || '';
            orderDialog.showModal();
        }));
    }

    // ------------------------------------------------------------ Administrateurs (Super Admin)
    initAdminManagement(root, url);
}

/* ====================================================================== Administrateurs et profils */
function initAdminManagement(root, url) {
    const adminDialog = document.querySelector('[data-dialog="admin"]');
    const confirmDialog = document.querySelector('[data-dialog="confirm"]');
    const roleDialog = document.querySelector('[data-dialog="role"]');
    if (!adminDialog || !confirmDialog || !roleDialog) return; // pas le droit « admins.manage » : rien à brancher

    // --- Inviter / modifier les accès d'un administrateur ---
    let adminTarget = null; // null = invitation ; {id, name} = modification
    bindDialog(adminDialog, root, async (form) => {
        const common = {
            roleId: form.elements.roleId.value,
            extra: checked(form, 'extra'),
            denied: checked(form, 'denied'),
            password: form.elements.password.value,
        };
        if (null === adminTarget) {
            return postJson(url('adminInvite', ''), { ...common, email: form.elements.email.value, fullName: form.elements.fullName.value, locale: form.elements.locale.value });
        }

        return postJson(url('adminAccess', adminTarget.id), common);
    });

    root.querySelector('[data-admin-invite-open]')?.addEventListener('click', () => {
        adminTarget = null;
        adminDialog.querySelector('[data-admin-invite-fields]').classList.remove('hidden');
        adminDialog.querySelector('[data-admin-dialog-title]').textContent = adminDialog.getAttribute('data-title-invite');
        adminDialog.querySelector('form').elements.email.required = true;
        adminDialog.querySelector('form').elements.fullName.required = true;
        adminDialog.showModal();
    });

    root.querySelectorAll('[data-admin-edit]').forEach((btn) => btn.addEventListener('click', () => {
        adminTarget = { id: btn.getAttribute('data-id') };
        const form = adminDialog.querySelector('form');
        adminDialog.querySelector('[data-admin-invite-fields]').classList.add('hidden');
        form.elements.email.required = false;
        form.elements.fullName.required = false;
        adminDialog.querySelector('[data-admin-dialog-title]').textContent = adminDialog.getAttribute('data-title-edit').replace('__NAME__', btn.getAttribute('data-name'));
        form.elements.roleId.value = btn.getAttribute('data-role-id');
        const extra = parseList(btn.getAttribute('data-extra'));
        const denied = parseList(btn.getAttribute('data-denied'));
        form.querySelectorAll('input[name="extra"]').forEach((c) => { c.checked = extra.includes(c.value); });
        form.querySelectorAll('input[name="denied"]').forEach((c) => { c.checked = denied.includes(c.value); });
        adminDialog.showModal();
    }));

    // --- Actions qui ne demandent QUE le mot de passe : suspendre, réactiver, renvoyer l'invitation, supprimer un profil ---
    const titles = JSON.parse(confirmDialog.getAttribute('data-titles') || '{}');
    let pending = null; // {endpoint, title, payload}
    bindDialog(confirmDialog, root, async (form) => postJson(pending.endpoint, { ...pending.payload, password: form.elements.password.value }));
    const askPassword = (kind, endpoint, payload = {}, name = '') => {
        pending = { endpoint, payload };
        confirmDialog.querySelector('[data-dialog-title]').textContent = (titles[kind] || '').replace('__NAME__', name);
        confirmDialog.showModal();
    };

    root.querySelectorAll('[data-admin-confirm]').forEach((btn) => btn.addEventListener('click', () => {
        const kind = btn.getAttribute('data-admin-confirm');
        const id = btn.getAttribute('data-id');
        if ('resend' === kind) askPassword(kind, url('adminResend', id));
        else if ('reset_access' === kind) askPassword(kind, url('adminResetAccess', id));
        else askPassword(kind, url('adminStatus', id), { action: kind });
    }));

    // --- Profils de droits ---
    bindDialog(roleDialog, root, async (form) => postJson(url('roleSave', ''), {
        id: form.elements.id.value,
        code: form.elements.code.value,
        label: form.elements.label.value,
        description: form.elements.description.value,
        permissions: checked(form, 'permissions'),
        password: form.elements.password.value,
    }));

    root.querySelectorAll('[data-role-edit]').forEach((btn) => btn.addEventListener('click', () => {
        const form = roleDialog.querySelector('form');
        const raw = btn.getAttribute('data-role');
        const role = raw ? JSON.parse(raw) : null;
        roleDialog.querySelector('[data-role-dialog-title]').textContent = role
            ? roleDialog.getAttribute('data-title-edit').replace('__NAME__', role.label)
            : roleDialog.getAttribute('data-title-new');
        form.elements.id.value = role?.id ?? '';
        form.elements.label.value = role?.label ?? '';
        form.elements.code.value = role?.code ?? '';
        form.elements.code.readOnly = Boolean(role?.isSystem); // le code d'un profil fourni d'origine ne change pas
        form.elements.description.value = role?.description ?? '';
        form.querySelectorAll('input[name="permissions"]').forEach((c) => { c.checked = (role?.permissions ?? []).includes(c.value); });
        roleDialog.showModal();
    }));

    root.querySelectorAll('[data-role-delete]').forEach((btn) => btn.addEventListener('click', () => {
        askPassword('role_delete', url('roleDelete', btn.getAttribute('data-id')), {}, btn.getAttribute('data-name'));
    }));
}

/* ====================================================================== Activation d'une invitation */
function initAdminInvitation() {
    const form = document.querySelector('[data-admin-invitation]');
    if (!form) return;
    const error = form.querySelector('[data-dialog-error]');
    const button = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        error.classList.add('hidden');
        button.disabled = true;
        const result = await postJson(form.getAttribute('action'), {
            password: form.elements.password.value,
            passwordConfirm: form.elements.passwordConfirm.value,
        });
        if (result.ok) {
            // Le mot de passe ne reste pas dans la page ; on envoie l'administrateur se connecter.
            form.elements.password.value = '';
            form.elements.passwordConfirm.value = '';
            window.location.assign(result.body.redirect);

            return;
        }
        button.disabled = false;
        error.textContent = messageOf(result, form.getAttribute('data-network-error'));
        error.classList.remove('hidden');
    });
}

/* ====================================================================== Outils */

/** Onglets : l'onglet actif est mémorisé dans l'URL (?tab=...), ce qui permet de recharger la page sans le perdre. */
function initTabs(root) {
    const tabs = root.querySelectorAll('[data-admin-tab]');
    const panels = root.querySelectorAll('[data-admin-panel]');
    const available = new Set(Array.from(panels).map((p) => p.getAttribute('data-admin-panel')));

    const switchTo = (id, remember = true) => {
        if (!available.has(id)) id = 'overview';
        panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-admin-panel') !== id));
        tabs.forEach((t) => {
            // Le bouton « Export Tableaux CSV » de l'en-tête et la barre d'onglets partagent l'attribut : seule la barre se colore.
            if (!t.closest('[data-admin-tabs]')) return;
            const active = t.getAttribute('data-admin-tab') === id;
            const emerald = ['vendor_applications', 'vendor_payouts', 'exports'].includes(id);
            t.classList.toggle('bg-indigo-600', active && !emerald);
            t.classList.toggle('bg-emerald-600', active && emerald);
            t.classList.toggle('text-white', active);
            t.classList.toggle('text-slate-600', !active);
            t.setAttribute('aria-current', active ? 'page' : 'false');
        });
        if (remember) {
            try {
                const next = new URL(window.location.href);
                next.searchParams.set('tab', id);
                window.history.replaceState(null, '', next);
            } catch { /* l'historique est un confort, jamais une obligation */ }
        }
    };

    tabs.forEach((t) => t.addEventListener('click', () => switchTo(t.getAttribute('data-admin-tab'))));
    switchTo(root.getAttribute('data-initial-tab') || 'overview', false);
}

/** Branche une fenêtre <dialog> : envoi, gestion d'erreur, fermeture, nettoyage des mots de passe. */
function bindDialog(dialog, root, send) {
    const form = dialog.querySelector('[data-dialog-form]');
    const error = dialog.querySelector('[data-dialog-error]');

    dialog.querySelectorAll('[data-dialog-cancel]').forEach((b) => b.addEventListener('click', () => dialog.close()));
    // Au retour sur la fenêtre, on repart de zéro : aucun mot de passe saisi ne reste dans le formulaire.
    dialog.addEventListener('close', () => { form.reset(); error.classList.add('hidden'); });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        error.classList.add('hidden');
        submit.disabled = true;
        const result = await send(form);
        submit.disabled = false;

        if (result.ok) {
            dialog.close();
            if (!result.noop) done(root);

            return;
        }
        if (result.expired) { sessionExpired(root); return; }
        // Erreur de mot de passe de confirmation : on vide le champ pour une nouvelle saisie.
        form.querySelectorAll('input[type="password"]').forEach((i) => { i.value = ''; });
        error.textContent = messageOf(result, root.getAttribute('data-network-error'));
        error.classList.remove('hidden');
    });
}

/** Une action a réussi : on mémorise le message puis on recharge pour afficher les données à jour. */
function done(root) {
    try { window.sessionStorage.setItem(FLASH_KEY, root.getAttribute('data-saved') || ''); } catch { /* facultatif */ }
    window.location.reload();
}

function showPendingFlash(root) {
    let message = null;
    try {
        message = window.sessionStorage.getItem(FLASH_KEY);
        window.sessionStorage.removeItem(FLASH_KEY);
    } catch { /* facultatif */ }
    if (message) notice(root, 'success', message);
}

function explain(root, result) {
    if (result.expired) { sessionExpired(root); return; }
    notice(root, 'error', messageOf(result, root.getAttribute('data-network-error')));
}

function sessionExpired(root) {
    notice(root, 'error', root.getAttribute('data-session-expired'));
    window.setTimeout(() => window.location.reload(), 1500); // le serveur redirige alors vers la connexion
}

function messageOf(result, networkMessage) {
    if (result.network) return networkMessage || '';

    return result.body?.message || networkMessage || '';
}

function notice(root, kind, message) {
    const box = root.querySelector('[data-admin-notice]');
    if (!box) return;
    box.textContent = message;
    const styles = {
        success: ['border-emerald-200', 'bg-emerald-50', 'text-emerald-800'],
        error: ['border-rose-200', 'bg-rose-50', 'text-rose-800'],
    };
    Object.values(styles).flat().forEach((c) => box.classList.remove(c));
    styles[kind].forEach((c) => box.classList.add(c));
    box.classList.remove('hidden');
    box.classList.add('flex');
    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    window.setTimeout(() => { box.classList.add('hidden'); box.classList.remove('flex'); }, 6000);
}

function readEndpoints() {
    try {
        return JSON.parse(document.getElementById('admin-endpoints')?.textContent || '{}');
    } catch {
        return {};
    }
}

function checked(form, name) {
    return Array.from(form.querySelectorAll('input[name="' + name + '"]:checked')).map((c) => c.value);
}

function parseList(raw) {
    try {
        const list = JSON.parse(raw || '[]');

        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}
