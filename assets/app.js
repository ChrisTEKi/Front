import './stimulus_bootstrap.js';
import './styles/app.css';

/**
 * GoBuy — front-end interactions (plain JavaScript, no framework).
 * Everything is driven by small `data-*` attributes read straight out of
 * the Twig templates. No back-end calls yet: forms show local UI feedback
 * only (see README "Prochaines étapes").
 */
document.addEventListener('DOMContentLoaded', () => {
    initDrawers();
    initDropdowns();
    initModeToggle();
    initSearchClear();
    initModals();
    initToggleChips();
    initChipGroups();
    initCityPicker();
    initLangCurrencyPicker();
    initViewToggle();
    initFavorites();
    initCartButtons();
    initAuth();
    initOnboarding();
    initAccount();
    initInfoModal();
    initFeedback();
    initInfoModalForms();
    initCheckout();
    initVendorForm();
    initQuickTracking();
    initFlashCountdown();
    initInspector();
    initSupplierDashboard();
    initAdminDashboard();
});

document.addEventListener('turbo:load', initAdminDashboard);

/* ----------------------------- Toast ----------------------------- */
function showToast(message) {
    let host = document.getElementById('gb-toast-host');
    if (!host) {
        host = document.createElement('div');
        host.id = 'gb-toast-host';
        host.className = 'pointer-events-none fixed inset-x-0 bottom-20 z-[100] flex flex-col items-center gap-2 sm:bottom-6';
        document.body.appendChild(host);
    }
    const toast = document.createElement('div');
    toast.className = 'pointer-events-auto rounded-full bg-slate-950 px-4 py-2 text-xs font-semibold text-white shadow-xl transition-opacity duration-300';
    toast.textContent = message;
    host.appendChild(toast);
    window.setTimeout(() => { toast.style.opacity = '0'; window.setTimeout(() => toast.remove(), 300); }, 2600);
}
document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-alert]');
    if (el) showToast(el.getAttribute('data-alert'));
});

/* --------------------------- Drawers ------------------------------ */
function initDrawers() {
    document.querySelectorAll('[data-drawer-toggle]').forEach((button) => {
        const drawer = document.getElementById(button.getAttribute('data-drawer-toggle'));
        const overlay = drawer?.querySelector('[data-drawer-overlay]');
        const panel = drawer?.querySelector('[data-drawer-panel]');
        if (!drawer || !overlay || !panel) return;
        const closedClass = panel.getAttribute('data-drawer-closed') || '-translate-x-full';

        const open = () => {
            drawer.classList.remove('pointer-events-none');
            overlay.classList.remove('opacity-0');
            panel.classList.remove(closedClass);
            button.setAttribute('aria-expanded', 'true');
            document.body.classList.add('overflow-hidden');
        };
        const close = () => {
            overlay.classList.add('opacity-0');
            panel.classList.add(closedClass);
            button.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('overflow-hidden');
            window.setTimeout(() => drawer.classList.add('pointer-events-none'), 250);
        };
        button.addEventListener('click', open);
        drawer.querySelectorAll('[data-drawer-close]').forEach((el) => el.addEventListener('click', close));
    });
}

/* -------------------------- Dropdowns ------------------------------ */
function initDropdowns() {
    const triggers = document.querySelectorAll('[data-dropdown-toggle]');
    triggers.forEach((button) => {
        const menu = document.getElementById(button.getAttribute('data-dropdown-toggle'));
        if (!menu) return;
        menu.setAttribute('data-dropdown-menu', '');
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const isHidden = menu.classList.contains('hidden');
            document.querySelectorAll('[data-dropdown-menu]').forEach((m) => m.classList.add('hidden'));
            if (isHidden) { menu.classList.remove('hidden'); button.setAttribute('aria-expanded', 'true'); }
            else button.setAttribute('aria-expanded', 'false');
        });
    });
    document.addEventListener('click', () => {
        document.querySelectorAll('[data-dropdown-menu]').forEach((m) => m.classList.add('hidden'));
    });
}

function initCityPicker() {
    document.querySelectorAll('[data-city-option]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-city-label]').forEach((el) => { el.textContent = btn.getAttribute('data-city-option'); });
            showToast('Zone de livraison mise à jour : ' + btn.getAttribute('data-city-option'));
        });
    });
}

function initLangCurrencyPicker() {
    document.querySelectorAll('[data-lang-option]').forEach((btn) => {
        btn.addEventListener('click', () => document.querySelectorAll('[data-lang-label]').forEach((el) => { el.textContent = btn.getAttribute('data-lang-option'); }));
    });
    document.querySelectorAll('[data-currency-option]').forEach((btn) => {
        btn.addEventListener('click', () => document.querySelectorAll('[data-currency-label]').forEach((el) => { el.textContent = btn.getAttribute('data-currency-option'); }));
    });
}

/* ----------------------- Delivery/pickup switch --------------------- */
function initModeToggle() {
    document.querySelectorAll('[data-mode-toggle]').forEach((group) => {
        group.querySelectorAll('button').forEach((option) => {
            option.addEventListener('click', () => {
                group.querySelectorAll('button').forEach((b) => { b.classList.remove('bg-black', 'text-white', 'shadow-xs'); b.classList.add('text-neutral-600'); b.setAttribute('aria-pressed', 'false'); });
                option.classList.add('bg-black', 'text-white', 'shadow-xs'); option.classList.remove('text-neutral-600'); option.setAttribute('aria-pressed', 'true');
            });
        });
    });
}

/* ------------------------------ Search ------------------------------ */
function initSearchClear() {
    document.querySelectorAll('[data-search-input]').forEach((input) => {
        const clearButton = input.parentElement?.querySelector('[data-search-clear]');
        if (!clearButton) return;
        const sync = () => clearButton.classList.toggle('hidden', input.value.length === 0);
        input.addEventListener('input', sync);
        clearButton.addEventListener('click', () => { input.value = ''; input.focus(); sync(); });
        sync();
    });
}

