import { formatAr, formatNumber, t } from './i18n.js';
import { showToast } from './ui.js';

/**
 * Panier et favoris.
 *
 * Le panier est mémorisé DANS LE NAVIGATEUR (localStorage) : un visiteur peut donc remplir son panier sans compte (navigation
 * « invité »). Chaque ligne garde une copie d'AFFICHAGE du produit (nom, prix, photo) prise sur la carte produit au moment de
 * l'ajout. Cette copie ne sert QU'À l'affichage : à la commande, le navigateur n'envoie que { ref, quantity } et le serveur
 * recalcule les prix depuis le catalogue (jamais de prix venu du navigateur). Voir OrderService.
 *
 * Données mémorisées :
 *   gobuy_cart      [{ ref, name, price, image, supplier, quantity }]
 *   gobuy_favorites [ref, …]
 * Le stockage peut être indisponible (navigation privée…) : tout est protégé par try/catch et l'écran reste utilisable.
 */
const CART_KEY = 'gobuy_cart';
const FAVORITES_KEY = 'gobuy_favorites';
const MAX_QUANTITY = 99;

/** Lecture sûre : ne renvoie que des lignes bien formées (le stockage peut avoir été modifié à la main). */
export function readCart() {
    try {
        const raw = JSON.parse(window.localStorage.getItem(CART_KEY) || '[]');
        if (!Array.isArray(raw)) return [];

        return raw
            .filter((l) => l && typeof l.ref === 'string' && l.ref !== '' && Number.isFinite(Number(l.price)) && Number.isInteger(Number(l.quantity)) && Number(l.quantity) > 0)
            .map((l) => ({
                ref: l.ref,
                name: String(l.name ?? ''),
                price: Math.max(0, Math.round(Number(l.price))),
                image: safeImage(l.image),
                supplier: String(l.supplier ?? ''),
                quantity: Math.min(MAX_QUANTITY, Number(l.quantity)),
            }));
    } catch {
        return [];
    }
}

function writeCart(lines) {
    try {
        window.localStorage.setItem(CART_KEY, JSON.stringify(lines));
    } catch { /* stockage indisponible : le panier reste en mémoire pour cette page seulement */ }
    window.dispatchEvent(new CustomEvent('gb:cart-changed'));
}

/** Vide le panier (après une commande réussie). */
export function clearCart() {
    writeCart([]);
}

export function cartSubtotal(lines = readCart()) {
    return lines.reduce((sum, l) => sum + l.price * l.quantity, 0);
}

export function cartCount(lines = readCart()) {
    return lines.reduce((sum, l) => sum + l.quantity, 0);
}

/** Frais de livraison forfaitaires lus dans la page (data-delivery-fee sur <body>), 0 si le panier est vide. */
export function deliveryFee(lines = readCart()) {
    return lines.length > 0 ? Number(document.body.getAttribute('data-delivery-fee')) || 0 : 0;
}

