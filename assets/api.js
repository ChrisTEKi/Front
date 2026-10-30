/**
 * Appels JSON vers le back-end Symfony (partagés par tous les écrans : connexion, commande, compte, admin…).
 *
 * Règles de sécurité appliquées ICI, une seule fois :
 *   - le jeton CSRF (balise <meta name="csrf-token">, générée par base.html.twig) est envoyé dans l'en-tête
 *     « X-CSRF-Token » de CHAQUE requête POST : le serveur refuse tout POST sans jeton valide (ApiCsrfSubscriber) ;
 *   - les cookies de session sont envoyés (credentials: 'same-origin') mais jamais à un autre site ;
 *   - aucune donnée sensible n'est écrite dans l'URL (tout passe dans le corps JSON).
 */

/** Jeton CSRF courant (chaîne vide si la page n'en a pas). */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Envoie un POST JSON.
 *
 * Retourne toujours un objet (jamais d'exception) :
 *   ok       — vrai si le serveur a répondu 2xx ET `body.ok` n'est pas faux ;
 *   status   — code HTTP (0 = serveur injoignable) ;
 *   body     — corps JSON décodé, ou null si la réponse n'est pas du JSON
 *              (cas typique : la session a expiré et le serveur a répondu par la page de connexion) ;
 *   network  — vrai si le serveur est injoignable ;
 *   expired  — vrai si la réponse n'est pas du JSON (session expirée / redirection vers la connexion).
 *
 * @param {string} url
 * @param {object} data
 * @returns {Promise<{ok: boolean, status: number, body: (object|null), network: boolean, expired: boolean}>}
 */
export async function postJson(url, data = {}) {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify(data),
        });
    } catch {
        return { ok: false, status: 0, body: null, network: true, expired: false };
    }

    const isJson = (response.headers.get('content-type') || '').includes('application/json');
    const body = isJson ? await response.json().catch(() => null) : null;

    return {
        ok: response.ok && null !== body && false !== body.ok,
        status: response.status,
        body,
        network: false,
        expired: !isJson,
    };
}

/**
 * Envoie un formulaire « multipart » (envoi d'un fichier : photo de profil). Même contrat de retour que postJson.
 * Pas d'en-tête Content-Type : c'est le navigateur qui le fixe, avec la « frontière » propre à ce corps de requête.
 *
 * @param {string} url
 * @param {FormData} formData
 */
export async function postForm(url, formData) {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
            body: formData,
        });
    } catch {
        return { ok: false, status: 0, body: null, network: true, expired: false };
    }

    const isJson = (response.headers.get('content-type') || '').includes('application/json');
    const body = isJson ? await response.json().catch(() => null) : null;

    return { ok: response.ok && null !== body && false !== body.ok, status: response.status, body, network: false, expired: !isJson };
}

/**
 * Envoie un GET JSON (lecture : état d'un parcours, liste…). Même contrat de retour que postJson.
 *
 * @param {string} url
 * @returns {Promise<{ok: boolean, status: number, body: (object|null), network: boolean, expired: boolean}>}
 */
export async function getJson(url) {
    let response;
    try {
        response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    } catch {
        return { ok: false, status: 0, body: null, network: true, expired: false };
    }

    const isJson = (response.headers.get('content-type') || '').includes('application/json');
    const body = isJson ? await response.json().catch(() => null) : null;

    return { ok: response.ok && null !== body && false !== body.ok, status: response.status, body, network: false, expired: !isJson };
}

/**
 * Redirection SÛRE après une action réussie : seulement vers un chemin de CE site (« /compte »), jamais vers une autre adresse.
 * Défense en profondeur : le serveur ne renvoie déjà que des chemins sûrs.
 */
export function goTo(path, fallback = '/') {
    const safe = typeof path === 'string' && path.startsWith('/') && !path.startsWith('//') && !path.includes('\\');
    window.location.assign(safe ? path : fallback);
}