/* -------------------------- Generic modals --------------------------- */
function initModals() {
    document.querySelectorAll('[data-open-modal]').forEach((trigger) => {
        trigger.addEventListener('click', () => openModal(trigger.getAttribute('data-open-modal'), trigger));
    });
    document.querySelectorAll('[data-modal]').forEach((modal) => {
        modal.querySelectorAll('[data-modal-close]').forEach((el) => el.addEventListener('click', () => closeModal(modal)));
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(modal); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') document.querySelectorAll('[data-modal]:not(.hidden)').forEach((m) => closeModal(m));
    });
}
function openModal(name, trigger) {
    const modal = document.querySelector('[data-modal="' + name + '"]');
    if (!modal) return;
    modal.classList.remove('hidden', 'pointer-events-none');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');

    if (name === 'auth' && trigger?.getAttribute('data-auth-mode')) {
        window.dispatchEvent(new CustomEvent('gb:auth-switch', { detail: trigger.getAttribute('data-auth-mode') }));
    }
    if (name === 'company-info' && trigger?.getAttribute('data-info-section')) {
        window.dispatchEvent(new CustomEvent('gb:info-switch', { detail: trigger.getAttribute('data-info-section') }));
    }
}
function closeModal(modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}

/* --------------------------- Toggle chips ---------------------------- */
function initToggleChips() {
    document.querySelectorAll('[data-toggle-chip]').forEach((chip) => {
        const activeClasses = (chip.getAttribute('data-active-class') || 'bg-black text-white border-black').split(' ');
        chip.addEventListener('click', () => {
            const isActive = chip.classList.contains(activeClasses[0]);
            activeClasses.forEach((c) => chip.classList.toggle(c, !isActive));
        });
    });
}

/* --------------------------- Chip groups (single-select) ------------- */
function initChipGroups() {
    document.querySelectorAll('[data-chip-group]').forEach((group) => {
        const buttons = group.querySelectorAll('[data-chip-value]');
        const activeClass = group.getAttribute('data-chip-active') || 'bg-black text-white';
        const inactiveClass = group.getAttribute('data-chip-inactive') || 'bg-soft-gray text-neutral-800';
        buttons.forEach((btn) => {
            btn.addEventListener('click', () => {
                buttons.forEach((b) => { b.className = b.className.replace(/\S+/g, (c) => c); });
                buttons.forEach((b) => setChipState(b, b === btn, activeClass, inactiveClass));
            });
        });
        const resetBtn = document.querySelector('[data-chip-reset="' + group.getAttribute('data-chip-group') + '"]');
        resetBtn?.addEventListener('click', () => buttons.forEach((b, i) => setChipState(b, i === 0, activeClass, inactiveClass)));
    });
}
function setChipState(btn, active, activeClass, inactiveClass) {
    activeClass.split(' ').forEach((c) => btn.classList.toggle(c, active));
    inactiveClass.split(' ').forEach((c) => btn.classList.toggle(c, !active));
}

/* ------------------------------ View toggle --------------------------- */
function initViewToggle() {
    document.querySelectorAll('[data-view-toggle]').forEach((group) => {
        const container = document.querySelector('[data-view-container]');
        group.querySelectorAll('[data-view]').forEach((btn) => {
            btn.addEventListener('click', () => {
                group.querySelectorAll('[data-view]').forEach((b) => { b.classList.remove('bg-white', 'shadow-xs', 'text-slate-900'); b.classList.add('text-slate-400'); });
                btn.classList.add('bg-white', 'shadow-xs', 'text-slate-900'); btn.classList.remove('text-slate-400');
                if (container) container.classList.toggle('lg:grid-cols-2', btn.getAttribute('data-view') === 'list');
            });
        });
    });
}

/* ------------------------------ Favorites ------------------------------ */
function initFavorites() {
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-favorite-toggle]');
        if (!btn) return;
        e.preventDefault();
        const active = btn.classList.toggle('text-red-500');
        btn.classList.toggle('bg-white/90', !active);
        btn.classList.toggle('bg-red-50', active);
        showToast(active ? 'Ajouté à vos favoris' : 'Retiré de vos favoris');
    });
}

/* ------------------------------- Favorites: clear all ------------------------ */
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-clear-favorites]');
    if (!btn) return;
    if (window.confirm('Voulez-vous retirer tous vos produits favoris ?')) showToast('Favoris vidés (démo front-end).');
});

/* -------------------------------- Cart ---------------------------------- */
let cartCount = 0;
function initCartButtons() {
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-add-to-cart]');
        if (!btn) return;
        e.preventDefault();
        cartCount += 1;
        document.querySelectorAll('[data-cart-count]').forEach((el) => { el.textContent = String(cartCount); });
        document.querySelectorAll('[data-cart-count-badge]').forEach((el) => { el.textContent = String(cartCount); el.classList.remove('hidden'); el.classList.add('flex'); });
        btn.classList.add('scale-90');
        window.setTimeout(() => btn.classList.remove('scale-90'), 150);
        showToast('Article ajouté au panier');
    });
}