/** Une photo n'est acceptée que si c'est une adresse http(s) ou un chemin du site (pas de « javascript: »). */
function safeImage(value) {
    const v = String(value ?? '');

    return /^(https?:\/\/|\/)/.test(v) && !v.startsWith('//') ? v : '';
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

// ----------------------------------------------------------------------------------------------- Modifications

function addLine(data) {
    const lines = readCart();
    const found = lines.find((l) => l.ref === data.ref);
    if (found) {
        found.quantity = Math.min(MAX_QUANTITY, found.quantity + 1);
    } else {
        lines.push({ ...data, quantity: 1 });
    }
    writeCart(lines);
}

function changeQuantity(ref, delta) {
    const lines = readCart();
    const line = lines.find((l) => l.ref === ref);
    if (!line) return;
    line.quantity = Math.min(MAX_QUANTITY, line.quantity + delta);
    writeCart(line.quantity <= 0 ? lines.filter((l) => l.ref !== ref) : lines);
}

function removeLine(ref) {
    writeCart(readCart().filter((l) => l.ref !== ref));
}

// ----------------------------------------------------------------------------------------------- Affichage

/** Bouton rond (+ / − / ×) d'une ligne de panier. */
function iconButton(className, label, glyph, onClick) {
    const b = el('button', className);
    b.type = 'button';
    b.setAttribute('aria-label', label);
    b.title = label;
    b.appendChild(glyph);
    b.addEventListener('click', onClick);

    return b;
}

/** Icône issue des icônes déjà présentes dans la page (<template data-icon="plus">), pour rester cohérent avec le reste du site. */
function icon(name, size) {
    const source = document.querySelector('template[data-icon="' + name + '"]');
    const node = source ? source.content.firstElementChild.cloneNode(true) : el('span', '', { plus: '+', minus: '−', x: '×' }[name] ?? '');
    if (source && size) node.setAttribute('class', size);

    return node;
}

function renderLine(line, compact) {
    const row = el('div', compact
        ? 'relative flex items-center gap-3 rounded-2xl border border-slate-100 bg-slate-50/50 p-3 transition-colors hover:bg-slate-50'
        : 'flex items-center justify-between gap-3 py-3 text-xs');

    const thumb = el('div', compact
        ? 'flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white'
        : 'flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-100 bg-white');
    if (line.image) {
        const img = el('img', 'h-full w-full object-cover');
        img.src = line.image;
        img.alt = line.name;
        img.loading = 'lazy';
        thumb.appendChild(img);
    }

    const info = el('div', 'min-w-0 flex-1 ' + (compact ? 'pr-6' : ''));
    info.appendChild(el('h4', 'truncate text-xs font-semibold text-slate-800', line.name));
    info.appendChild(el('div', 'mt-0.5 text-xs font-bold text-emerald-700', formatAr(line.price)));

    const qty = el('div', 'flex w-fit items-center overflow-hidden rounded-lg border border-slate-200 bg-white');
    const btnClass = 'flex h-6 w-6 items-center justify-center text-slate-500 hover:bg-slate-100';
    qty.appendChild(iconButton(btnClass, t('cart.decrease'), icon('minus', 'h-3 w-3'), () => changeQuantity(line.ref, -1)));
    qty.appendChild(el('span', 'w-6 text-center text-xs font-semibold text-slate-800', String(line.quantity)));
    qty.appendChild(iconButton(btnClass, t('cart.increase'), icon('plus', 'h-3 w-3'), () => changeQuantity(line.ref, 1)));

    if (compact) {
        qty.classList.add('mt-2');
        info.appendChild(qty);
        row.append(thumb, info);
        const remove = iconButton('absolute right-2 top-2 p-1.5 text-slate-400 transition-colors hover:text-red-500', t('cart.remove'), icon('x', 'h-4 w-4'), () => removeLine(line.ref));
        row.appendChild(remove);
    } else {
        const left = el('div', 'flex min-w-0 flex-1 items-center gap-3');
        left.append(thumb, info);
        row.append(left, qty);
    }

    return row;
}

function setText(root, selector, text) {
    root.querySelectorAll(selector).forEach((n) => { n.textContent = text; });
}

function render() {
    const lines = readCart();
    const count = cartCount(lines);
    const subtotal = cartSubtotal(lines);
    const fee = deliveryFee(lines);
    const total = subtotal + fee;

    document.querySelectorAll('[data-cart-count]').forEach((n) => { n.textContent = String(count); });
    document.querySelectorAll('[data-cart-count-badge]').forEach((n) => {
        n.textContent = String(count);
        n.classList.toggle('hidden', count === 0);
        n.classList.toggle('flex', count > 0);
    });

    document.querySelectorAll('[data-cart-view]').forEach((root) => {
        const compact = root.getAttribute('data-cart-view') === 'drawer';
        const list = root.querySelector('[data-cart-lines]');
        list.replaceChildren(...lines.map((l) => renderLine(l, compact)));
        root.querySelector('[data-cart-empty]')?.classList.toggle('hidden', lines.length > 0);
        root.querySelector('[data-cart-filled]')?.classList.toggle('hidden', lines.length === 0);
        setText(root, '[data-cart-items-count]', t('cart.items_count', { count: formatNumber(count) }));
        setText(root, '[data-cart-subtotal]', formatAr(subtotal));
        setText(root, '[data-cart-shipping]', formatAr(fee));
        setText(root, '[data-cart-total]', formatAr(total));
        setText(root, '[data-cart-monthly]', formatAr(Math.round(total / 4)));
    });
}

// ----------------------------------------------------------------------------------------------- Favoris

function readFavorites() {
    try {
        const raw = JSON.parse(window.localStorage.getItem(FAVORITES_KEY) || '[]');

        return Array.isArray(raw) ? raw.filter((r) => typeof r === 'string') : [];
    } catch {
        return [];
    }
}

function writeFavorites(refs) {
    try {
        window.localStorage.setItem(FAVORITES_KEY, JSON.stringify(refs));
    } catch { /* ignoré */ }
}

function paintFavorite(btn, active) {
    btn.classList.toggle('text-red-500', active);
    btn.classList.toggle('bg-red-50', active);
    btn.classList.toggle('bg-white/90', !active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
}

function renderFavorites() {
    const favorites = readFavorites();
    document.querySelectorAll('[data-favorite-toggle]').forEach((btn) => paintFavorite(btn, favorites.includes(btn.getAttribute('data-favorite-toggle'))));
    document.querySelectorAll('[data-favorites-count]').forEach((n) => { n.textContent = String(favorites.length); });
    document.querySelectorAll('[data-favorites-summary]').forEach((n) => {
        n.textContent = favorites.length === 0 ? t('favorites.none') : t('favorites.count', { count: favorites.length });
    });
    document.querySelectorAll('[data-clear-favorites]').forEach((n) => n.classList.toggle('hidden', favorites.length === 0));
}

// ----------------------------------------------------------------------------------------------- Démarrage

export function initCart() {
    document.addEventListener('click', (e) => {
        const add = e.target.closest('[data-add-to-cart]');
        if (add) {
            e.preventDefault();
            addLine({
                ref: add.getAttribute('data-add-to-cart'),
                name: add.getAttribute('data-product-name') || '',
                price: Number(add.getAttribute('data-product-price')) || 0,
                image: safeImage(add.getAttribute('data-product-image')),
                supplier: add.getAttribute('data-product-supplier') || '',
            });
            add.classList.add('scale-90');
            window.setTimeout(() => add.classList.remove('scale-90'), 150);
            showToast(t('cart.added'));

            return;
        }

        const fav = e.target.closest('[data-favorite-toggle]');
        if (fav) {
            e.preventDefault();
            const ref = fav.getAttribute('data-favorite-toggle');
            const favorites = readFavorites();
            const active = !favorites.includes(ref);
            writeFavorites(active ? [...favorites, ref] : favorites.filter((r) => r !== ref));
            renderFavorites();
            showToast(active ? t('favorites.added') : t('favorites.removed'));

            return;
        }

        if (e.target.closest('[data-clear-favorites]') && window.confirm(t('favorites.confirm_clear'))) {
            writeFavorites([]);
            renderFavorites();
            showToast(t('favorites.cleared'));
        }
    });

    // Une autre page ou un autre onglet a modifié le panier : on se remet à jour.
    window.addEventListener('storage', (e) => {
        if (e.key === CART_KEY) render();
        if (e.key === FAVORITES_KEY) renderFavorites();
    });
    window.addEventListener('gb:cart-changed', render);

    render();
    renderFavorites();
}
