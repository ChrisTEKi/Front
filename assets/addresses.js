import { goTo, postJson } from './api.js';
import { busy, errorText, say } from './form-ui.js';
import { t } from './i18n.js';

/**
 * Carnet d'adresses de livraison (page « Mon compte › Mes adresses »).
 *
 * La liste est affichée par le serveur (Twig) ; chaque carte porte ses données dans `data-address` (JSON). Ce script ne fait que :
 *   - ouvrir le formulaire pour AJOUTER une adresse ou MODIFIER celle d'une carte ;
 *   - appeler l'API /compte/api/adresses (ajout, modification, « par défaut », suppression) ;
 *   - recharger la page à la fin : la liste redessinée par le serveur est donc toujours exacte.
 *
 * Le serveur applique toutes les règles (10 adresses au maximum, une seule adresse par défaut, adresses d'un autre compte
 * introuvables) : rien n'est décidé ici.
 */
const API = '/compte/api/adresses';
const reload = () => goTo('/compte?section=addresses');

export function initAddresses() {
    const root = document.querySelector('[data-addresses]');
    if (!root) return;

    const form = root.querySelector('[data-address-form-edit]');
    const title = root.querySelector('[data-address-form-title]');
    const field = (name) => form?.querySelector('[name="' + name + '"]');
    let editingId = null;

    /** Ouvre le formulaire : vide pour une nouvelle adresse (nom du compte prérempli), rempli pour une modification. */
    const open = (address) => {
        if (!form) return;
        form.reset();
        form.querySelector('[data-form-message]')?.classList.add('hidden');
        editingId = address?.id ?? null;
        title.textContent = t(editingId ? 'addresses.form.edit' : 'addresses.form.add');
        const values = address ?? { fullName: form.getAttribute('data-default-name') ?? '', city: form.getAttribute('data-default-city') ?? '', postalCode: form.getAttribute('data-default-postal') ?? '' };
        ['label', 'fullName', 'phone', 'street', 'city', 'postalCode', 'instructions'].forEach((name) => {
            const input = field(name);
            if (input) input.value = values[name] ?? '';
        });
        // « Définir comme adresse par défaut » : coché et verrouillé pour l'adresse qui l'est déjà ; la 1re adresse le devient d'office.
        const def = field('makeDefault');
        if (def) {
            def.checked = Boolean(address?.isDefault);
            def.disabled = Boolean(address?.isDefault);
        }
        form.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        field('street')?.focus();
    };

    root.querySelector('[data-address-add]')?.addEventListener('click', () => open(null));
    root.querySelector('[data-address-cancel]')?.addEventListener('click', () => form?.classList.add('hidden'));

    root.querySelectorAll('[data-address-card]').forEach((card) => {
        let address = null;
        try { address = JSON.parse(card.getAttribute('data-address') || 'null'); } catch { address = null; }
        if (!address) return;

        card.querySelector('[data-address-edit]')?.addEventListener('click', () => open(address));

        card.querySelector('[data-address-default]')?.addEventListener('click', async (e) => {
            e.currentTarget.disabled = true;
            const result = await postJson(API + '/' + encodeURIComponent(address.id) + '/defaut');
            if (result.ok) reload(); else { e.currentTarget.disabled = false; alertError(result); }
        });

        card.querySelector('[data-address-delete]')?.addEventListener('click', async (e) => {
            if (!window.confirm(t('addresses.confirm_delete'))) return;
            e.currentTarget.disabled = true;
            const result = await postJson(API + '/' + encodeURIComponent(address.id) + '/supprimer');
            if (result.ok) reload(); else { e.currentTarget.disabled = false; alertError(result); }
        });
    });

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = {};
        ['label', 'fullName', 'phone', 'street', 'city', 'postalCode', 'instructions'].forEach((name) => { data[name] = (field(name)?.value ?? '').trim(); });
        data.makeDefault = field('makeDefault')?.checked === true;

        busy(form, true);
        try {
            const result = await postJson(editingId ? API + '/' + encodeURIComponent(editingId) + '/modifier' : API, data);
            if (result.ok) {
                say(form, t('addresses.saved'), true);
                window.setTimeout(reload, 500);

                return;
            }
            // Erreurs champ par champ (422) : on les affiche toutes, sinon le message général du serveur.
            const messages = result.body?.errors ? Object.values(result.body.errors).flat().join(' ') : '';
            say(form, messages !== '' ? messages : errorText(result), false);
        } finally {
            busy(form, false);
        }
    });

    function alertError(result) {
        say(form ?? root, errorText(result), false);
    }
}