/* -------------------------------- Auth ---------------------------------- */
function initAuth() {
    document.querySelectorAll('[data-auth]').forEach((root) => {
        const panels = root.querySelectorAll('[data-auth-panel]');
        const title = root.querySelector('[data-auth-title]');
        const backBtn = root.querySelector('[data-auth-back]');
        const history = [];

        const switchTo = (mode, push = true) => {
            panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-auth-panel') !== mode));
            const active = root.querySelector('[data-auth-panel="' + mode + '"]');
            if (title && active) title.textContent = active.getAttribute('data-title') || 'GoBuy';
            root.querySelectorAll('[data-auth-error]').forEach((e) => e.classList.add('hidden'));
            if (push) history.push(mode);
            backBtn?.classList.toggle('hidden', history.length < 2 && mode === (root.getAttribute('data-auth-initial') || 'login'));
        };

        root.querySelectorAll('[data-auth-switch]').forEach((btn) => btn.addEventListener('click', () => switchTo(btn.getAttribute('data-auth-switch'))));
        backBtn?.addEventListener('click', () => { history.pop(); switchTo(history.pop() || 'login'); });
        window.addEventListener('gb:auth-switch', (e) => switchTo(e.detail));

        root.querySelectorAll('[data-auth-form]').forEach((form) => {
            form.addEventListener('submit', (e) => {
                const kind = form.getAttribute('data-auth-form');
                if (kind === 'login') return;
                e.preventDefault();
                const email = form.querySelector('input[name="email"]')?.value || 'votre adresse';
                const phone = form.querySelector('input[name="phone"]')?.value || '';
                if (kind === 'register') {
                    root.querySelectorAll('[data-auth-email]').forEach((el) => { el.textContent = email; });
                    switchTo('verify_email');
                } else if (kind === 'forgot') {
                    root.querySelectorAll('[data-auth-email]').forEach((el) => { el.textContent = email; });
                    switchTo('reset_password');
                } else if (kind === 'reset') {
                    showToast('Mot de passe réinitialisé (démo front-end — non connecté à un compte réel).');
                    switchTo('login');
                } else {
                    showToast("Connexion : aucun back-end n'est encore branché à ce formulaire.");
                }
                if (phone) root.querySelectorAll('[data-auth-phone]').forEach((el) => { el.textContent = phone; });
            });
        });

        root.querySelectorAll('[data-auth-verify]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const kind = btn.getAttribute('data-auth-verify');
                if (kind === 'email') switchTo('verify_sms');
                else { closeModal(root.closest('[data-modal]') || root); showToast('Compte créé avec succès (démo).'); }
            });
        });

        root.querySelectorAll('[data-auth-social]').forEach((btn) => btn.addEventListener('click', () => showToast('Connexion sociale : bientôt disponible.')));

        switchTo(root.getAttribute('data-auth-initial') || 'login', false);
    });
}

/* ----------------------------- Onboarding -------------------------------- */
function initOnboarding() {
    const modal = document.querySelector('[data-modal="onboarding"]');
    const wrap = document.querySelector('[data-onboarding]');
    if (!modal || !wrap) return;

    const steps = wrap.querySelectorAll('[data-onb-step]');
    let index = 0;
    const show = (i) => steps.forEach((s) => s.classList.toggle('hidden', Number(s.getAttribute('data-onb-step')) !== i));
    wrap.querySelectorAll('[data-onb-next]').forEach((btn) => btn.addEventListener('click', () => { index = Math.min(index + 1, steps.length - 1); show(index); }));

    modal.querySelectorAll('[data-modal-close]').forEach((btn) => btn.addEventListener('click', () => { try { window.localStorage.setItem('gobuy_onboarding_seen', '1'); } catch (e) { /* ignore */ } }));

    let seen = false;
    try { seen = window.localStorage.getItem('gobuy_onboarding_seen') === '1'; } catch (e) { /* ignore */ }
    if (!seen) window.setTimeout(() => openModal('onboarding'), 400);
}

/* ------------------------------- Account --------------------------------- */
function initAccount() {
    document.querySelectorAll('[data-account]').forEach((root) => {
        const sections = root.querySelectorAll('[data-account-section]');
        const go = (name) => sections.forEach((s) => s.classList.toggle('hidden', s.getAttribute('data-account-section') !== name));
        root.querySelectorAll('[data-account-go]').forEach((btn) => btn.addEventListener('click', () => go(btn.getAttribute('data-account-go'))));
        go(root.getAttribute('data-account-initial') || 'main');

        root.querySelectorAll('[data-toggle-2fa]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const on = btn.classList.toggle('bg-emerald-600'); btn.classList.toggle('text-white', on);
                btn.classList.toggle('bg-slate-200', !on); btn.classList.toggle('text-slate-700', !on);
                btn.textContent = on ? 'ACTIVÉ' : 'DÉSACTIVÉ';
            });
        });
    });

    const delInput = document.querySelector('[data-delete-confirm]');
    const delBtn = document.querySelector('[data-delete-submit]');
    if (delInput && delBtn) {
        delInput.addEventListener('input', () => { delBtn.disabled = delInput.value.trim().toUpperCase() !== 'SUPPRIMER'; });
        delBtn.addEventListener('click', () => showToast('Suppression de compte : démo front-end uniquement.'));
    }
}

/* --------------------------- Company info modal ---------------------------- */
function initInfoModal() {
    const root = document.querySelector('[data-info]');
    if (!root) return;
    const tabs = root.querySelectorAll('[data-info-tab]');
    const panels = root.querySelectorAll('[data-info-panel]');
    const switchTo = (id) => {
        panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-info-panel') !== id));
        tabs.forEach((t) => {
            const active = t.getAttribute('data-info-tab') === id;
            t.classList.toggle('bg-white', active); t.classList.toggle('text-black', active); t.classList.toggle('shadow-xs', active);
            t.classList.toggle('text-slate-600', !active);
        });
        root.closest('[data-modal]')?.querySelector('.overflow-y-auto')?.scrollTo?.({ top: 0 });
    };
    tabs.forEach((t) => t.addEventListener('click', () => switchTo(t.getAttribute('data-info-tab'))));
    window.addEventListener('gb:info-switch', (e) => switchTo(e.detail));
    switchTo('about');
}

