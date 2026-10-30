import { goTo, postJson } from './api.js';
import { t } from './i18n.js';
import { busy, errorText, say } from './form-ui.js';
import { showToast } from './ui.js';

/**
 * Page « Mon compte » : navigation entre les écrans et réglages du compte.
 *
 * Chaque formulaire `data-account-form="<nom>"` appelle une route de l'API /compte/api/* (voir AccountApiController) :
 *   password      → mot de passe ;           two_factor → double authentification (e-mail / SMS) ;
 *   phone         → ajout / correction d'un numéro NON vérifié (+ code SMS) ;   phone_verify → validation du code SMS ;
 *   (changement d'e-mail ou de téléphone vérifié : contact.js ; photo : avatar.js ; adresses : addresses.js)
 *   delete        → suppression du compte (gel immédiat, effacement définitif après 30 jours).
 *
 * Sécurité côté navigateur : le jeton CSRF part avec chaque POST (api.js) ; les mots de passe et codes saisis sont vidés dès que
 * le serveur a répondu ; aucune confirmation n'est « devinée » ici : le serveur re-vérifie TOUJOURS le mot de passe (ou le code
 * e-mail) avant une action sensible. Les messages d'erreur viennent traduits du serveur.
 */
const ENDPOINTS = {
    password: '/compte/api/mot-de-passe',
    two_factor: '/compte/api/double-authentification',
    phone: '/compte/api/telephone',
    phone_verify: '/compte/api/telephone/verifier',
    confirm_send: '/compte/api/confirmation/envoyer',
    delete: '/compte/api/suppression',
};

export function initAccount() {
    document.querySelectorAll('[data-account]').forEach(setupNavigation);
    document.querySelectorAll('[data-account-form]').forEach(setupForm);
    document.querySelectorAll('[data-send-code]').forEach(setupSendCode);
}

// ------------------------------------------------------------------------------------------------ Navigation

function setupNavigation(root) {
    const sections = root.querySelectorAll('[data-account-section]');
    const go = (name) => sections.forEach((s) => s.classList.toggle('hidden', s.getAttribute('data-account-section') !== name));
    root.querySelectorAll('[data-account-go]').forEach((btn) => btn.addEventListener('click', () => {
        go(btn.getAttribute('data-account-go'));
        window.scrollTo({ top: 0 });
    }));
    go(root.getAttribute('data-account-initial') || 'main');
}

// ------------------------------------------------------------------------------------------------ Messages

/** Vide les champs secrets (mots de passe, codes) : on ne les garde jamais dans la page. */
function wipeSecrets(form) {
    form.querySelectorAll('input[type="password"], input[name="code"], input[name="current"], input[name="new"]').forEach((i) => { i.value = ''; });
}

// ------------------------------------------------------------------------------------------------ Code de confirmation par e-mail

/** Bouton « Recevoir un code par e-mail » (comptes Google/Apple, sans mot de passe) avec délai avant renvoi. */
function setupSendCode(button) {
    const form = button.closest('form');
    const label = button.textContent;
    let timer = null;

    const cooldown = (seconds) => {
        window.clearInterval(timer);
        let left = seconds;
        const paint = () => {
            if (left <= 0) {
                window.clearInterval(timer);
                button.disabled = false;
                button.textContent = label;

                return;
            }
            button.textContent = t('otp.resend_in', { seconds: left });
            left -= 1;
        };
        button.disabled = true;
        paint();
        timer = window.setInterval(paint, 1000);
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        const result = await postJson(ENDPOINTS.confirm_send, { purpose: button.getAttribute('data-send-code') });
        if (result.ok) {
            say(form, t('account.reauth_code_sent'), true);
            cooldown(Number(result.body?.resendIn) || 30);
        } else {
            say(form, errorText(result), false);
            const wait = Number(result.body?.retryAfter) || Number(result.body?.resendIn) || 0;
            if (wait > 0) cooldown(wait); else button.disabled = false;
        }
    });
}

// ------------------------------------------------------------------------------------------------ Formulaires

