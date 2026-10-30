import { t } from './i18n.js';

/**
 * Petits outils d'affichage partagés par les écrans du compte (réglages, coordonnées, photo, adresses) :
 * message sous un formulaire, texte d'erreur d'une réponse du serveur, bouton « en cours ».
 */

/** Affiche un message sous le formulaire (vert = succès, rouge = erreur). Le texte est posé avec textContent : jamais interprété comme du HTML. */
export function say(form, message, ok) {
    const box = form.querySelector('[data-form-message]');
    if (!box) return;
    box.textContent = message;
    box.classList.remove('hidden', 'bg-red-50', 'text-red-700', 'bg-emerald-50', 'text-emerald-800');
    box.classList.add(...(ok ? ['bg-emerald-50', 'text-emerald-800'] : ['bg-red-50', 'text-red-700']));
    box.setAttribute('role', ok ? 'status' : 'alert');
}

/** Texte d'erreur d'une réponse : le message du serveur (déjà traduit), sinon un message générique. */
export function errorText(result) {
    if (result.network) return t('net.error');
    if (result.expired) return t('session.expired');

    return result.body?.message || t('net.error');
}

/** Active / désactive les boutons d'envoi d'un formulaire pendant un appel. */
export function busy(form, on) {
    form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = on; });
}