/* ---- Company info: recruitment quick-apply, promo copy, contact/claim forms ---- */
function initInfoModalForms() {
    const root = document.querySelector('[data-info]');
    if (!root) return;

    root.querySelectorAll('[data-job-select]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const form = root.querySelector('[data-job-form]');
            form.classList.remove('hidden');
            form.querySelector('[data-job-title]').textContent = btn.getAttribute('data-job-select');
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });
    root.querySelector('[data-job-cancel]')?.addEventListener('click', () => root.querySelector('[data-job-form]').classList.add('hidden'));
    root.querySelector('[data-job-form]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        e.target.querySelectorAll('input, textarea, button[type="submit"]').forEach((el) => el.classList.add('hidden'));
        root.querySelector('[data-job-sent]').classList.remove('hidden');
        root.querySelector('[data-job-sent]').classList.add('flex');
    });

    root.querySelectorAll('[data-copy-code]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const code = btn.getAttribute('data-copy-code');
            navigator.clipboard?.writeText(code).catch(() => {});
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="text-emerald-600 font-semibold">Copié</span>';
            window.setTimeout(() => { btn.innerHTML = original; }, 1800);
        });
    });

    root.querySelector('[data-contact-form]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        e.target.querySelectorAll('input, textarea, button[type="submit"]').forEach((el) => el.classList.add('hidden'));
        root.querySelector('[data-contact-sent]').classList.remove('hidden');
        root.querySelector('[data-contact-sent]').classList.add('flex');
    });

    root.querySelector('[data-claim-form]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const ref = 'REC-' + String(Date.now()).slice(-6);
        root.querySelector('[data-claim-ref]').textContent = 'Votre réclamation a bien été transmise à notre service qualité. Référence dossier : ' + ref + '.';
        e.target.querySelectorAll('input, select, textarea, button[type="submit"]').forEach((el) => el.classList.add('hidden'));
        root.querySelector('[data-claim-sent]').classList.remove('hidden');
        root.querySelector('[data-claim-sent]').classList.add('flex');
    });
}

/* ------------------------------- Feedback ---------------------------------- */
function initFeedback() {
    document.querySelectorAll('[data-feedback]').forEach((root) => {
        root.querySelectorAll('[data-mood]').forEach((btn) => {
            btn.addEventListener('click', () => {
                root.querySelectorAll('[data-mood]').forEach((b) => { b.classList.remove('scale-125', 'bg-white', 'shadow-xs'); b.classList.add('opacity-60'); });
                btn.classList.add('scale-125', 'bg-white', 'shadow-xs'); btn.classList.remove('opacity-60');
            });
        });
        root.querySelector('[data-feedback-form]')?.addEventListener('submit', (e) => {
            e.preventDefault();
            root.querySelector('[data-feedback-form]').classList.add('hidden');
            root.querySelector('[data-feedback-done]')?.classList.remove('hidden');
        });
    });
}

/* ------------------------------ Quick tracking ------------------------------ */
function initQuickTracking() {
    document.querySelectorAll('[data-quick-tracking]').forEach((form) => {
        if (!form.getAttribute('action')) return; // page version already has a real GET action
    });
}

/* ------------------------------ Supplier dashboard ----------------------------- */
function initSupplierDashboard() {
    const root = document.querySelector('[data-supplier-dashboard]');
    if (!root) return;
    const tabs = root.querySelectorAll('[data-supplier-tab]');
    const panels = root.querySelectorAll('[data-supplier-panel]');
    const switchTo = (id) => {
        panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-supplier-panel') !== id));
        tabs.forEach((t) => {
            const active = t.getAttribute('data-supplier-tab') === id;
            t.classList.toggle('bg-slate-950', active && id !== 'finances'); t.classList.toggle('bg-emerald-600', active && id === 'finances');
            t.classList.toggle('text-white', active); t.classList.toggle('font-bold', active);
            t.classList.toggle('text-slate-600', !active);
        });
    };
    tabs.forEach((t) => t.addEventListener('click', () => switchTo(t.getAttribute('data-supplier-tab'))));
    switchTo('dashboard');

    const banner = root.querySelector('[data-plan-banner]');
    banner?.querySelector('[data-plan-toggle]')?.addEventListener('click', () => {
        const isStandard = banner.getAttribute('data-plan') === 'standard';
        const next = isStandard ? 'pro' : 'standard';
        banner.setAttribute('data-plan', next);
        banner.classList.toggle('bg-white', next !== 'pro'); banner.classList.toggle('text-slate-800', next !== 'pro'); banner.classList.toggle('border-slate-200', next !== 'pro');
        banner.classList.toggle('bg-gradient-to-r', next === 'pro'); banner.classList.toggle('from-slate-900', next === 'pro'); banner.classList.toggle('to-emerald-950', next === 'pro'); banner.classList.toggle('text-white', next === 'pro'); banner.classList.toggle('border-emerald-500/50', next === 'pro');
        banner.querySelector('[data-plan-badge]').textContent = next === 'pro' ? 'Boutique Pro Certifiée' : 'Formule Standard';
        banner.querySelector('[data-plan-rent]').textContent = next === 'pro' ? 'Loyer : 49 000 Ar / mois' : 'Loyer : 0 Ar / mois';
        banner.querySelector('[data-plan-title]').textContent = next === 'pro' ? 'Vous bénéficiez de -2% de commission et du badge vérifié' : 'Passez en Boutique Pro pour booster vos marges et vos ventes';
        banner.querySelector('[data-plan-desc]').textContent = next === 'pro' ? "Votre boutique est mise en avant prioritairement dans les résultats et vous disposez d'un support prioritaire 7j/7." : "Économisez 20 000 Ar par tranche de 1 000 000 Ar de vente et obtenez le badge Vendeur Officiel.";
        banner.querySelector('[data-plan-toggle]').textContent = next === 'pro' ? 'Gérer ou rétrograder en Standard' : 'Activer la Boutique Pro (49k)';
        root.querySelector('[data-commission-rate]').textContent = next === 'pro' ? '6%' : '8%';
        const msg = root.querySelector('[data-plan-msg]');
        msg.querySelector('[data-plan-msg-text]').textContent = next === 'pro' ? 'Félicitations ! Votre Boutique Pro est activée. Taux de commission réduit de -2%.' : 'Passage à la formule Standard effectué (0 Ar/mois).';
        msg.classList.remove('hidden'); msg.classList.add('flex');
        window.setTimeout(() => { msg.classList.add('hidden'); msg.classList.remove('flex'); }, 4000);
    });

    root.querySelector('[data-save-payout]')?.addEventListener('click', () => {
        const el = root.querySelector('[data-payout-saved]');
        el.classList.remove('hidden'); el.classList.add('flex');
        window.setTimeout(() => { el.classList.add('hidden'); el.classList.remove('flex'); }, 3500);
    });
}

