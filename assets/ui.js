/**
 * Petits éléments d'interface partagés par tous les écrans : bulle d'information (toast) et fenêtres modales.
 */

/** Affiche une bulle de message en bas de l'écran pendant quelques secondes. */
export function showToast(message) {
    let host = document.getElementById('gb-toast-host');
    if (!host) {
        host = document.createElement('div');
        host.id = 'gb-toast-host';
        host.setAttribute('role', 'status');
        host.className = 'pointer-events-none fixed inset-x-0 bottom-20 z-[100] flex flex-col items-center gap-2 sm:bottom-6';
        document.body.appendChild(host);
    }
    const toast = document.createElement('div');
    toast.className = 'pointer-events-auto rounded-full bg-slate-950 px-4 py-2 text-xs font-semibold text-white shadow-xl transition-opacity duration-300';
    toast.textContent = message;
    host.appendChild(toast);
    window.setTimeout(() => { toast.style.opacity = '0'; window.setTimeout(() => toast.remove(), 300); }, 2600);
}

/** Ouvre la fenêtre modale `data-modal="<name>"` ; `trigger` (le bouton cliqué) peut préciser l'onglet à afficher. */
export function openModal(name, trigger) {
    const modal = document.querySelector('[data-modal="' + name + '"]');
    if (!modal) return;
    modal.classList.remove('hidden', 'pointer-events-none');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');

    if (name === 'auth' && trigger?.getAttribute('data-auth-mode')) {
        window.dispatchEvent(new CustomEvent('gb:auth-switch', { detail: trigger.getAttribute('data-auth-mode') }));
    }
    if (name === 'auth') {
        window.dispatchEvent(new CustomEvent('gb:auth-opened'));
    }
    if (name === 'company-info' && trigger?.getAttribute('data-info-section')) {
        window.dispatchEvent(new CustomEvent('gb:info-switch', { detail: trigger.getAttribute('data-info-section') }));
    }
}

/** Ferme une fenêtre modale (l'élément lui-même). */
export function closeModal(modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}
