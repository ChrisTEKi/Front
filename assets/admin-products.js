import { goTo, postJson } from './api.js';
import { busy, errorText, say } from './form-ui.js';
import { t } from './i18n.js';

/**
 * Page d'administration du catalogue (/admin/produits).
 *
 * Deux choses seulement :
 *   1. le formulaire des produits de GoBuy (ajout / modification), comme « Mes produits » côté fournisseur ;
 *   2. les actions de modération (retirer, rétablir, signaler, mettre en avant, supprimer, catalogue entier). Chaque bouton
 *      `[data-admin-action]` porte son adresse d'API (`data-url`), son contenu de base (`data-payload`) et ce qu'il faut demander
 *      à l'administrateur (`data-need` : motif, durée, mot de passe). Sans rien à demander, l'action part directement.
 *
 * Ce script ne décide d'AUCUN droit : le serveur vérifie le droit fin de chaque action, le jeton CSRF et le mot de passe.
 */
export function initAdminProducts() {
    const root = document.querySelector('[data-admin-products]');
    if (!root) return;

    initProductForm(root);
    initActions(root);
}

/* ------------------------------------------------------------------ Formulaire des produits GoBuy */
function initProductForm(root) {
    const form = root.querySelector('[data-product-form]');
    if (!form) return; // pas le droit « products.manage »

    const endpoint = (name, id = '') => root.getAttribute('data-endpoint-' + name).replace('00000000-0000-0000-0000-000000000000', encodeURIComponent(id));
    const title = form.querySelector('[data-product-form-title]');
    const field = (name) => form.querySelector('[name="' + name + '"]');
    const currencies = ['EUR', 'USD'];
    let editingId = null;

    const clearErrors = () => form.querySelectorAll('[data-field-error]').forEach((el) => { el.textContent = ''; el.classList.add('hidden'); });

    const open = (product) => {
        form.reset();
        clearErrors();
        form.querySelector('[data-form-message]')?.classList.add('hidden');
        editingId = product?.id ?? null;
        title.textContent = t(editingId ? 'products.form.edit' : 'products.form.add');
        ['name', 'description', 'category', 'reference'].forEach((name) => { if (field(name)) field(name).value = product?.[name] ?? ''; });
        field('priceMga').value = product ? String(product.priceMga) : '';
        currencies.forEach((code) => { if (field('price_' + code)) field('price_' + code).value = product?.prices?.[code] ?? ''; });
        form.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        field('name').focus();
    };

    root.querySelector('[data-product-add]')?.addEventListener('click', () => open(null));
    form.querySelector('[data-product-cancel]')?.addEventListener('click', () => form.classList.add('hidden'));
    root.querySelectorAll('[data-product-edit]').forEach((btn) => btn.addEventListener('click', () => {
        let product = null;
        try { product = JSON.parse(btn.getAttribute('data-product') || 'null'); } catch { product = null; }
        if (product) open(product);
    }));

    form.addEventListener('submit', async (e) => {
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
                window.setTimeout(() => goTo(window.location.pathname + window.location.search), 500);

                return;
            }
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
}

/* ------------------------------------------------------------------ Actions de modération */
function initActions(root) {
    const dialog = document.querySelector('[data-action-dialog]');
    if (!dialog) return;

    const form = dialog.querySelector('[data-action-form]');
    const title = dialog.querySelector('[data-action-title]');
    const error = dialog.querySelector('[data-action-error]');
    const submit = dialog.querySelector('[data-action-submit]');
    const notice = root.querySelector('[data-admin-notice]');
    let current = null; // { url, payload, need }

    const show = (message, ok) => {
        if (!notice) return;
        notice.textContent = message;
        notice.classList.remove('hidden', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800', 'border-rose-200', 'bg-rose-50', 'text-rose-700');
        notice.classList.add(...(ok ? ['border-emerald-200', 'bg-emerald-50', 'text-emerald-800'] : ['border-rose-200', 'bg-rose-50', 'text-rose-700']));
    };
    const refresh = () => goTo(window.location.pathname + window.location.search);

    const send = async (url, payload) => {
        const result = await postJson(url, payload);
        if (result.ok) { refresh(); return { done: true }; }

        return { done: false, message: result.expired ? t('session.expired') : errorText(result) };
    };

    root.querySelectorAll('[data-admin-action]').forEach((btn) => btn.addEventListener('click', async () => {
        let payload = {};
        try { payload = JSON.parse(btn.getAttribute('data-payload') || '{}'); } catch { payload = {}; }
        const need = (btn.getAttribute('data-need') || '').split(',').filter(Boolean);
        current = { url: btn.getAttribute('data-url'), payload, need };

        // Rien à demander : l'action part directement.
        if (need.length === 0) {
            btn.disabled = true;
            const outcome = await send(current.url, payload);
            if (!outcome.done) { btn.disabled = false; show(outcome.message, false); }

            return;
        }

        title.textContent = btn.getAttribute('data-title') || '';
        submit.classList.toggle('bg-rose-600', btn.getAttribute('data-danger') === '1');
        submit.classList.toggle('hover:bg-rose-700', btn.getAttribute('data-danger') === '1');
        submit.classList.toggle('bg-indigo-600', btn.getAttribute('data-danger') !== '1');
        dialog.querySelectorAll('[data-need-field]').forEach((box) => {
            const wanted = need.includes(box.getAttribute('data-need-field'));
            box.classList.toggle('hidden', !wanted);
            box.querySelectorAll('textarea, input, select').forEach((input) => { input.required = wanted && input.tagName !== 'SELECT'; });
        });
        error.classList.add('hidden');
        dialog.showModal();
    }));

    dialog.querySelector('[data-action-cancel]')?.addEventListener('click', () => dialog.close());
    // À la fermeture, on repart de zéro : aucun mot de passe saisi ne reste dans le formulaire.
    dialog.addEventListener('close', () => { form.reset(); error.classList.add('hidden'); });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!current) return;
        const payload = { ...current.payload };
        if (current.need.includes('reason')) payload.reason = form.elements.reason.value;
        if (current.need.includes('days')) payload.days = form.elements.days.value;
        if (current.need.includes('password')) payload.password = form.elements.password.value;

        submit.disabled = true;
        const outcome = await send(current.url, payload);
        submit.disabled = false;
        if (outcome.done) { dialog.close(); return; }

        form.querySelectorAll('input[type="password"]').forEach((i) => { i.value = ''; });
        error.textContent = outcome.message;
        error.classList.remove('hidden');
    });
}
