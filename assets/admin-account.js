import { goTo, postJson } from './api.js';
import { busy, errorText, say } from './form-ui.js';

/**
 * Fiche d'un compte dans l'espace administrateur (/admin/comptes/{id}).
 *
 * Ce script ne décide d'AUCUN droit : il envoie des requêtes JSON, et c'est le serveur qui accepte ou refuse (droit fin, jeton CSRF,
 * mot de passe de confirmation, règles métier). Masquer un bouton n'est qu'un confort d'affichage ; Twig ne dessine d'ailleurs un
 * formulaire que si l'administrateur a le droit correspondant.
 *
 * ATTENTION : l'attribut est `data-admin-form`, PAS `data-account-form` : ce dernier appartient aux formulaires du compte CLIENT
 * (assets/account.js), qui s'accrocherait aussi aux formulaires admin et viderait le champ mot de passe avant l'envoi.
 * (Les formulaires portent aussi method="post" : si le script ne se charge pas, le mot de passe ne part jamais dans l'adresse.)
 *
 * Deux gestes :
 *   - un formulaire [data-admin-form data-endpoint] : les champs sont lus, envoyés en JSON ; le bouton de validation cliqué
 *     (name="action" value="…") est inclus ; les cases « name[] » deviennent une liste ;
 *   - un bouton [data-account-post data-payload] : envoi direct (après confirmation éventuelle [data-confirm]).
 * Après un succès, la page est rechargée (filtres de l'adresse conservés) : l'affichage reflète toujours l'état réel. Si la réponse porte
 * « redirect » (ex. après une suppression), on va à cette page ; si elle porte « warning » (ex. e-mail au candidat non parti), le message
 * reste affiché plus longtemps avant le rechargement.
 * Chaque formulaire pris en charge reçoit data-gb-bound : le garde-fou de templates/base.html.twig s'en sert pour refuser un envoi « à
 * l'ancienne » (navigateur) quand ce script n'a pas pu se charger, au lieu d'une page d'erreur 405.
 */
// On garde les filtres de l'adresse (?status=…&order=…) : un rechargement ne doit pas les perdre.
const reload = () => goTo(window.location.pathname + window.location.search);
// Fin d'une action réussie : le serveur peut indiquer une autre page (« redirect », ex. après une suppression) et un
// avertissement (« warning », ex. e-mail au candidat non parti) à lire avant de quitter la page.
const leave = (body, delay) => window.setTimeout(() => (body?.redirect ? goTo(body.redirect) : reload()), body?.warning ? Math.max(delay, 4500) : delay);

export function initAdminAccount() {
    const root = document.querySelector('[data-admin-account]');
    if (!root) return;

    root.querySelectorAll('[data-admin-form]').forEach((form) => form.setAttribute('data-gb-bound', '1'));
    root.querySelectorAll('[data-admin-form]').forEach((form) => form.addEventListener('submit', async (e) => {
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
                const warning = result.body?.warning;
                say(form, [form.getAttribute('data-done') || '✓', warning].filter(Boolean).join(' '), !warning);
                leave(result.body, 400);

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
        const notice = root.querySelector('[data-account-notice]');
        if (result.ok) {
            if (result.body?.warning && notice) {
                notice.textContent = result.body.warning;
                notice.className = 'rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900';
            }
            leave(result.body, 0);

            return;
        }
        btn.disabled = false;
        if (notice) {
            notice.textContent = errorText(result);
            notice.className = 'rounded-2xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800';
        }
    }));
}
