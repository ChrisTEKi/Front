import './styles/app.css';
import { initAdminDashboard } from './admin.js';
import { initAuth } from './auth.js';
import { initAccount } from './account.js';
import { initAddresses } from './addresses.js';
import { initAvatar } from './avatar.js';
import { initContactChange } from './contact.js';
import { initCart } from './cart.js';
import { initCheckout } from './checkout.js';
import { goTo, postJson } from './api.js';
import { closeModal, openModal, showToast } from './ui.js';
import { formatAr, formatNumber, t } from './i18n.js';

/**
 * GoBuy — interactions du navigateur (JavaScript simple, sans framework).
 *
 * Tout est piloté par de petits attributs `data-*` lus dans les gabarits Twig. Ce fichier contient les éléments d'interface
 * communs (tiroirs, menus, fenêtres, filtres…) ; chaque écran qui parle au serveur a son module :
 *   auth.js      connexion / inscription (étapes décidées par le serveur) ;
 *   account.js   réglages du compte (mot de passe, 2FA, téléphone, suppression) ;
 *   contact.js   changement d'e-mail / de téléphone (code à l'ancienne adresse, puis à la nouvelle) ;
 *   avatar.js    photo de profil ;   addresses.js   carnet d'adresses de livraison ;
 *   cart.js      panier et favoris (mémorisés dans le navigateur) ;
 *   checkout.js  commande : vérification du téléphone et du Mobile Money, validation ;
 *   admin.js     espace administrateur.
 * Les textes affichés par le JavaScript viennent du dictionnaire « js » (i18n.js), jamais écrits en dur ici.
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
    initViewToggle();
    initCart();
    initAuth();
    initOnboarding();
    initAccount();
    initContactChange();
    initAvatar();
    initAddresses();
    initInfoModal();
    initFeedback();
    initSupportForms();
    initCheckout();
    initVendorForm();
    initFlashCountdown();
    initSupplierDashboard();
    initAdminDashboard();
});

/* ----------------------------- Bulles d'information ----------------------------- */
// Tout élément portant data-alert="message" affiche ce message dans une bulle au clic.
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
            const city = btn.getAttribute('data-city-option');
            document.querySelectorAll('[data-city-label]').forEach((el) => { el.textContent = city; });
            showToast(t('header.city_updated', { city }));
        });
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

/* ------------------------------ Messages au support ------------------------------
 * Formulaires du centre d'information (contact, réclamation, candidature) et tiroir « Feedback » : tous envoient un VRAI
 * message au serveur (POST /support/message → e-mail vers l'équipe). Ils portent l'attribut data-support-form="<type>"
 * (feedback | contact | claim | job) et ne sont affichés par les gabarits que si l'adresse du support est configurée.
 * Le serveur est le seul juge : on affiche ses messages d'erreur (traduits) et son message de réussite (traduit).
 */
function initSupportForms() {
    document.querySelectorAll('form[data-support-form]').forEach((form) => {
        const kind = form.getAttribute('data-support-form');
        const errorBox = form.querySelector('[data-support-error]');
        const sentBox = form.querySelector('[data-support-sent]');
        const submit = form.querySelector('button[type="submit"]');
        const showError = (text) => {
            if (!errorBox) return;
            errorBox.textContent = text;
            errorBox.classList.toggle('hidden', text === '');
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            showError('');
            submit.disabled = true;

            // Champs saisis (nom, contact, message, commande, motif) + l'humeur choisie pour un avis.
            const payload = { kind };
            new FormData(form).forEach((value, key) => { payload[key] = String(value); });
            const mood = form.querySelector('[data-mood][aria-pressed="true"]');
            if (mood) payload.mood = mood.getAttribute('data-mood');

            const result = await postJson('/support/message', payload);
            submit.disabled = false;

            if (result.ok) {
                form.reset();
                // Avis : le tiroir affiche son propre remerciement ; autres formulaires : message de réussite du serveur.
                if (kind === 'feedback') {
                    form.classList.add('hidden');
                    form.closest('[data-feedback]')?.querySelector('[data-feedback-done]')?.classList.remove('hidden');
                } else if (sentBox) {
                    sentBox.querySelector('[data-support-sent-text]').textContent = result.body?.message ?? '';
                    sentBox.classList.remove('hidden');
                    sentBox.classList.add('flex');
                    window.setTimeout(() => { sentBox.classList.add('hidden'); sentBox.classList.remove('flex'); }, 8000);
                }
                return;
            }

            if (result.network || result.expired) {
                showError(t('support.network'));
            } else if (result.body?.errors) {
                // Erreurs de validation : une ligne par message, sans doublon.
                showError([...new Set(Object.values(result.body.errors).flat())].join(' '));
            } else {
                showError(result.body?.message ?? t('support.network'));
            }
        });
    });
}

