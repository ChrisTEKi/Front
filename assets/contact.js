import { goTo, postJson } from './api.js';
import { busy, errorText, say } from './form-ui.js';
import { t } from './i18n.js';
import { showToast } from './ui.js';

/**
 * Changer son adresse e-mail ou son numéro de téléphone, avec DOUBLE confirmation (page « Mon compte › Mes informations »).
 *
 *   étape 1  « request » : on saisit la nouvelle valeur  → le serveur envoie un code à l'ANCIENNE adresse e-mail ;
 *   étape 2  « code »    : on saisit ce code            → le serveur envoie un second code à la NOUVELLE adresse (ou un SMS
 *                                                          au nouveau numéro) ;
 *   étape 3  « code »    : on saisit le second code     → seulement maintenant, le serveur modifie le compte.
 *
 * Retirer son numéro n'a pas de nouvelle destination : le code de l'ancienne adresse suffit.
 *
 * Rien n'est décidé ici : l'état de la demande vit dans la session du serveur (ContactChangeService), qui re-vérifie tout.
 * Les routes : /compte/api/contact/{demarrer,confirmer,renvoyer,annuler}.
 */
const EP = {
    start: '/compte/api/contact/demarrer',
    confirm: '/compte/api/contact/confirmer',
    resend: '/compte/api/contact/renvoyer',
    cancel: '/compte/api/contact/annuler',
};

export function initContactChange() {
    const form = document.querySelector('[data-contact]');
    if (!form) return;

    const field = (name) => form.querySelector('[name="' + name + '"]');
    const text = (selector, value) => { const el = form.querySelector(selector); if (el) el.textContent = value; };
    const resendButton = form.querySelector('[data-contact-resend]');
    const resendLabel = resendButton?.textContent ?? '';
    let kind = null;      // « email », « phone » ou « phone_remove »
    let stage = 'request';
    let timer = null;
    let finished = false; // changement appliqué : on ne réactive plus les boutons avant le rechargement de la page

    // ------------------------------------------------------------------ Affichage

    const showStage = (name) => {
        stage = name;
        form.querySelectorAll('[data-contact-stage]').forEach((el) => {
            el.classList.toggle('hidden', el.getAttribute('data-contact-stage') !== name);
        });
        text('[data-contact-submit]', name === 'request' ? t('contact.send_old') : t('contact.confirm'));
    };

    const cooldown = (seconds) => {
        window.clearInterval(timer);
        if (!resendButton) return;
        let left = Math.max(0, Number(seconds) || 0);
        const paint = () => {
            if (left <= 0) {
                window.clearInterval(timer);
                resendButton.disabled = false;
                resendButton.textContent = resendLabel;

                return;
            }
            resendButton.textContent = t('otp.resend_in', { seconds: left });
            left -= 1;
        };
        resendButton.disabled = left > 0;
        paint();
        if (left > 0) timer = window.setInterval(paint, 1000);
    };

    const reset = () => {
        window.clearInterval(timer);
        form.reset();
        form.querySelector('[data-form-message]')?.classList.add('hidden');
        showStage('request');
    };

    /** Ouvre le panneau pour « email », « phone » (remplacer) ou « phone_remove » (retirer). */
    const open = (which) => {
        reset();
        kind = which;
        const input = field('value');
        const removing = which === 'phone_remove';
        text('[data-contact-title]', t('contact.title.' + which));
        text('[data-contact-intro]', t('contact.intro.' + which));
        text('[data-contact-label]', t('contact.label.' + (which === 'email' ? 'email' : 'phone')));
        form.querySelector('[data-contact-value-wrap]')?.classList.toggle('hidden', removing);
        if (input) {
            input.type = which === 'email' ? 'email' : 'tel';
            input.setAttribute('autocomplete', which === 'email' ? 'email' : 'tel');
            input.placeholder = t('contact.placeholder.' + (which === 'email' ? 'email' : 'phone'));
            input.classList.toggle('font-mono', which !== 'email');
        }
        form.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        (removing ? form.querySelector('[data-contact-submit]') : input)?.focus();
    };

    /** Un code vient de partir : on passe à la saisie (step = « old » : ancienne adresse ; « new » : nouvelle destination). */
    const enterCode = (body) => {
        showStage('code');
        const step = body.step === 'new' ? 'new' : 'old';
        const key = step === 'old' ? 'contact.hint.old' : (kind === 'email' ? 'contact.hint.new_email' : 'contact.hint.new_phone');
        text('[data-contact-hint]', t(key, { target: body.maskedTarget || '' }));
        const code = field('code');
        if (code) {
            code.value = '';
            code.focus();
        }
        cooldown(body.resendIn);
    };

    // ------------------------------------------------------------------ Actions

    document.querySelectorAll('[data-contact-open]').forEach((btn) => btn.addEventListener('click', () => open(btn.getAttribute('data-contact-open'))));

    field('code')?.addEventListener('input', (e) => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6); });

    form.querySelector('[data-contact-cancel]')?.addEventListener('click', async () => {
        form.classList.add('hidden');
        reset();
        await postJson(EP.cancel); // abandonne aussi la demande côté serveur
    });

    resendButton?.addEventListener('click', async () => {
        resendButton.disabled = true;
        const result = await postJson(EP.resend);
        if (result.ok) {
            say(form, t('contact.resent'), true);
            cooldown(result.body?.resendIn);
        } else {
            say(form, errorText(result), false);
            cooldown(Number(result.body?.retryAfter) || Number(result.body?.resendIn) || 0);
            if (result.body?.error === 'contact_change_expired') showStage('request');
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        busy(form, true);
        try {
            if (stage === 'request') {
                const value = kind === 'phone_remove' ? '' : (field('value')?.value ?? '').trim();
                const result = await postJson(EP.start, { kind: kind === 'email' ? 'email' : 'phone', value });
                if (result.ok) {
                    say(form, t('contact.sent_old'), true);
                    enterCode(result.body);
                } else {
                    say(form, errorText(result), false);
                }

                return;
            }

            const code = (field('code')?.value ?? '').trim();
            if (code.length !== 6) {
                say(form, t('otp.enter_code'), false);

                return;
            }
            const result = await postJson(EP.confirm, { code });
            if (!result.ok) {
                say(form, errorText(result), false);
                if (result.body?.error === 'contact_change_expired') showStage('request'); // demande périmée : on recommence
                const box = field('code');
                if (box) { box.value = ''; box.focus(); }

                return;
            }
            if (result.body.state === 'sent') {
                say(form, t('contact.sent_new'), true);
                enterCode(result.body); // ancienne adresse confirmée : second code envoyé à la nouvelle destination

                return;
            }

            // Changement appliqué : on recharge la page pour afficher l'état à jour (la session a déjà été rouverte par le serveur).
            const done = kind === 'phone_remove' ? 'contact.done.phone_removed' : 'contact.done.' + (kind === 'email' ? 'email' : 'phone');
            say(form, t(done), true);
            showToast(t(done));
            finished = true;
            form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
            window.setTimeout(() => goTo('/compte?section=info'), 1200);
        } finally {
            if (!finished) busy(form, false);
        }
    });
}
