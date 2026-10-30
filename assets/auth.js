import { getJson, goTo, postJson } from './api.js';
import { t } from './i18n.js';

/**
 * Connexion / inscription — écran piloté par le SERVEUR.
 *
 * Le navigateur n'invente jamais l'étape suivante : chaque réponse de l'API /auth/* contient `step` (login, verify_email,
 * verify_sms, two_factor, restore_account, done, blocked) et ce script se contente d'afficher le panneau correspondant
 * (`data-auth-panel`). Les textes d'erreur viennent traduits du serveur ; les autres textes de la page (Twig, domaine
 * « messages ») ou du dictionnaire JavaScript (domaine « js », voir i18n.js).
 *
 * Sécurité côté navigateur : jeton CSRF envoyé à chaque POST (api.js), aucun mot de passe ni code conservé après l'envoi,
 * redirection limitée aux chemins du site (goTo).
 */

/** Panneaux qui appartiennent à un parcours déjà démarré côté serveur : « retour » l'abandonne. */
const FLOW_PANELS = ['verify_email', 'verify_sms', 'two_factor', 'restore_account'];

export function initAuth() {
    document.querySelectorAll('[data-auth]').forEach(setup);
}

function setup(root) {
    const ep = JSON.parse(root.getAttribute('data-auth-endpoints') || '{}');
    const panels = root.querySelectorAll('[data-auth-panel]');
    const title = root.querySelector('[data-auth-title]');
    const backButton = root.querySelector('[data-auth-back]');
    const errorBox = root.querySelector('[data-auth-error]');
    const successBox = root.querySelector('[data-auth-success]');
    const trail = []; // panneaux visités (pour « retour »)
    let current = 'login';
    let resendTimer = null;

    // ------------------------------------------------------------------ Messages

    const showError = (message) => {
        successBox.classList.add('hidden');
        successBox.classList.remove('flex');
        errorBox.querySelector('[data-auth-error-text]').textContent = message;
        errorBox.classList.remove('hidden');
        errorBox.classList.add('flex');
    };
    const showSuccess = (message) => {
        errorBox.classList.add('hidden');
        errorBox.classList.remove('flex');
        successBox.querySelector('[data-auth-success-text]').textContent = message;
        successBox.classList.remove('hidden');
        successBox.classList.add('flex');
    };
    const clearMessages = () => {
        [errorBox, successBox].forEach((box) => { box.classList.add('hidden'); box.classList.remove('flex'); });
        root.querySelectorAll('[data-field-error]').forEach((p) => { p.classList.add('hidden'); p.textContent = ''; });
    };

    /** Erreurs de validation du serveur : { champ: [messages], _: [messages généraux] }. */
    const showFieldErrors = (form, errors) => {
        let general = '';
        Object.entries(errors || {}).forEach(([field, messages]) => {
            const target = form?.querySelector('[data-field-error="' + field + '"]');
            const text = Array.isArray(messages) ? messages.join(' ') : String(messages);
            if (target) { target.textContent = text; target.classList.remove('hidden'); } else { general += (general ? ' ' : '') + text; }
        });
        if (general) showError(general);
        // Un champ en erreur dans un bloc replié (adresse facultative de l'inscription) : on déplie le bloc pour que l'erreur se voie.
        form?.querySelectorAll('details').forEach((block) => { if (block.querySelector('[data-field-error]:not(.hidden)')) block.open = true; });
    };

    // ------------------------------------------------------------------ Panneaux

    const show = (name, { push = true, keepMessages = false } = {}) => {
        if (!root.querySelector('[data-auth-panel="' + name + '"]')) name = 'login';
        panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-auth-panel') !== name));
        const active = root.querySelector('[data-auth-panel="' + name + '"]');
        if (title && active) title.textContent = active.getAttribute('data-title') || 'GoBuy';
        if (!keepMessages) clearMessages();
        if (push && current !== name) trail.push(current);
        current = name;
        backButton?.classList.toggle('hidden', name === 'login');
        window.clearInterval(resendTimer);
        window.setTimeout(() => active?.querySelector('input:not([type="hidden"]), button')?.focus?.({ preventScroll: true }), 50);
    };

    const setBusy = (button, busy) => { if (button) { button.disabled = busy; button.setAttribute('aria-busy', busy ? 'true' : 'false'); } };

    // ------------------------------------------------------------------ Réponses du serveur

    /** Affiche le résultat d'une action : étape suivante, redirection ou erreur. Retourne true si l'étape a changé. */
    const handle = (result, { form = null } = {}) => {
        if (result.network) { showError(t('net.error')); return false; }
        const body = result.body;
        if (result.expired || body === null) { show('login'); showError(t('session.expired')); return false; }

        if (body.step === 'done' && !body.error) {
            goTo(body.redirect, '/');
            return true;
        }
        if (body.errors && form) { showFieldErrors(form, body.errors); if (body.message && !Object.keys(body.errors).length) showError(body.message); return false; }

        const panel = { verify_email: 'verify_email', verify_sms: 'verify_sms', two_factor: 'two_factor', restore_account: 'restore_account' }[body.step];
        if (panel) {
            const changed = current !== panel;
            if (changed) show(panel, { keepMessages: false });
            fillStep(panel, body);
            if (body.error) showError(body.message || t('net.error'));
            return changed;
        }
        // Retour à l'étape « connexion » (session expirée, parcours abandonné, accès bloqué…).
        if (body.step === 'login' || body.step === 'blocked') {
            if (FLOW_PANELS.includes(current)) show('login');
        }
        if (body.error) showError(body.message || t('net.error'));
        return false;
    };

    /** Remplit un panneau de vérification : destinataire masqué, compte à rebours, choix du canal 2FA, bouton « Plus tard ». */
    const fillStep = (panel, body) => {
        const el = root.querySelector('[data-auth-panel="' + panel + '"]');
        if (!el) return;
        el.querySelectorAll('[data-auth-target]').forEach((n) => { n.textContent = body.maskedTarget || ''; });
        el.querySelector('[data-auth-skip]')?.classList.toggle('hidden', !body.skippable);
        const channels = el.querySelector('[data-auth-channels]');
        if (channels) {
            const list = body.channels || [];
            channels.classList.toggle('hidden', list.length < 2);
            channels.querySelectorAll('[data-auth-channel]').forEach((b) => b.classList.toggle('hidden', !list.includes(b.getAttribute('data-auth-channel')) || b.getAttribute('data-auth-channel') === body.channel));
        }
        startCountdown(el, Math.max(Number(body.resendIn) || 0, Number(body.retryAfter) || 0));
        const otp = el.querySelector('[data-auth-otp]');
        if (otp && body.error !== 'too_soon') { otp.value = ''; }
    };

    const startCountdown = (el, seconds) => {
        const button = el.querySelector('[data-auth-resend]');
        window.clearInterval(resendTimer);
        if (!button) return;
        let left = seconds;
        const render = () => {
            button.disabled = left > 0;
            button.textContent = left > 0 ? t('otp.resend_in', { seconds: left }) : t('otp.resend');
        };
        render();
        if (left > 0) {
            resendTimer = window.setInterval(() => { left -= 1; render(); if (left <= 0) window.clearInterval(resendTimer); }, 1000);
        }
    };

    // ------------------------------------------------------------------ Navigation

    root.querySelectorAll('[data-auth-switch]').forEach((btn) => btn.addEventListener('click', () => show(btn.getAttribute('data-auth-switch'))));
    window.addEventListener('gb:auth-switch', (e) => { if (root.closest('[data-modal]')) show(e.detail); });

    backButton?.addEventListener('click', async () => {
        if (FLOW_PANELS.includes(current)) {
            await postJson(ep.cancel); // abandonne le parcours côté serveur
            trail.length = 0;
            show('login', { push: false });
            return;
        }
        const previous = trail.pop() || 'login';
        show(previous, { push: false });
    });

    // ------------------------------------------------------------------ Formulaires

    root.querySelectorAll('[data-auth-form]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const kind = form.getAttribute('data-auth-form');
            const data = Object.fromEntries(new FormData(form).entries()); // les mots de passe ne sont jamais « trim » côté serveur
            const submit = form.querySelector('[type="submit"]');
            clearMessages();
            setBusy(submit, true);

            try {
                if (kind === 'login') {
                    handle(await postJson(ep.login, { email: data.email, password: data.password }), { form });
                } else if (kind === 'register_client' || kind === 'register_supplier') {
                    const result = await postJson(kind === 'register_client' ? ep.registerClient : ep.registerSupplier, data);
                    handle(result, { form });
                    form.querySelectorAll('input[type="password"]').forEach((i) => { i.value = ''; });
                } else if (kind === 'forgot') {
                    const result = await postJson(ep.forgot, { email: data.email });
                    if (result.ok) {
                        root.querySelector('[data-auth-form="reset"] input[name="email"]').value = data.email;
                        show('reset_password');
                        showSuccess(result.body.message);
                    } else if (result.network) { showError(t('net.error')); } else { showError(result.body?.message || t('session.expired')); }
                } else if (kind === 'reset') {
                    const result = await postJson(ep.reset, { email: data.email, code: data.code, password: data.password });
                    if (result.ok) {
                        form.reset();
                        show('login');
                        showSuccess(result.body.message);
                    } else if (result.network) { showError(t('net.error')); } else if (result.body?.errors) { showFieldErrors(form, result.body.errors); showError(result.body.message); } else { showError(result.body?.message || t('session.expired')); }
                }
            } finally {
                setBusy(submit, false);
            }
        });
    });

    // ------------------------------------------------------------------ Codes (e-mail, SMS, 2FA)

    root.querySelectorAll('[data-auth-otp]').forEach((input) => {
        input.addEventListener('input', () => { input.value = input.value.replace(/\D/g, '').slice(0, 6); });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); input.closest('[data-auth-panel]')?.querySelector('[data-auth-verify]')?.click(); } });
    });

    root.querySelectorAll('[data-auth-verify]').forEach((btn) => btn.addEventListener('click', async () => {
        const input = btn.closest('[data-auth-panel]').querySelector('[data-auth-otp]');
        clearMessages();
        if (input.value.length !== 6) { showError(t('otp.enter_code')); input.focus(); return; }
        setBusy(btn, true);
        try {
            const result = await postJson(ep.verify, { code: input.value });
            handle(result);
            if (result.body && result.body.step === current) input.value = '';
        } finally { setBusy(btn, false); }
    }));

    root.querySelectorAll('[data-auth-resend]').forEach((btn) => btn.addEventListener('click', async () => {
        clearMessages();
        setBusy(btn, true);
        try {
            const result = await postJson(ep.resend);
            handle(result);
            if (result.ok) showSuccess(t('otp.sent'));
        } finally { setBusy(btn, false); }
    }));

    root.querySelectorAll('[data-auth-channel]').forEach((btn) => btn.addEventListener('click', async () => {
        clearMessages();
        const result = await postJson(ep.resend, { channel: btn.getAttribute('data-auth-channel') });
        handle(result);
        if (result.ok) showSuccess(t('otp.sent'));
    }));

    root.querySelector('[data-auth-skip]')?.addEventListener('click', async () => handle(await postJson(ep.skip)));
    root.querySelector('[data-auth-restore]')?.addEventListener('click', async (e) => {
        setBusy(e.currentTarget, true);
        try { handle(await postJson(ep.restore)); } finally { setBusy(e.currentTarget, false); }
    });
    root.querySelector('[data-auth-restore-decline]')?.addEventListener('click', async () => {
        await postJson(ep.cancel);
        show('login', { push: false });
    });

    // ------------------------------------------------------------------ Reprise d'un parcours en cours

    /** Après un rechargement ou un retour de Google/Apple : le serveur dit où l'on en est (sans renvoyer de code). */
    const resume = async () => {
        const result = await getJson(ep.state);
        if (result.body && result.body.step && result.body.step !== 'login') handle(result);
    };

    window.addEventListener('gb:auth-opened', () => { if (root.closest('[data-modal]')) resume(); });

    show(root.getAttribute('data-auth-initial') || 'login', { push: false });
    const flash = root.getAttribute('data-auth-flash-error');
    if (flash) showError(flash);
    if (root.hasAttribute('data-auth-resume') || (!root.closest('[data-modal]') && !flash)) resume();
}