/* -------------------------------- Admin dashboard ------------------------------- */
function initAdminDashboard() {
    const root = document.querySelector('[data-admin-dashboard]');
    if (!root || root.dataset.adminDashboardInitialized === 'true') return;
    root.dataset.adminDashboardInitialized = 'true';
    const tabs = root.querySelectorAll('[data-admin-tab]');
    const panels = root.querySelectorAll('[data-admin-panel]');
    const switchTo = (id) => {
        panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-admin-panel') !== id));
        tabs.forEach((t) => {
            const active = t.getAttribute('data-admin-tab') === id;
            const emerald = id === 'vendor_applications' || id === 'vendor_payouts' || id === 'exports';
            t.classList.toggle('bg-indigo-600', active && !emerald); t.classList.toggle('bg-emerald-600', active && emerald);
            t.classList.toggle('text-white', active); t.classList.toggle('text-slate-600', !active);
        });
    };
    tabs.forEach((t) => t.addEventListener('click', () => switchTo(t.getAttribute('data-admin-tab'))));
    const requestedTab = new URLSearchParams(window.location.search).get('tab');
    switchTo([...panels].some((panel) => panel.getAttribute('data-admin-panel') === requestedTab)
        ? requestedTab
        : 'overview');

    const userSearch = root.querySelector('[data-user-search]');
    const userRoleFilter = root.querySelector('[data-user-role-filter]');
    const userRows = [...root.querySelectorAll('[data-user-row]')];
    const noUserResults = root.querySelector('[data-user-no-results]');
    const filterUsers = () => {
        const searchTerm = userSearch?.value.trim().toLocaleLowerCase() || '';
        const selectedRole = userRoleFilter?.value || 'all';
        let visibleRows = 0;

        for (const row of userRows) {
            const matchesRole = selectedRole === 'all' || row.dataset.userRole === selectedRole;
            const matchesSearch = row.dataset.userSearchText.includes(searchTerm);
            const isVisible = matchesRole && matchesSearch;
            row.classList.toggle('hidden', !isVisible);
            visibleRows += Number(isVisible);
        }

        noUserResults?.classList.toggle('hidden', visibleRows > 0);
    };
    userSearch?.addEventListener('input', filterUsers);
    userRoleFilter?.addEventListener('change', filterUsers);

    root.querySelectorAll('[data-export]').forEach((btn) => {
        btn.addEventListener('click', () => {
            window.location.assign(btn.getAttribute('data-export-url'));
        });
    });
}

/* --------------------------- Symfony inspector modal -------------------------- */
function initInspector() {
    const root = document.querySelector('[data-inspector]');
    if (!root) return;
    const tabs = root.querySelectorAll('[data-inspector-tab]');
    const panels = root.querySelectorAll('[data-inspector-panel]');
    const switchTo = (id) => {
        panels.forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-inspector-panel') !== id));
        tabs.forEach((t) => {
            const active = t.getAttribute('data-inspector-tab') === id;
            t.classList.toggle('bg-emerald-600', active); t.classList.toggle('text-white', active);
            t.classList.toggle('text-slate-400', !active);
        });
    };
    tabs.forEach((t) => t.addEventListener('click', () => switchTo(t.getAttribute('data-inspector-tab'))));
    switchTo('architecture');

    root.querySelector('[data-copy-schema]')?.addEventListener('click', (e) => {
        const btn = e.currentTarget;
        const sql = root.querySelector('[data-schema-sql]')?.textContent || '';
        navigator.clipboard?.writeText(sql).catch(() => {});
        const original = btn.innerHTML;
        btn.innerHTML = '<span class="text-emerald-400">Copié !</span>';
        window.setTimeout(() => { btn.innerHTML = original; }, 2000);
    });
}

/* ---------------------------- Flash countdown -------------------------------- */
function initFlashCountdown() {
    document.querySelectorAll('[data-flash-countdown]').forEach((el) => {
        let remaining = 6 * 3600 + 41 * 60 + 22;
        window.setInterval(() => {
            remaining = remaining > 0 ? remaining - 1 : 24 * 3600 - 1;
            const h = String(Math.floor(remaining / 3600)).padStart(2, '0');
            const m = String(Math.floor((remaining % 3600) / 60)).padStart(2, '0');
            const s = String(remaining % 60).padStart(2, '0');
            el.textContent = 'Fin dans ' + h + ':' + m + ':' + s;
        }, 1000);
    });
}

/* ---------------------------------------------------------------------------
   Checkout — 2-step form, live 3x/4x installment simulator, Mobile Money
   number validation (mirrors the mockup's exact carrier-prefix rules).
--------------------------------------------------------------------------- */
function validateMobileMoney(raw) {
    const clean = (raw || '').replace(/\s+/g, '').replace(/^\+261/, '0');
    if (!clean.startsWith('0')) return { isValid: false, message: 'Le numéro doit débuter par 03x ou +261' };
    if (clean.length !== 10) return { isValid: false, message: 'Le numéro doit comporter 10 chiffres (ex: 034 12 345 67)' };
    const prefix = clean.substring(0, 3);
    if (['034', '038'].includes(prefix)) return { isValid: true, carrier: 'mvola', message: 'Numéro Mvola (Telma) valide' };
    if (prefix === '033') return { isValid: true, carrier: 'airtel', message: 'Numéro Airtel Money valide' };
    if (['032', '037'].includes(prefix)) return { isValid: true, carrier: 'orange', message: 'Numéro Orange Money valide' };
    return { isValid: false, message: 'Opérateur Mobile Money non reconnu (requis: 034, 038, 033, 032, 037)' };
}
function formatAr(n) { return Math.round(n).toLocaleString('fr-FR') + ' Ar'; }

