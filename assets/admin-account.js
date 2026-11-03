import { goTo, postJson } from './api.js';
import { busy, errorText, say } from './form-ui.js';

/**
 * Fiche d'un compte dans l'espace administrateur (/admin/comptes/{id}).
 *
 * Ce script ne décide d'AUCUN droit : il envoie des requêtes JSON, et c'est le serveur qui accepte ou refuse (droit fin, jeton CSRF,
 * mot de passe de confirmation, règles métier). Masquer un bouton n'est qu'un confort d'affichage ; Twig ne dessine d'ailleurs un
 * formulaire que si l'administrateur a le droit correspondant.
 *
 * Deux gestes :
 *   - un formulaire [data-account-form data-endpoint] : les champs sont lus, envoyés en JSON ; le bouton de validation cliqué
 *     (name="action" value="…") est inclus ; les cases « name[] » deviennent une liste ;
 *   - un bouton [data-account-post data-payload] : envoi direct (après confirmation éventuelle [data-confirm]).
 * Après un succès, la page est rechargée : l'affichage reflète toujours l'état réel.
 */
const reload = () => goTo(window.location.pathname);

export function initAdminAccount() {
    const root = document.querySelector('[data-admin-account]');
    if (!root) return;

    root.querySelectorAll('[data-account-form]').forEach((form) => form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const question = e.submitter?.getAttribute('data-confirm') || form.getAttribute('data-confirm');
        if (question && !window.confirm(question)) return;

        const data = {};
        for (const [key, raw] of new FormData(form, e.submitter ?? undefined).entries()) {
            const value = typeof raw === 'string' ? raw.trim() : '';
            if (key.endsWith('[]')) {
                const name = key.slice(0, -2);
                (data[name] ??= []).push(value);
            } else {
                data[key] = value;
            }
        }

        busy(form, true);
        try {
            const result = await postJson(form.getAttribute('data-endpoint'), data);
            if (result.ok) {
                say(form, form.getAttribute('data-done') || '✓', true);
                window.setTimeout(reload, 400);

                return;
            }
            say(form, errorText(result), false);
        } finally {
            busy(form, false);
        }
    }));

    root.querySelectorAll('[data-account-post]').forEach((btn) => btn.addEventListener('click', async () => {
        const question = btn.getAttribute('data-confirm');
        if (question && !window.confirm(question)) return;
        let payload = {};
        try { payload = JSON.parse(btn.getAttribute('data-payload') || '{}'); } catch { payload = {}; }
        btn.disabled = true;
        const result = await postJson(btn.getAttribute('data-account-post'), payload);
        if (result.ok) {
            reload();

            return;
        }
        btn.disabled = false;
        const notice = root.querySelector('[data-account-notice]');
        if (notice) {
            notice.textContent = errorText(result);
            notice.className = 'rounded-2xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800';
        }
    }));
}
