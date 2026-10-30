import { goTo, postForm, postJson } from './api.js';
import { errorText, say } from './form-ui.js';
import { t } from './i18n.js';
import { showToast } from './ui.js';

/**
 * Photo de profil (page « Mon compte › Mes informations »).
 *
 * La photo est réduite DANS LE NAVIGATEUR avant l'envoi (1 024 px, JPEG) : l'envoi reste léger même avec une photo de téléphone de
 * 8 Mo. Le serveur ne s'y fie pas : il décode et ré-encode TOUJOURS l'image (AvatarImageProcessor), refuse tout ce qui n'est pas une
 * vraie photo et limite la taille. Si la réduction échoue (navigateur ancien), le fichier d'origine est envoyé tel quel.
 *
 * Après un envoi ou une suppression réussis, la page est rechargée : l'en-tête et la carte du compte affichent alors la nouvelle photo.
 */
const ENDPOINTS = { set: '/compte/api/photo', remove: '/compte/api/photo/supprimer' };
const MAX_SIDE = 1024;
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

export function initAvatar() {
    const panel = document.querySelector('[data-avatar-panel]');
    if (!panel) return;

    const input = panel.querySelector('[data-avatar-input]');
    const pick = panel.querySelector('[data-avatar-pick]');
    const remove = panel.querySelector('[data-avatar-remove]');
    const reload = () => window.setTimeout(() => goTo('/compte?section=info'), 700);

    pick?.addEventListener('click', () => input?.click());

    input?.addEventListener('change', async () => {
        const file = input.files?.[0];
        input.value = ''; // permet de rechoisir le même fichier ensuite
        if (!file) return;
        if (!ALLOWED.includes(file.type)) {
            say(panel, t('avatar.unsupported'), false);

            return;
        }

        setBusy(true);
        try {
            const blob = await shrink(file);
            const data = new FormData();
            data.append('photo', blob, 'photo.jpg');
            const result = await postForm(ENDPOINTS.set, data);
            if (result.ok) {
                say(panel, t('avatar.saved'), true);
                showToast(t('avatar.saved'));
                reload();
            } else {
                say(panel, errorText(result), false);
            }
        } finally {
            setBusy(false);
        }
    });

    remove?.addEventListener('click', async () => {
        setBusy(true);
        try {
            const result = await postJson(ENDPOINTS.remove);
            if (result.ok) {
                say(panel, t('avatar.removed'), true);
                showToast(t('avatar.removed'));
                reload();
            } else {
                say(panel, errorText(result), false);
            }
        } finally {
            setBusy(false);
        }
    });

    function setBusy(on) {
        [pick, remove].forEach((b) => { if (b) b.disabled = on; });
    }
}

/**
 * Réduit l'image à 1 024 px au plus et la convertit en JPEG. `imageOrientation: 'from-image'` applique le sens de prise de vue
 * des photos de téléphone. En cas d'échec (format non géré, GIF animé…), renvoie le fichier d'origine : le serveur tranchera.
 */
async function shrink(file) {
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; // fond blanc sous les images transparentes (le JPEG n'a pas de transparence)
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));

        return blob || file;
    } catch {
        return file;
    }
}