function initCheckout() {
    const root = document.querySelector('[data-checkout]');
    if (!root) return;

    const subtotalBase = Number(root.getAttribute('data-subtotal')) || 0;
    const shippingBase = Number(root.getAttribute('data-shipping')) || 0;
    const hasItems = Number(root.getAttribute('data-items')) > 0;
    const previewInput = root.querySelector('[data-preview-amount]');

    let paymentMethod = 'installments';
    let installments = 3;
    let channel = 'mobile_money';
    let phoneVerified = false;

    const getSubtotal = () => hasItems ? subtotalBase : Number(previewInput?.value || 0);
    const getShipping = () => hasItems ? shippingBase : 0;
    const getTotal = () => getSubtotal() + getShipping();

    /* ---- step navigation ---- */
    const step1 = root.querySelector('[data-step="1"]');
    const step2 = root.querySelector('[data-step="2"]');
    const backBtn = root.querySelector('[data-checkout-back]');
    const goToStep = (n) => {
        step1.classList.toggle('hidden', n !== 1);
        step2.classList.toggle('hidden', n !== 2);
        backBtn?.classList.toggle('hidden', n === 1);
        root.querySelectorAll('[data-step-dot]').forEach((dot) => {
            const active = Number(dot.getAttribute('data-step-dot')) <= n;
            dot.classList.toggle('bg-slate-950', active); dot.classList.toggle('text-white', active);
            dot.classList.toggle('bg-slate-200', !active); dot.classList.toggle('text-slate-500', !active);
        });
        root.querySelector('[data-step-label="2"]')?.classList.toggle('text-slate-400', n !== 2);
        root.querySelector('[data-step-label="2"]')?.classList.toggle('text-slate-950', n === 2);
        if (n === 2) refreshSummary();
    };
    root.querySelector('[data-step1-next]')?.addEventListener('click', () => {
        const nameInput = root.querySelector('input[name="fullName"]');
        const name = nameInput?.value || root.querySelector('[data-sum-name]')?.textContent;
        if (name) root.querySelectorAll('[data-sum-name]').forEach((el) => { el.textContent = name; });
        const phone = root.querySelector('input[name="phone"]')?.value;
        if (phone) { root.querySelectorAll('[data-sum-phone]').forEach((el) => { el.textContent = phone; }); root.querySelector('[data-phone-badge] span')?.remove?.(); }
        const street = root.querySelector('input[name="street"]')?.value; if (street) root.querySelectorAll('[data-sum-street]').forEach((el) => { el.textContent = street; });
        goToStep(2);
    });
    backBtn?.addEventListener('click', () => goToStep(1));
    root.querySelector('[data-address-edit]')?.addEventListener('click', () => {
        root.querySelector('[data-address-summary]')?.classList.toggle('hidden');
        root.querySelector('[data-address-form]')?.classList.toggle('hidden');
    });

    /* ---- payment method selection ---- */
    const payCards = root.querySelectorAll('[data-pay]');
    const selectPayment = (method) => {
        paymentMethod = method;
        payCards.forEach((card) => {
            const active = card.getAttribute('data-pay') === method;
            card.classList.toggle('border-slate-950', active); card.classList.toggle('shadow-sm', active); card.classList.toggle('ring-1', active); card.classList.toggle('ring-slate-950', active);
            card.classList.toggle('border-slate-200', !active);
            const radio = card.querySelector('[data-radio]');
            if (radio) radio.innerHTML = active ? '<span class="h-2 w-2 rounded-full bg-white"></span>' : '';
            radio?.classList.toggle('bg-slate-950', active); radio?.classList.toggle('border-slate-950', active); radio?.classList.toggle('border-slate-300', !active);
            card.querySelector('[data-pay-body]')?.classList.toggle('hidden', !active);
        });
        refreshSummary();
    };
    payCards.forEach((card) => card.addEventListener('click', (e) => { if (!e.target.closest('input, [data-momo-input], button[data-channel], button[data-installments]')) selectPayment(card.getAttribute('data-pay')); }));

    /* ---- installments 3x / 4x ---- */
    const instButtons = root.querySelectorAll('[data-installments]');
    const setInstallments = (n) => {
        installments = n;
        instButtons.forEach((b) => {
            const active = Number(b.getAttribute('data-installments')) === n;
            b.classList.toggle('border-slate-950', active); b.classList.toggle('bg-slate-950', active); b.classList.toggle('text-white', active); b.classList.toggle('shadow-xs', active);
            b.classList.toggle('border-slate-200', !active); b.classList.toggle('bg-slate-50', !active); b.classList.toggle('text-slate-800', !active);
        });
        root.querySelectorAll('[data-inst-count]').forEach((el) => { el.textContent = String(n); });
        refreshSummary();
    };
    instButtons.forEach((b) => b.addEventListener('click', () => setInstallments(Number(b.getAttribute('data-installments')))));

    /* ---- collection channel ---- */
    root.querySelectorAll('[data-channel]').forEach((btn) => {
        btn.addEventListener('click', () => {
            channel = btn.getAttribute('data-channel');
            root.querySelectorAll('[data-channel]').forEach((b) => {
                const active = b === btn;
                b.classList.toggle('border-slate-950', active); b.classList.toggle('bg-slate-950', active); b.classList.toggle('text-white', active);
                b.classList.toggle('border-slate-200', !active); b.classList.toggle('bg-white', !active); b.classList.toggle('text-slate-700', !active);
            });
            root.querySelectorAll('[data-channel-panel]').forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-channel-panel') !== channel));
        });
    });

    /* ---- mobile money live validation ---- */
    root.querySelectorAll('[data-momo-input]').forEach((input) => {
        const badge = input.parentElement.querySelector('[data-momo-badge]');
        const msg = input.parentElement.parentElement.querySelector('[data-momo-msg]');
        input.addEventListener('input', () => {
            if (!input.value) { badge.classList.add('hidden'); if (msg) { msg.textContent = 'ℹ Saisissez votre numéro Mvola (034/038), Airtel (033) ou Orange (032).'; msg.className = 'text-[11px] text-slate-500'; } return; }
            const res = validateMobileMoney(input.value);
            if (res.isValid) { badge.textContent = res.carrier.toUpperCase(); badge.classList.remove('hidden'); if (msg) { msg.textContent = '✓ ' + res.message; msg.className = 'text-[11px] font-semibold text-emerald-600'; } }
            else { badge.classList.add('hidden'); if (msg) { msg.textContent = '✕ ' + res.message; msg.className = 'text-[11px] font-semibold text-red-500'; } }
        });
    });

    /* ---- summary / schedule refresh ---- */
    function refreshSummary() {
        const subtotal = getSubtotal(), shipping = getShipping(), total = getTotal();
        root.querySelectorAll('[data-sum-subtotal]').forEach((el) => { el.textContent = subtotal.toLocaleString('fr-FR'); });
        root.querySelectorAll('[data-sum-shipping]').forEach((el) => { el.textContent = shipping.toLocaleString('fr-FR'); });
        root.querySelectorAll('[data-sum-total]').forEach((el) => { el.textContent = total.toLocaleString('fr-FR'); });

        [3, 4].forEach((n) => {
            const monthly = total / n;
            root.querySelectorAll('[data-inst-monthly="' + n + '"]').forEach((el) => { el.textContent = Math.round(monthly).toLocaleString('fr-FR'); });
        });

        const scheduleEl = root.querySelector('[data-schedule]');
        if (scheduleEl) {
            const monthly = total / installments;
            let html = '';
            for (let i = 1; i <= installments; i += 1) {
                const d = new Date(); d.setMonth(d.getMonth() + (i - 1));
                const label = i === 1 ? "Aujourd'hui" : (i + 'e prélèvement');
                html += '<div class="flex items-center justify-between rounded-xl border border-slate-200/80 bg-white p-2 text-[11px]">'
                    + '<div class="flex items-center gap-2"><span class="flex h-4 w-4 items-center justify-center rounded-full font-mono text-[9px] font-bold ' + (i === 1 ? 'bg-slate-950 text-[#00E5A3]' : 'bg-slate-100 text-slate-600') + '">' + (i === 1 ? '✓' : i) + '</span>'
                    + '<div><span class="font-semibold text-slate-800">' + label + '</span><span class="ml-1.5 text-slate-400">(' + d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' }) + ')</span></div></div>'
                    + '<span class="font-mono font-bold text-slate-900">' + Math.round(monthly).toLocaleString('fr-FR') + ' Ar</span></div>';
            }
            scheduleEl.innerHTML = html;
            root.querySelectorAll('[data-total-text]').forEach((el) => { el.textContent = Math.round(total).toLocaleString('fr-FR'); });
        }

        const recap = root.querySelector('[data-installment-recap]');
        if (paymentMethod === 'installments') {
            const first = total / installments;
            recap?.classList.remove('hidden');
            root.querySelectorAll('[data-first-payment]').forEach((el) => { el.textContent = formatAr(first); });
            root.querySelectorAll('[data-remaining]').forEach((el) => { el.textContent = (installments - 1) + ' x ' + formatAr(first); });
            root.querySelectorAll('[data-confirm-label]').forEach((el) => { el.textContent = 'Payer ' + formatAr(first) + ' aujourd\'hui'; });
        } else {
            recap?.classList.add('hidden');
            root.querySelectorAll('[data-confirm-label]').forEach((el) => { el.textContent = paymentMethod === 'cash_on_delivery' ? 'Confirmer la commande' : 'Payer ' + formatAr(total); });
        }
    }
    previewInput?.addEventListener('input', refreshSummary);

    /* ---- phone verification (SMS OTP) ---- */
    const phoneModal = root.querySelector('[data-phone-modal]');
    const openPhoneModal = () => { phoneModal.classList.remove('hidden', 'pointer-events-none'); phoneModal.classList.add('flex'); };
    const closePhoneModal = () => { phoneModal.classList.add('hidden'); phoneModal.classList.remove('flex'); };
    root.querySelector('[data-phone-reminder]')?.addEventListener('click', openPhoneModal);
    root.querySelector('[data-otp-request]')?.addEventListener('click', () => {
        root.querySelector('[data-otp-send]')?.classList.add('hidden');
        root.querySelector('[data-otp-verify]')?.classList.remove('hidden');
        const phone = root.querySelector('input[name="phone"]')?.value || '—';
        root.querySelectorAll('[data-otp-phone]').forEach((el) => { el.textContent = phone; });
    });
    root.querySelector('[data-otp-cancel]')?.addEventListener('click', closePhoneModal);
    root.querySelector('[data-otp-confirm]')?.addEventListener('click', () => {
        const val = root.querySelector('[data-otp-input]')?.value || '';
        if (val.length !== 6) { const err = root.querySelector('[data-otp-error]'); if (err) { err.textContent = 'Veuillez saisir les 6 chiffres du code reçu.'; err.classList.remove('hidden'); } return; }
        phoneVerified = true;
        root.querySelector('[data-phone-badge]').innerHTML = '✓ Tél. vérifié';
        root.querySelector('[data-phone-badge]').className = 'inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700';
        root.querySelector('[data-phone-reminder]')?.classList.add('hidden');
        closePhoneModal();
        showToast('Numéro vérifié avec succès.');
    });

    /* ---- confirm order ---- */
    root.querySelector('[data-confirm-order]')?.addEventListener('click', () => {
        if ((paymentMethod === 'installments' || paymentMethod === 'mobile_money') && channel !== 'card') {
            const input = root.querySelector(paymentMethod === 'installments' ? '[data-momo-input="installments"]' : '[data-momo-input="mobile_money"]');
            const res = validateMobileMoney(input?.value || '');
            if (!res.isValid) { showToast('Numéro Mobile Money invalide : ' + res.message); input?.focus(); return; }
        }
        if (!phoneVerified) { openPhoneModal(); return; }
        const notice = root.querySelector('[data-checkout-notice]');
        if (notice) { notice.textContent = "Commande simulée avec succès — aucun back-end n'est encore connecté pour l'enregistrer."; notice.classList.remove('hidden'); }
        showToast('Commande confirmée (démo front-end).');
    });

    goToStep(1);
    selectPayment('installments');
    setInstallments(3);
}

