import { formatAr, locale } from './i18n.js';

/**
 * Montants dans la devise d'affichage choisie par le visiteur (Ariary, euro ou dollar).
 *
 * La page fournit la devise et les taux dans <script type="application/json" id="gb-currency"> (voir base.html.twig) :
 *   { "current": "EUR", "rates": { "EUR": 5000, "USD": 4500 } }      (taux = Ariary pour 1 unité)
 * Les montants sont des ENTIERS en unités mineures (centimes pour l'euro et le dollar, Ariary entiers pour l'Ariary).
 *
 * IMPORTANT : ce fichier ne sert qu'à AFFICHER (panier, résumé de commande). Le serveur recalcule toujours le prix de chaque
 * ligne et le taux utilisé (voir OrderService) : un prix ou un taux modifié dans le navigateur ne change jamais ce qui est facturé.
 */
const FORMAT_LOCALES = { fr: 'fr-FR', en: 'en-US', es: 'es-ES' };
const MGA = 'MGA';

let config = null;

function load() {
    if (config === null) {
        try {
            const raw = JSON.parse(document.getElementById('gb-currency')?.textContent || '{}');
            config = { current: typeof raw.current === 'string' ? raw.current : MGA, rates: raw.rates && typeof raw.rates === 'object' ? raw.rates : {} };
        } catch {
            config = { current: MGA, rates: {} };
        }
    }

    return config;
}

/** Remplace les taux connus de la page (après une nouvelle lecture des prix sur le serveur). */
export function setRates(rates) {
    if (rates && typeof rates === 'object') load().rates = rates;
}

/** Devise d'affichage de la page (« MGA », « EUR » ou « USD »). */
export function currentCurrency() {
    return load().current;
}

/** Ariary pour 1 unité de la devise, ou null si la page n'en connaît pas (l'Ariary vaut 1). */
export function rateOf(code) {
    if (code === MGA) return 1;
    const rate = Number(load().rates[code]);

    return Number.isFinite(rate) && rate > 0 ? rate : null;
}

/** Unités mineures par unité : 1 pour l'Ariary, 100 pour l'euro et le dollar. */
export function minorFactor(code) {
    return code === MGA ? 1 : 100;
}

/** Ariary → unités mineures de la devise (au moins 1 pour un montant positif) ; null sans taux. */
export function fromMga(mga, code = currentCurrency()) {
    const rate = rateOf(code);
    if (rate === null) return null;
    if (code === MGA || mga <= 0) return Math.max(0, Math.round(mga));

    return Math.max(1, Math.round((mga * minorFactor(code)) / rate));
}

/** Unités mineures de la devise → Ariary ; null sans taux. */
export function toMga(minor, code) {
    const rate = rateOf(code);
    if (rate === null) return null;
    if (code === MGA || minor <= 0) return Math.max(0, Math.round(minor));

    return Math.max(1, Math.round((minor / minorFactor(code)) * rate));
}

/** Symbole de la devise : « Ar », « € », « $ ». */
export function symbolOf(code) {
    return { MGA: 'Ar', EUR: '€', USD: '$' }[code] ?? code;
}

/** Taux mis en forme (jusqu'à 4 décimales) : « 5 012,34 ». */
export function formatRate(rate) {
    return new Intl.NumberFormat(FORMAT_LOCALES[locale()] || 'fr-FR', { maximumFractionDigits: 4 }).format(rate);
}

/** Montant mis en forme : « 160 000 Ar », « 12,50 € », « $12.50 ». */
export function formatMoney(minor, code = currentCurrency()) {
    if (code === MGA) return formatAr(minor);
    try {
        return new Intl.NumberFormat(FORMAT_LOCALES[locale()] || 'fr-FR', { style: 'currency', currency: code }).format(minor / minorFactor(code));
    } catch {
        return formatAr(toMga(minor, code) ?? 0);
    }
}

/**
 * Prix UNITAIRE d'une ligne de panier dans la devise : le prix mémorisé pour cette devise (prix du vendeur ou prix converti
 * au moment de l'ajout), sinon converti depuis le prix en Ariary ; null si c'est impossible.
 */
export function linePrice(line, code = currentCurrency()) {
    if (code === MGA) return line.price;
    const stored = Number(line.prices?.[code]);
    if (Number.isFinite(stored) && stored > 0) return Math.round(stored);

    return fromMga(line.price, code);
}

/**
 * Devise dans laquelle afficher TOUT le panier : la devise choisie si chaque ligne peut y être exprimée, sinon l'Ariary
 * (on ne mélange jamais deux devises dans un même total).
 */
export function cartCurrency(lines) {
    const code = currentCurrency();

    return rateOf(code) !== null && lines.every((l) => linePrice(l, code) !== null) ? code : MGA;
}

/**
 * Équivalent en Ariary d'une ligne (ESTIMATION d'affichage) : le prix en Ariary, sauf si le vendeur a fixé un prix spécifique
 * dans la devise du panier, auquel cas ce prix est converti au taux de la page.
 */
export function lineMga(line, code = currentCurrency()) {
    if (code === MGA || !(line.overrides ?? []).includes(code)) return line.price;

    return toMga(linePrice(line, code), code) ?? line.price;
}