function setupForm(form) {
    const kind = form.getAttribute('data-account-form');
    const read = (name) => form.querySelector('[name="' + name + '"]')?.value ?? '';

    // Suppression : le bouton ne s'active qu'une fois le mot demandé recopié (le serveur ne dépend PAS de ce garde-fou).
    if (kind === 'delete') {
        const input = form.querySelector('[data-delete-confirm]');
        const submit = form.querySelector('[data-delete-submit]');
        input.addEventListener('input', () => {
            submit.disabled = input.value.trim().toUpperCase() !== input.getAttribute('data-delete-word').toUpperCase();
        });
    }

    // « Vérifier ce numéro maintenant » : renvoie un code SMS pour le numéro déjà enregistré mais non vérifié.
    form.querySelector('[data-phone-resend]')?.addEventListener('click', async (e) => {
        const button = e.currentTarget;
        button.disabled = true;
        await sendPhone(form, button.getAttribute('data-phone'), {});
        button.disabled = false;
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (kind === 'delete' && form.querySelector('[data-delete-submit]').disabled) {
            say(form, t('account.delete_word_mismatch'), false);

            return;
        }
        busy(form, true);
        try {
            await submit(form, kind, read);
        } finally {
            busy(form, false);
            if (kind === 'delete') form.querySelector('[data-delete-submit]').disabled = true;
            wipeSecrets(form);
        }
    });
}

async function submit(form, kind, read) {
    if (kind === 'password') {
        const result = await postJson(ENDPOINTS.password, { current: read('current'), new: read('new') });
        say(form, result.ok ? result.body.message : errorText(result), result.ok);

        return;
    }

    if (kind === 'two_factor') {
        const email = form.querySelector('[name="email"]')?.checked === true;
        const sms = form.querySelector('[name="sms"]')?.checked === true;
        const result = await postJson(ENDPOINTS.two_factor, { email, sms, password: read('password'), code: read('code') });
        say(form, result.ok ? result.body.message : errorText(result), result.ok);
        if (result.ok) paintTwoFactor(Boolean(result.body.twoFactor?.email || result.body.twoFactor?.sms));

        return;
    }

    if (kind === 'phone') {
        await sendPhone(form, read('phone'), { password: read('password'), code: read('code') });

        return;
    }

    if (kind === 'phone_verify') {
        const result = await postJson(ENDPOINTS.phone_verify, { code: read('code') });
        if (result.ok) {
            say(form, t('account.phone_verified_ok'), true);
            window.setTimeout(() => goTo('/compte?section=info'), 900);
        } else {
            say(form, errorText(result), false);
        }

        return;
    }

    if (kind === 'delete') {
        const result = await postJson(ENDPOINTS.delete, { password: read('password'), code: read('code') });
        if (result.ok) {
            say(form, result.body.message, true);
            // Le serveur a fermé la session : on laisse le temps de lire la date d'effacement, puis retour à l'accueil.
            window.setTimeout(() => goTo(result.body.redirect, '/'), 3500);
        } else {
            say(form, errorText(result), false);
        }
    }
}

/** Envoie le code SMS du nouveau numéro (ou retire le numéro si le champ est vide) puis affiche la saisie du code. */
async function sendPhone(form, phone, reauth) {
    const result = await postJson(ENDPOINTS.phone, { phone, ...reauth });
    if (!result.ok) {
        say(form, errorText(result), false);

        return;
    }
    const verifyForm = document.querySelector('[data-account-form="phone_verify"]');
    if (result.body.state === 'sent' && verifyForm) {
        verifyForm.classList.remove('hidden');
        verifyForm.querySelector('[data-phone-sent]').textContent = t('account.phone_sent', { phone: result.body.maskedTarget || '' });
        verifyForm.querySelector('[name="code"]')?.focus();
        say(form, t('account.phone_sent', { phone: result.body.maskedTarget || '' }), true);
    } else {
        // Numéro retiré, ou déjà vérifié : on recharge pour afficher l'état à jour.
        showToast(phone === '' ? t('account.phone_removed') : t('account.phone_verified_ok'));
        window.setTimeout(() => goTo('/compte?section=info'), 700);
    }
}

/** Met à jour la pastille « 2FA » et le résumé de l'accueil sans recharger la page. */
function paintTwoFactor(enabled) {
    document.querySelectorAll('[data-twofa-chip]').forEach((chip) => {
        chip.classList.toggle('hidden', !enabled);
        chip.classList.toggle('flex', enabled);
    });
    document.querySelectorAll('[data-twofa-summary]').forEach((el) => {
        el.textContent = el.getAttribute(enabled ? 'data-on' : 'data-off');
    });
}
