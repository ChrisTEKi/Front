import { postForm } from './api.js';
import { busy, errorText, say } from './form-ui.js';
import { t } from './i18n.js';

/**
 * Formulaire « Postuler » d'une offre d'emploi publique (/emplois/{id}).
 *
 * Le script envoie le formulaire (multipart) à POST /emplois/{id}/candidater et affiche un message de confirmation à la place du
 * formulaire. Il ne fait qu'un contrôle de confort (poids des fichiers) pour éviter un envoi inutile ; le SERVEUR refait tous les
 * contrôles (type réel du fichier, poids, doublon, nombre d'envois par heure, offre encore ouverte) et c'est lui qui décide.
 * Marqueur propre à ce module : « data-job-apply » (jamais « data-account-form », réservé au compte client).
 */
export function initJobApplication() {
    const root = document.querySelector('[data-job-apply]');
    const form = root?.querySelector('[data-job-form]');
    if (!root || !form) return;

    const maxFile = Number(root.getAttribute('data-max-file-mb')) * 1048576;
    const maxTotal = Number(root.getAttribute('data-max-total-mb')) * 1048576;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const files = ['cv', 'letter'].map((name) => form.elements[name]?.files?.[0]).filter(Boolean);
        const total = files.reduce((sum, f) => sum + f.size, 0);
        if (files.some((f) => f.size > maxFile) || total > maxTotal) {
            say(form, t('job.files_too_big'), false);

            return;
        }

        busy(form, true);
        try {
            const result = await postForm(root.getAttribute('data-endpoint'), new FormData(form));
            if (result.ok) {
                form.classList.add('hidden');
                const done = root.querySelector('[data-job-sent]');
                done?.classList.remove('hidden');
                done?.classList.add('flex');
                done?.scrollIntoView({ behavior: 'smooth', block: 'center' });

                return;
            }
            say(form, errorText(result), false);
        } finally {
            busy(form, false);
        }
    });
}