/* ------------------------------- Feedback ---------------------------------- */
function initFeedback() {
    document.querySelectorAll('[data-feedback]').forEach((root) => {
        const moods = root.querySelectorAll('[data-mood]');
        moods.forEach((btn) => {
            // Un seul smiley choisi à la fois ; recliquer dessus le désélectionne (l'humeur est facultative).
            btn.setAttribute('aria-pressed', 'false');
            btn.addEventListener('click', () => {
                const wasSelected = btn.getAttribute('aria-pressed') === 'true';
                moods.forEach((b) => { b.setAttribute('aria-pressed', 'false'); b.classList.remove('scale-125', 'bg-white', 'shadow-xs'); b.classList.add('opacity-60'); });
                if (!wasSelected) {
                    btn.setAttribute('aria-pressed', 'true');
                    btn.classList.add('scale-125', 'bg-white', 'shadow-xs'); btn.classList.remove('opacity-60');
                }
            });
        });
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

    // Histogramme des ventes : bascule entre « chiffre d'affaires » et « nombre de commandes » (les deux séries sont déjà dans la page).
    const metricButtons = root.querySelectorAll('[data-chart-metric]');
    metricButtons.forEach((btn) => btn.addEventListener('click', () => {
        const metric = btn.getAttribute('data-chart-metric');
        root.querySelectorAll('[data-chart-series]').forEach((series) => {
            const on = series.getAttribute('data-chart-series') === metric;
            series.classList.toggle('hidden', !on);
            series.classList.toggle('flex', on);
        });
        metricButtons.forEach((b) => {
            const on = b === btn;
            b.classList.toggle('bg-white', on); b.classList.toggle('text-slate-950', on); b.classList.toggle('shadow-xs', on); b.classList.toggle('font-black', on);
            b.classList.toggle('text-slate-600', !on); b.classList.toggle('font-bold', !on);
        });
    }));
}

/* ---------------------------- Flash countdown -------------------------------- */
/**
 * Compte à rebours des offres flash : elles prennent fin à minuit, heure de Madagascar (UTC+3, sans heure d'été).
 * Le temps restant est calculé à partir de l'heure réelle, pas d'un chiffre inventé.
 */
function initFlashCountdown() {
    const els = document.querySelectorAll('[data-flash-countdown]');
    if (els.length === 0) return;
    const MADAGASCAR_OFFSET_MS = 3 * 3600 * 1000;
    const tick = () => {
        const local = Date.now() + MADAGASCAR_OFFSET_MS;
        const remaining = 86400 - Math.floor((local % 86400000) / 1000);
        const h = String(Math.floor(remaining / 3600)).padStart(2, '0');
        const m = String(Math.floor((remaining % 3600) / 60)).padStart(2, '0');
        const sec = String(remaining % 60).padStart(2, '0');
        els.forEach((el) => { el.textContent = t('flash.ends_in', { time: h + ':' + m + ':' + sec }); });
    };
    tick();
    window.setInterval(tick, 1000);
}

/* ---------------------------------------------------------------------------
   Devenir partenaire — bascule présentation / formulaire + assistant en 4 étapes + simulateur
   de commissions (calcul réel, aucune donnée fictive).
   À l'envoi, les réponses partent vers l'inscription fournisseur (POST /auth/register/supplier) : le compte et le
   dossier sont créés en base, puis la personne est envoyée vers /connexion pour vérifier son e-mail et son téléphone.
--------------------------------------------------------------------------- */
function initVendorForm() {
    const page = document.querySelector('[data-vendor-page]');
    if (!page) return;

    const showView = (name) => page.querySelectorAll('[data-vendor-view]').forEach((v) => v.classList.toggle('hidden', v.getAttribute('data-vendor-view') !== name));
    page.querySelectorAll('[data-vendor-open-form]').forEach((btn) => btn.addEventListener('click', () => {
        showView('form');
        goToVendorStep(Number(btn.getAttribute('data-vendor-open-form')) || 1);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (btn.hasAttribute('data-vendor-solidarity')) { page.querySelector('[data-damage-yes]').checked = true; syncDamageFields(); }
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
    page.querySelector('[data-vendor-prev]')?.addEventListener('click', () => goToVendorStep(Math.max(currentStep - 1, 1)));

    // ---- Programme « Reconstruisons Ensemble » : les justificatifs n'apparaissent que si la réponse est « Oui ». ----
    const damageFields = page.querySelector('[data-damage-fields]');
    const syncDamageFields = () => damageFields?.classList.toggle('hidden', !page.querySelector('[data-damage-yes]')?.checked);
    page.querySelector('[data-damage-yes]')?.addEventListener('change', syncDamageFields);
    page.querySelector('[data-damage-no]')?.addEventListener('change', syncDamageFields);
    syncDamageFields();

    const form = page.querySelector('[data-vendor-form]');
    const errorBox = form?.querySelector('[data-vendor-error]');
    const submitButton = form?.querySelector('[data-vendor-submit]');

    const showFormError = (message) => {
        if (!errorBox) return;
        errorBox.querySelector('[data-vendor-error-text]').textContent = message;
        errorBox.classList.remove('hidden');
        errorBox.classList.add('flex');
    };
    const clearFormErrors = () => {
        errorBox?.classList.add('hidden');
        errorBox?.classList.remove('flex');
        form?.querySelectorAll('[data-field-error]').forEach((p) => { p.classList.add('hidden'); p.textContent = ''; });
    };
    const showFieldError = (name, message) => {
        const target = form.querySelector('[data-field-error="' + name + '"]');
        if (!target) return false;
        target.textContent = message;
        target.classList.remove('hidden');
        return true;
    };

    /** Premier champ obligatoire invalide (vide, ou e-mail mal formé) d'une étape, ou undefined. */
    const firstInvalid = (n) => [...form.querySelectorAll('[data-vendor-step="' + n + '"] [required]')].find((el) => !el.checkValidity());
    const flagInvalid = (el) => {
        showFieldError(el.name, el.validationMessage || t('vendor.form.required'));
        el.focus({ preventScroll: false });
    };

    page.querySelector('[data-vendor-next]')?.addEventListener('click', () => {
        clearFormErrors();
        const bad = firstInvalid(currentStep);
        if (bad) { flagInvalid(bad); return; }
        goToVendorStep(Math.min(currentStep + 1, 4));
    });

    /** Valeur texte d'un champ nommé (espaces retirés). */
    const text = (name) => (form.elements.namedItem(name)?.value ?? '').trim();
    /** Valeurs cochées d'un groupe de cases / boutons radio. */
    const checked = (name) => [...form.querySelectorAll('input[name="' + name + '"]:checked')].map((i) => i.value);

    /** Construit le JSON attendu par RegisterSupplierData : champs du compte + objet « application » (voir SupplierApplication). */
    const buildPayload = () => {
        const damage = checked('damage')[0] === 'yes';
        return {
            fullName: text('fullName'),
            email: text('email'),
            phone: text('phone'),
            // Le mot de passe n'est JAMAIS « trimé » : les espaces en font partie.
            password: form.elements.namedItem('password').value,
            companyName: text('companyName'),
            companyAddress: text('companyAddress'),
            companyDescription: text('companyDescription'),
            application: {
                contactRole: text('contactRole'), city: text('city'), activityType: text('activityType'), taxId: text('taxId'),
                mainCategory: text('mainCategory'), plan: checked('plan')[0] ?? '',
                sector: text('sector'), productTypes: checked('productTypes'), skuRange: text('skuRange'),
                prepTime: text('prepTime'), salesPoints: checked('salesPoints'), ownDelivery: checked('ownDelivery')[0] === 'yes', stockTool: text('stockTool'),
                damage, proofs: damage ? checked('proofs') : [], expectations: text('expectations'),
            },
        };
    };

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearFormErrors();

        // Tous les champs obligatoires sont vérifiés avant l'envoi ; on ramène la personne à la première étape incomplète.
        for (let n = 1; n <= 4; n += 1) {
            const bad = firstInvalid(n);
            if (bad) { goToVendorStep(n); flagInvalid(bad); return; }
        }

        submitButton.disabled = true;
        submitButton.textContent = t('vendor.form.sending');
        const result = await postJson(page.getAttribute('data-vendor-endpoint'), buildPayload());
        const body = result.body;

        if (result.ok && body && body.step && body.step !== 'login') {
            // Le compte est créé et le parcours de vérification (e-mail, puis téléphone) est ouvert côté serveur :
            // on continue sur la page de connexion, qui reprend exactement à l'étape en cours.
            goTo(page.getAttribute('data-vendor-next-url'), '/connexion');
            return;
        }

        submitButton.disabled = false;
        submitButton.textContent = submitButton.getAttribute('data-label');

        if (result.network) { showFormError(t('net.error')); return; }
        if (body?.errors) {
            // Les champs en erreur du serveur sont tous à l'étape 1 : on y retourne et on les signale un par un.
            goToVendorStep(1);
            let general = '';
            Object.entries(body.errors).forEach(([field, messages]) => {
                const msg = Array.isArray(messages) ? messages.join(' ') : String(messages);
                if (!showFieldError(field, msg)) general += (general ? ' ' : '') + msg;
            });
            showFormError(general || t('vendor.form.fix'));
            return;
        }
        showFormError(body?.message || t('net.error'));
    });
    if (submitButton) submitButton.setAttribute('data-label', submitButton.textContent);

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
            sim.querySelector('[data-sim-revenue-label]').textContent = formatAr(revenue);
            const stdCommission = revenue * standardRate;
            const proCommission = revenue * proRate;
            const netStd = revenue - stdCommission;
            const netPro = revenue - proCommission - proRent;
            sim.querySelector('[data-sim-standard-rate]').textContent = Math.round(standardRate * 100) + '%';
            sim.querySelector('[data-sim-pro-rate]').textContent = Math.round(proRate * 100) + '%';
            sim.querySelector('[data-sim-standard-commission]').textContent = '-' + formatAr(stdCommission);
            sim.querySelector('[data-sim-pro-commission]').textContent = '-' + formatAr(proCommission);
            sim.querySelector('[data-sim-standard-net]').textContent = formatAr(netStd);
            sim.querySelector('[data-sim-pro-net]').textContent = formatAr(netPro);
            const advantage = netPro - netStd;
            const advEl = sim.querySelector('[data-sim-pro-advantage]');
            if (advantage > 0) { advEl.textContent = t('vendor.sim.advantage', { amount: formatAr(advantage) }); advEl.classList.remove('hidden'); }
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
