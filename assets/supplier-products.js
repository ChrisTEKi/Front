import { goTo, postJson } from './api.js';
import { busy, errorText, say } from './form-ui.js';
import { t } from './i18n.js';

/**
 * Page « Mes produits » du fournisseur.
 *
 * La liste est dessinée par le serveur (Twig) ; ce script ne fait que :
 *   - ouvrir le formulaire pour AJOUTER un produit ou MODIFIER celui d'une ligne (données dans `data-product`, JSON) ;
 *   - appeler l'API /fournisseur/api/produits (création, modification, masquer / afficher, suppression) ;
 *   - recharger la page à la fin : la liste redessinée par le serveur est donc toujours exacte.
 *
 * Le serveur applique toutes les règles (dossier approuvé ou en attente, 2 000 produits au plus, produit d'un autre compte
 * introuvable, produit retiré par un administrateur non modifiable) : rien n'est décidé ici.
 */
const reload = () => goTo('/fournisseur/produits');

export function initSupplierProducts() {
    const root = document.querySelector('[data-supplier-products]');
    if (!root) return;

    const endpoint = (name, id = '') => root.getAttribute('data-endpoint-' + name).replace('00000000-0000-0000-0000-000000000000', encodeURIComponent(id));
    const form = root.querySelector('[data-product-form]');
    const title = root.querySelector('[data-product-form-title]');
    const field = (name) => form?.querySelector('[name="' + name + '"]');
    const currencies = ['EUR', 'USD'];
    let editingId = null;

    const clearErrors = () => form?.querySelectorAll('[data-field-error]').forEach((el) => { el.textContent = ''; el.classList.add('hidden'); });

    /** Ouvre le formulaire : vide pour un nouveau produit, rempli pour une modification. */
    const open = (product) => {
        if (!form) return;
        form.reset();
        clearErrors();
        form.querySelector('[data-form-message]')?.classList.add('hidden');
        editingId = product?.id ?? null;
        title.textContent = t(editingId ? 'products.form.edit' : 'products.form.add');
        ['name', 'description', 'category', 'reference'].forEach((name) => { if (field(name)) field(name).value = product?.[name] ?? ''; });
        if (field('priceMga')) field('priceMga').value = product ? String(product.priceMga) : '';
        currencies.forEach((code) => { if (field('price_' + code)) field('price_' + code).value = product?.prices?.[code] ?? ''; });
        form.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        field('name')?.focus();
    };

    root.querySelector('[data-product-add]')?.addEventListener('click', () => open(null));
    root.querySelector('[data-product-cancel]')?.addEventListener('click', () => form?.classList.add('hidden'));

    root.querySelectorAll('[data-product-edit]').forEach((btn) => btn.addEventListener('click', () => {
        let product = null;
        try { product = JSON.parse(btn.getAttribute('data-product') || 'null'); } catch { product = null; }
        if (product) open(product);
    }));

    root.querySelectorAll('[data-product-visibility]').forEach((btn) => btn.addEventListener('click', async () => {
        btn.disabled = true;
        const result = await postJson(endpoint('visibility', btn.getAttribute('data-id')), { visible: btn.getAttribute('data-visible') === 'true' });
        if (result.ok) reload(); else { btn.disabled = false; alertError(result); }
    }));

    root.querySelectorAll('[data-product-delete]').forEach((btn) => btn.addEventListener('click', async () => {
        if (!window.confirm(t('products.confirm_delete'))) return;
        btn.disabled = true;
        const result = await postJson(endpoint('delete', btn.getAttribute('data-id')));
        if (result.ok) reload(); else { btn.disabled = false; alertError(result); }
    }));

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        const data = { prices: {} };
        ['name', 'description', 'category', 'reference', 'priceMga'].forEach((name) => { data[name] = (field(name)?.value ?? '').trim(); });
        currencies.forEach((code) => { data.prices[code] = (field('price_' + code)?.value ?? '').trim(); });

        busy(form, true);
        try {
            const result = await postJson(editingId ? endpoint('update', editingId) : endpoint('add'), data);
            if (result.ok) {
                say(form, t('products.saved'), true);
                window.setTimeout(reload, 500);

                return;
            }
            // Erreurs champ par champ (422) : chacune sous son champ ; ce qui n'a pas de champ va dans le message général.
            let general = '';
            Object.entries(result.body?.errors ?? {}).forEach(([name, messages]) => {
                const target = form.querySelector('[data-field-error="' + name + '"]');
                const text = Array.isArray(messages) ? messages.join(' ') : String(messages);
                if (target) { target.textContent = text; target.classList.remove('hidden'); } else { general += (general ? ' ' : '') + text; }
            });
            say(form, general !== '' ? general : (result.body?.errors ? t('products.fix') : errorText(result)), false);
        } finally {
            busy(form, false);
        }
    });

    function alertError(result) {
        if (form) { form.classList.remove('hidden'); say(form, errorText(result), false); } else { window.alert(errorText(result)); }
    }
}