/* ---------------------------------------------------------------------------
   Become a vendor — landing/form/success view switch + 4-step wizard +
   live commission/revenue simulator (real arithmetic, no fictional data).
--------------------------------------------------------------------------- */
function initVendorForm() {
    const page = document.querySelector('[data-vendor-page]');
    if (!page) return;

    const showView = (name) => page.querySelectorAll('[data-vendor-view]').forEach((v) => v.classList.toggle('hidden', v.getAttribute('data-vendor-view') !== name));
    page.querySelectorAll('[data-vendor-open-form]').forEach((btn) => btn.addEventListener('click', () => {
        showView('form');
        goToVendorStep(Number(btn.getAttribute('data-vendor-open-form')) || 1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (btn.hasAttribute('data-vendor-solidarity')) page.querySelector('[data-damage-yes]').checked = true;
    }));
    page.querySelector('[data-vendor-back]')?.addEventListener('click', () => showView('landing'));

    let currentStep = 1;
    function goToVendorStep(n) {
        currentStep = n;
        page.querySelectorAll('[data-vendor-step]').forEach((s) => s.classList.toggle('hidden', Number(s.getAttribute('data-vendor-step')) !== n));
        page.querySelectorAll('[data-vendor-step-dot]').forEach((dot) => {
            const i = Number(dot.getAttribute('data-vendor-step-dot'));
            dot.classList.toggle('text-slate-950', i <= n);
            dot.querySelector('span').classList.toggle('bg-slate-950', i <= n);
            dot.querySelector('span').classList.toggle('text-white', i <= n);
            dot.querySelector('span').classList.toggle('bg-slate-200', i > n);
            dot.querySelector('span').classList.toggle('text-slate-500', i > n);
        });
        page.querySelector('[data-vendor-prev]')?.classList.toggle('hidden', n === 1);
        page.querySelector('[data-vendor-next]')?.classList.toggle('hidden', n === 4);
        page.querySelector('[data-vendor-submit]')?.classList.toggle('hidden', n !== 4);
    }
    page.querySelector('[data-vendor-next]')?.addEventListener('click', () => goToVendorStep(Math.min(currentStep + 1, 4)));
    page.querySelector('[data-vendor-prev]')?.addEventListener('click', () => goToVendorStep(Math.max(currentStep - 1, 1)));

    page.querySelector('[data-damage-yes]')?.addEventListener('change', () => page.querySelector('[data-damage-fields]')?.classList.remove('hidden'));
    page.querySelector('[data-damage-no]')?.addEventListener('change', () => page.querySelector('[data-damage-fields]')?.classList.add('hidden'));

    page.querySelector('[data-vendor-form]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        showView('success');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /* Revenue simulator */
    const sim = page.querySelector('[data-simulator]');
    if (sim) {
        const proRent = Number(sim.getAttribute('data-pro-rent')) || 49000;
        const catButtons = sim.querySelectorAll('[data-sim-category]');
        const revenueInput = sim.querySelector('[data-sim-revenue]');
        let standardRate = Number(catButtons[0]?.getAttribute('data-standard')) / 100 || 0.04;
        let proRate = Number(catButtons[0]?.getAttribute('data-pro')) / 100 || 0.02;

        const refresh = () => {
            const revenue = Number(revenueInput.value);
            sim.querySelector('[data-sim-revenue-label]').textContent = revenue.toLocaleString('fr-FR') + ' Ar';
            const stdCommission = revenue * standardRate;
            const proCommission = revenue * proRate;
            const netStd = revenue - stdCommission;
            const netPro = revenue - proCommission - proRent;
            sim.querySelector('[data-sim-standard-rate]').textContent = Math.round(standardRate * 100) + '%';
            sim.querySelector('[data-sim-pro-rate]').textContent = Math.round(proRate * 100) + '%';
            sim.querySelector('[data-sim-standard-commission]').textContent = '-' + Math.round(stdCommission).toLocaleString('fr-FR') + ' Ar';
            sim.querySelector('[data-sim-pro-commission]').textContent = '-' + Math.round(proCommission).toLocaleString('fr-FR') + ' Ar';
            sim.querySelector('[data-sim-standard-net]').textContent = Math.round(netStd).toLocaleString('fr-FR') + ' Ar';
            sim.querySelector('[data-sim-pro-net]').textContent = Math.round(netPro).toLocaleString('fr-FR') + ' Ar';
            const advantage = netPro - netStd;
            const advEl = sim.querySelector('[data-sim-pro-advantage]');
            if (advantage > 0) { advEl.textContent = 'Gain supplémentaire Pro : +' + Math.round(advantage).toLocaleString('fr-FR') + ' Ar / mois'; advEl.classList.remove('hidden'); }
            else advEl.classList.add('hidden');
        };
        catButtons.forEach((btn) => btn.addEventListener('click', () => {
            catButtons.forEach((b) => { b.classList.remove('border-emerald-600', 'bg-emerald-50', 'font-bold', 'text-emerald-950', 'shadow-xs'); b.classList.add('border-slate-200', 'bg-slate-50/50', 'text-slate-600'); });
            btn.classList.add('border-emerald-600', 'bg-emerald-50', 'font-bold', 'text-emerald-950', 'shadow-xs'); btn.classList.remove('border-slate-200', 'bg-slate-50/50', 'text-slate-600');
            standardRate = Number(btn.getAttribute('data-standard')) / 100;
            proRate = Number(btn.getAttribute('data-pro')) / 100;
            refresh();
        }));
        revenueInput?.addEventListener('input', refresh);
        refresh();
    }
}
