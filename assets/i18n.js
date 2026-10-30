/**
 * Traductions et formats pour le JavaScript.
 *
 * Les textes viennent de la page : base.html.twig y écrit, dans <script type="application/json" id="gb-i18n">, tout le domaine
 * « js » (fichiers translations/js.fr|en|es.yaml) dans la langue de la requête. Aucun texte n'est écrit en dur dans les scripts.
 */
let dictionary = null;

function load() {
    if (dictionary === null) {
        try {
            dictionary = JSON.parse(document.getElementById('gb-i18n')?.textContent || '{}');
        } catch {
            dictionary = {};
        }
    }

    return dictionary;
}

/**
 * Texte traduit. Les valeurs dynamiques s'écrivent %nom% dans le texte : t('otp.resend_in', { seconds: 24 }).
 * Une clé inconnue est renvoyée telle quelle (visible, donc facile à repérer en test).
 */
export function t(key, params = {}) {
    let text = load()[key] ?? key;
    Object.entries(params).forEach(([name, value]) => {
        text = text.split('%' + name + '%').join(String(value));
    });

    return text;
}

/** Langue de la page (« fr », « en » ou « es »), lue sur <html lang>. */
export function locale() {
    return (document.documentElement.getAttribute('lang') || 'fr').slice(0, 2);
}

/** Langue de formatage des nombres et dates. */
export function numberLocale() {
    return { fr: 'fr-FR', en: 'en-GB', es: 'es-ES' }[locale()] || 'fr-FR';
}

/** Nombre entier avec séparateurs de milliers, selon la langue (« 160 000 » en français). */
export function formatNumber(value) {
    return Math.round(Number(value) || 0).toLocaleString(numberLocale());
}

/** Montant en Ariary : « 160 000 Ar ». */
export function formatAr(value) {
    return formatNumber(value) + ' Ar';
}

/** Date courte selon la langue : « 22 oct. », « 22 Oct », « 22 oct ». */
export function formatShortDate(date) {
    return date.toLocaleDateString(numberLocale(), { day: '2-digit', month: 'short' });
}
