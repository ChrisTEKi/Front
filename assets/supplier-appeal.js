import { postForm } from './api.js';
import { busy, errorText, say } from './form-ui.js';
import { t } from './i18n.js';

/**
 * Page « Contester la décision » du fournisseur.
 *
 * Le script envoie le message et les pièces (multipart) à /fournisseur/api/contestation. Il fait seulement un contrôle de confort
 * (nombre et poids des fichiers) pour éviter un envoi inutile ; le SERVEUR refait tous les contrôles (type réel du fichier, poids,
 * nombre de contestations, état du dossier) et c'est lui qui décide.
 */
export function initSupplierAppeal() {
    const root = document.querySelector('[data-supplier-appeal]');
    const form = root?.querySelector('[data-appeal-form]');
    if (!root || !form) return;

    const maxFiles = Number(root.getAttribute('data-max-files'));
    const maxFile = Number(root.getAttribute('data-max-file-mb')) * 1048576;
    const maxTotal = Number(root.getAttribute('data-max-total-mb')) * 1048576;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const files = Array.from(form.elements['files[]']?.files ?? []);
        const total = files.reduce((sum, f) => sum + f.size, 0);
        if (files.length > maxFiles || files.some((f) => f.size > maxFile) || total > maxTotal) {
            say(form, t('appeal.files_too_big'), false);

            return;
        }

        busy(form, true);
        try {
            const result = await postForm(root.getAttribute('data-endpoint'), new FormData(form));
            if (result.ok) {
                say(form, t('appeal.sent'), true);
                window.setTimeout(() => window.location.reload(), 900);

                return;
            }
            say(form, errorText(result), false);
        } finally {
            busy(form, false);
        }
    });
}
