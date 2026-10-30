import { goTo, postJson } from './api.js';
import { cartCount, cartSubtotal, clearCart, deliveryFee, readCart } from './cart.js';
import { formatAr, formatNumber, formatShortDate, t } from './i18n.js';
import { showToast } from './ui.js';

/**
 * Page de commande : adresse → paiement → validation.
 *
 * Ce que le navigateur fait : afficher le récapitulatif (indicatif), contrôler le FORMAT du numéro Mobile Money, conduire la
 * vérification par SMS (téléphone du compte, numéro Mobile Money) et envoyer la commande.
 * Ce que le serveur fait (et re-vérifie toujours, voir OrderService) : relire le panier et recalculer les prix depuis le catalogue,
 * refuser un téléphone ou un numéro Mobile Money non vérifié, appliquer les plafonds, enregistrer la commande.
 * Aucun prix, total ni statut « vérifié » envoyé par le navigateur n'est cru.
 *
 * Aucune donnée de carte bancaire n'est saisie ici.
 */

/** Règles de format Mobile Money (miroir de MobileMoneyNumberValidator côté serveur, qui fait foi). */
function validateMobileMoney(raw) {
    const clean = (raw || '').replace(/\s+/g, '').replace(/^\+261/, '0');
    if (!clean.startsWith('0')) return { valid: false, message: t('momo.err.prefix') };
    if (!/^\d{10}$/.test(clean)) return { valid: false, message: t('momo.err.length') };
    const prefix = clean.substring(0, 3);
    if (['034', '038'].includes(prefix)) return { valid: true, carrier: 'mvola', message: t('momo.ok.mvola') };
    if (prefix === '033') return { valid: true, carrier: 'airtel', message: t('momo.ok.airtel') };
    if (['032', '037'].includes(prefix)) return { valid: true, carrier: 'orange', message: t('momo.ok.orange') };

    return { valid: false, message: t('momo.err.carrier') };
}

/** Numéro normalisé (« 0341234567 ») : sert de clé pour mémoriser les numéros déjà vérifiés pendant cette visite. */
const normalize = (raw) => (raw || '').replace(/\s+/g, '').replace(/^\+261/, '0');

/** Texte d'erreur d'une réponse : message du serveur (traduit), sinon message générique. */
function errorText(result) {
    if (result.network) return t('net.error');
    if (result.expired) return t('session.expired');
    const details = result.body?.errors ? Object.values(result.body.errors).flat().join(' ') : '';

    return [result.body?.message, details].filter(Boolean).join(' ') || t('net.error');
}

function show(node, text) {
    if (!node) return;
    if (text !== undefined) node.textContent = text;
    node.classList.remove('hidden');
}
const hide = (node) => node?.classList.add('hidden');

export function initCheckout() {
    const root = document.querySelector('[data-checkout]');
    if (!root) return;

    const ep = JSON.parse(root.getAttribute('data-endpoints') || '{}');
    const previewInput = root.querySelector('[data-preview-amount]');

    let paymentMethod = 'installments';
    let installments = 3;
    let channel = 'mobile_money';
    let phoneVerified = root.getAttribute('data-phone-verified') === '1';
    const verifiedMomo = new Set(); // numéros déjà vérifiés pendant cette visite
    let idempotencyKey = (window.crypto?.randomUUID?.() ?? String(Date.now()) + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9_-]/g, '');
    let placing = false;

    // ------------------------------------------------------------------ Montants (indicatifs : le serveur recalcule)

    const lines = () => readCart();
    const hasItems = () => lines().length > 0;
    const getSubtotal = () => (hasItems() ? cartSubtotal() : Number(previewInput?.value || 0));
    const getShipping = () => (hasItems() ? deliveryFee() : 0);
    const getTotal = () => getSubtotal() + getShipping();

    // ------------------------------------------------------------------ Étapes

    const step1 = root.querySelector('[data-step="1"]');
    const step2 = root.querySelector('[data-step="2"]');
    const backBtn = root.querySelector('[data-checkout-back]');
    const goToStep = (n) => {
        step1.classList.toggle('hidden', n !== 1);
        step2.classList.toggle('hidden', n !== 2);
        backBtn?.classList.toggle('hidden', n === 1);
        root.querySelectorAll('[data-step-dot]').forEach((dot) => {
            const active = Number(dot.getAttribute('data-step-dot')) <= n;
            dot.classList.toggle('bg-slate-950', active);
            dot.classList.toggle('text-white', active);
            dot.classList.toggle('bg-slate-200', !active);
            dot.classList.toggle('text-slate-500', !active);
        });
        root.querySelector('[data-step-label="2"]')?.classList.toggle('text-slate-400', n !== 2);
        root.querySelector('[data-step-label="2"]')?.classList.toggle('text-slate-950', n === 2);
        if (n === 2) refreshSummary();
    };

    const field = (name) => root.querySelector('[data-address-form] [name="' + name + '"]');
    const addressValue = (name) => (field(name)?.value ?? '').trim();

    root.querySelector('[data-step1-next]')?.addEventListener('click', () => {
        const error = root.querySelector('[data-address-error]');
        // Contrôle de saisie pour guider la personne ; le serveur re-valide tout (PlaceOrderRequest).
        if (['fullName', 'street', 'city'].some((n) => addressValue(n) === '')) {
            root.querySelector('[data-address-form]')?.classList.remove('hidden');
            root.querySelector('[data-address-summary]')?.classList.add('hidden');
            show(error, t('checkout.err.address'));

            return;
        }
        hide(error);
        paintAddressSummary();
        goToStep(2);
    });
    backBtn?.addEventListener('click', () => goToStep(1));

    // Recopie les champs de l'adresse dans les résumés (étape 1 et récapitulatif de l'étape 2).
    const paintAddressSummary = () => {
        root.querySelectorAll('[data-sum-name]').forEach((el) => { el.textContent = addressValue('fullName'); });
        root.querySelectorAll('[data-sum-street]').forEach((el) => { el.textContent = addressValue('street'); });
        root.querySelectorAll('[data-sum-city]').forEach((el) => { el.textContent = addressValue('city'); });
        root.querySelectorAll('[data-sum-postal]').forEach((el) => { el.textContent = addressValue('postalCode'); });
        root.querySelectorAll('[data-sum-phone]').forEach((el) => { el.textContent = addressValue('phone') || t('checkout.no_phone'); });
    };

    // ------------------------------------------------------------------ Carnet d'adresses : adresse par défaut, autre adresse enregistrée, ou nouvelle adresse

    const summaryBox = root.querySelector('[data-address-summary]');
    const formBox = root.querySelector('[data-address-form]');
    const saveOptions = root.querySelector('[data-save-options]');
    const savedCards = root.querySelectorAll('[data-saved-address]');
    // « Enregistrer cette adresse » / « en faire mon adresse par défaut » : ne concernent qu'une adresse SAISIE ici (options visibles).
    const optionChecked = (name) => root.querySelector('[data-save-options] [name="' + name + '"]')?.checked === true;
    const saveOptionsVisible = () => saveOptions !== null && !saveOptions.classList.contains('hidden');

    const markCard = (selected) => {
        savedCards.forEach((card) => {
            const on = card === selected;
            card.setAttribute('aria-pressed', on ? 'true' : 'false');
            card.classList.toggle('border-slate-950', on);
            card.classList.toggle('ring-1', on);
            card.classList.toggle('ring-slate-950', on);
            card.classList.toggle('border-slate-200', !on);
            const dot = card.querySelector('[data-saved-dot]');
            dot?.classList.toggle('border-slate-950', on);
            dot?.classList.toggle('bg-slate-950', on);
            dot?.classList.toggle('border-slate-300', !on);
        });
    };
    const setField = (name, value) => { const input = field(name); if (input) input.value = value ?? ''; };

    savedCards.forEach((card) => card.addEventListener('click', () => {
        let address = null;
        try { address = JSON.parse(card.getAttribute('data-saved-address') || 'null'); } catch { address = null; }
        if (!address) return;
        setField('fullName', address.fullName);
        setField('phone', address.phone || '');
        setField('street', address.street);
        setField('city', address.city);
        setField('postalCode', address.postalCode || '');
        setField('courierNote', address.instructions || '');
        markCard(card);
        paintAddressSummary();
        summaryBox?.classList.remove('hidden');
        formBox?.classList.add('hidden');
        saveOptions?.classList.add('hidden');
        hide(root.querySelector('[data-address-error]'));
    }));

    root.querySelector('[data-new-address]')?.addEventListener('click', () => {
        setField('fullName', root.querySelector('[data-step="1"]')?.getAttribute('data-default-name') ?? '');
        setField('phone', '');
        setField('street', '');
        setField('city', 'Antananarivo');
        setField('postalCode', '101');
        setField('courierNote', '');
        markCard(null);
        summaryBox?.classList.add('hidden');
        formBox?.classList.remove('hidden');
        saveOptions?.classList.remove('hidden');
        field('street')?.focus();
    });

    root.querySelector('[data-address-edit]')?.addEventListener('click', () => {
        const opening = formBox?.classList.contains('hidden');
        summaryBox?.classList.toggle('hidden');
        formBox?.classList.toggle('hidden');
        // Une adresse modifiée ici peut être enregistrée comme nouvelle adresse : les options réapparaissent avec le formulaire.
        saveOptions?.classList.toggle('hidden', !opening);
    });

    // ------------------------------------------------------------------ Moyen de paiement

    const payCards = root.querySelectorAll('[data-pay]');
    const selectPayment = (method) => {
        paymentMethod = method;
        payCards.forEach((card) => {
            const active = card.getAttribute('data-pay') === method;
            ['border-slate-950', 'shadow-sm', 'ring-1', 'ring-slate-950'].forEach((c) => card.classList.toggle(c, active));
            card.classList.toggle('border-slate-200', !active);
            const radio = card.querySelector('[data-radio]');
            if (radio) radio.replaceChildren(...(active ? [Object.assign(document.createElement('span'), { className: 'h-2 w-2 rounded-full bg-white' })] : []));
            radio?.classList.toggle('bg-slate-950', active);
            radio?.classList.toggle('border-slate-950', active);
            radio?.classList.toggle('border-slate-300', !active);
            card.querySelector('[data-pay-body]')?.classList.toggle('hidden', !active);
        });
        refreshSummary();
    };
    payCards.forEach((card) => card.addEventListener('click', (e) => {
        if (!e.target.closest('input, [data-momo-input], button[data-channel], button[data-installments]')) selectPayment(card.getAttribute('data-pay'));
    }));

    // 3x / 4x
    const instButtons = root.querySelectorAll('[data-installments]');
    const setInstallments = (n) => {
        installments = n;
        instButtons.forEach((b) => {
            const active = Number(b.getAttribute('data-installments')) === n;
            ['border-slate-950', 'bg-slate-950', 'text-white', 'shadow-xs'].forEach((c) => b.classList.toggle(c, active));
            ['border-slate-200', 'bg-slate-50', 'text-slate-800'].forEach((c) => b.classList.toggle(c, !active));
        });
        refreshSummary();
    };
    instButtons.forEach((b) => b.addEventListener('click', () => setInstallments(Number(b.getAttribute('data-installments')))));

    // Canal de prélèvement des mensualités
    root.querySelectorAll('[data-channel]').forEach((btn) => {
        btn.addEventListener('click', () => {
            channel = btn.getAttribute('data-channel');
            root.querySelectorAll('[data-channel]').forEach((b) => {
                const active = b === btn;
                ['border-slate-950', 'bg-slate-950', 'text-white'].forEach((c) => b.classList.toggle(c, active));
                ['border-slate-200', 'bg-white', 'text-slate-700'].forEach((c) => b.classList.toggle(c, !active));
            });
            root.querySelectorAll('[data-channel-panel]').forEach((p) => p.classList.toggle('hidden', p.getAttribute('data-channel-panel') !== channel));
        });
    });

    // Numéro Mobile Money : contrôle de format en direct + opérateur reconnu
    root.querySelectorAll('[data-momo-input]').forEach((input) => {
        const badge = input.parentElement.querySelector('[data-momo-badge]');
        const msg = input.parentElement.parentElement.querySelector('[data-momo-msg]');
        const idleText = msg?.textContent ?? '';
        input.addEventListener('input', () => {
            if (!input.value) {
                badge.classList.add('hidden');
                if (msg) { msg.textContent = idleText; msg.className = 'text-[11px] text-slate-500'; }

                return;
            }
            const res = validateMobileMoney(input.value);
            if (res.valid) {
                badge.textContent = res.carrier.toUpperCase();
                badge.classList.remove('hidden');
                if (msg) { msg.textContent = '✓ ' + res.message; msg.className = 'text-[11px] font-semibold text-emerald-600'; }
            } else {
                badge.classList.add('hidden');
                if (msg) { msg.textContent = '✕ ' + res.message; msg.className = 'text-[11px] font-semibold text-red-500'; }
            }
        });
    });

    // ------------------------------------------------------------------ Récapitulatif et échéancier

    function refreshSummary() {
        const cart = lines();
        const subtotal = getSubtotal();
        const shipping = getShipping();
        const total = getTotal();

        root.querySelector('[data-empty-cart]')?.classList.toggle('hidden', cart.length > 0);
        root.querySelectorAll('[data-sum-items-label]').forEach((el) => { el.textContent = t('checkout.items', { count: formatNumber(cartCount(cart)) }); });
        root.querySelectorAll('[data-sum-subtotal]').forEach((el) => { el.textContent = formatNumber(subtotal); });
        root.querySelectorAll('[data-sum-shipping]').forEach((el) => { el.textContent = formatNumber(shipping); });
        root.querySelectorAll('[data-sum-total]').forEach((el) => { el.textContent = formatNumber(total); });
        [3, 4].forEach((n) => {
            root.querySelectorAll('[data-inst-monthly="' + n + '"]').forEach((el) => { el.textContent = formatNumber(total / n); });
        });

        // Échéancier : mêmes règles que le serveur (1re échéance aujourd'hui, puis une par mois ; la dernière absorbe l'arrondi).
        const scheduleEl = root.querySelector('[data-schedule]');
        if (scheduleEl) {
            const base = Math.floor(total / installments);
            const rows = [];
            for (let i = 1; i <= installments; i += 1) {
                const due = new Date();
                due.setMonth(due.getMonth() + (i - 1));
                const amount = base + (i === installments ? total - base * installments : 0);
                rows.push(scheduleRow(i, i === 1 ? t('checkout.today') : t('checkout.nth_debit', { n: i }), formatShortDate(due), amount));
            }
            scheduleEl.replaceChildren(...rows);
            root.querySelectorAll('[data-total-text]').forEach((el) => { el.textContent = formatNumber(total); });
            root.querySelectorAll('[data-schedule-title]').forEach((el) => { el.textContent = t('checkout.schedule_title', { count: installments }); });
        }

        const recap = root.querySelector('[data-installment-recap]');
        const confirmLabel = root.querySelector('[data-confirm-label]');
        if (paymentMethod === 'installments') {
            const first = Math.floor(total / installments);
            recap?.classList.remove('hidden');
            root.querySelectorAll('[data-first-payment]').forEach((el) => { el.textContent = formatAr(first); });
            root.querySelectorAll('[data-remaining]').forEach((el) => { el.textContent = (installments - 1) + ' x ' + formatAr(first); });
            if (confirmLabel) confirmLabel.textContent = t('checkout.pay_today', { amount: formatAr(first) });
        } else {
            recap?.classList.add('hidden');
            if (confirmLabel) confirmLabel.textContent = paymentMethod === 'cash_on_delivery' ? t('checkout.confirm') : t('checkout.pay_amount', { amount: formatAr(total) });
        }
        const confirmBtn = root.querySelector('[data-confirm-order]');
        if (confirmBtn) confirmBtn.disabled = cart.length === 0 || placing;
    }
    previewInput?.addEventListener('input', refreshSummary);
    window.addEventListener('gb:cart-changed', refreshSummary);

    /** Une ligne de l'échéancier, construite sans innerHTML (aucun texte n'est interprété comme du HTML). */
    function scheduleRow(index, label, date, amount) {
        const el = (tag, cls, text) => Object.assign(document.createElement(tag), { className: cls, textContent: text ?? '' });
        const row = el('div', 'flex items-center justify-between rounded-xl border border-slate-200/80 bg-white p-2 text-[11px]');
        const left = el('div', 'flex items-center gap-2');
        left.appendChild(el('span', 'flex h-4 w-4 items-center justify-center rounded-full font-mono text-[9px] font-bold ' + (index === 1 ? 'bg-slate-950 text-[#00E5A3]' : 'bg-slate-100 text-slate-600'), index === 1 ? '✓' : String(index)));
        const text = el('div', '');
        text.appendChild(el('span', 'font-semibold text-slate-800', label));
        text.appendChild(el('span', 'ml-1.5 text-slate-400', '(' + date + ')'));
        left.appendChild(text);
        row.append(left, el('span', 'font-mono font-bold text-slate-900', formatAr(amount)));

        return row;
    }

    // ------------------------------------------------------------------ Vérification par SMS (fenêtre commune)

    const modal = root.querySelector('[data-verify-modal]');
    const q = (sel) => modal.querySelector(sel);
    let cooldownTimer = null;

    const setCooldown = (seconds) => {
        const resend = q('[data-verify-resend]');
        const label = t('otp.resend');
        window.clearInterval(cooldownTimer);
        let left = seconds;
        const paint = () => {
            if (left <= 0) { window.clearInterval(cooldownTimer); resend.disabled = false; resend.textContent = label; return; }
            resend.disabled = true;
            resend.textContent = t('otp.resend_in', { seconds: left });
            left -= 1;
        };
        paint();
        cooldownTimer = window.setInterval(paint, 1000);
    };

    /**
     * Ouvre la fenêtre de vérification.
     * @param {'phone'|'momo'} kind
     * @param {string} number numéro à vérifier (modifiable pour le téléphone du compte, imposé pour le Mobile Money)
     * @returns {Promise<boolean>} vrai si le numéro a été vérifié
     */
    function verify(kind, number) {
        return new Promise((resolve) => {
            const isPhone = kind === 'phone';
            const numberInput = q('[data-verify-number]');
            const errorBox = q('[data-verify-error]');
            const codeInput = q('[data-verify-code]');
            const sendBtn = q('[data-verify-send]');
            const confirmBtn = q('[data-verify-confirm]');
            const resendBtn = q('[data-verify-resend]');
            const abort = new AbortController();

            q('[data-verify-title]').textContent = t(isPhone ? 'checkout.verify.phone_title' : 'checkout.verify.momo_title');
            q('[data-verify-text]').textContent = t(isPhone ? 'checkout.verify.phone_text' : 'checkout.verify.momo_text');
            q('[data-verify-number-label]').textContent = t(isPhone ? 'checkout.verify.phone_label' : 'checkout.verify.momo_label');
            confirmBtn.textContent = confirmBtn.getAttribute(isPhone ? 'data-label-phone' : 'data-label-momo');
            numberInput.value = number;
            numberInput.readOnly = !isPhone; // un numéro Mobile Money se corrige dans le champ de paiement, pas ici
            codeInput.value = '';
            hide(errorBox);
            q('[data-verify-step="send"]').classList.remove('hidden');
            q('[data-verify-step="code"]').classList.add('hidden');
            modal.classList.remove('hidden', 'pointer-events-none');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
            numberInput.focus();

            const finish = (ok) => {
                abort.abort();
                window.clearInterval(cooldownTimer);
                modal.classList.add('hidden', 'pointer-events-none');
                modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
                codeInput.value = '';
                resolve(ok);
            };

            const send = async () => {
                hide(errorBox);
                sendBtn.disabled = true;
                resendBtn.disabled = true;
                const result = isPhone
                    ? await postJson(ep.phoneSend, { phone: numberInput.value })
                    : await postJson(ep.momoSend, { number: numberInput.value });
                sendBtn.disabled = false;
                if (!result.ok) {
                    show(errorBox, errorText(result));
                    const wait = Number(result.body?.retryAfter) || Number(result.body?.resendIn) || 0;
                    if (wait > 0 && !q('[data-verify-step="code"]').classList.contains('hidden')) setCooldown(wait); else resendBtn.disabled = false;

                    return;
                }
                if (result.body.state === 'verified') { finish(true); return; } // rien à saisir : déjà prouvé
                q('[data-verify-step="send"]').classList.add('hidden');
                q('[data-verify-step="code"]').classList.remove('hidden');
                q('[data-verify-sent]').textContent = t('checkout.verify.sent', { number: result.body.maskedTarget || '' });
                setCooldown(Number(result.body.resendIn) || 30);
                codeInput.focus();
            };

            const confirm = async () => {
                hide(errorBox);
                if (!/^\d{6}$/.test(codeInput.value.trim())) { show(errorBox, t('otp.enter_code')); return; }
                confirmBtn.disabled = true;
                const result = isPhone
                    ? await postJson(ep.phoneVerify, { code: codeInput.value.trim() })
                    : await postJson(ep.momoVerify, { number: numberInput.value, code: codeInput.value.trim() });
                confirmBtn.disabled = false;
                if (result.ok) { finish(true); return; }
                show(errorBox, errorText(result));
                codeInput.select();
            };

            const on = (selector, handler) => q(selector).addEventListener('click', handler, { signal: abort.signal });
            on('[data-verify-send]', send);
            on('[data-verify-resend]', send);
            on('[data-verify-confirm]', confirm);
            modal.querySelectorAll('[data-verify-cancel]').forEach((b) => b.addEventListener('click', () => finish(false), { signal: abort.signal }));
            codeInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); confirm(); } }, { signal: abort.signal });
            numberInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); send(); } }, { signal: abort.signal });
        });
    }

    const markPhoneVerified = () => {
        phoneVerified = true;
        const badge = root.querySelector('[data-phone-badge]');
        if (badge) {
            badge.textContent = '✓ ' + t('checkout.phone_verified');
            badge.className = 'inline-flex shrink-0 items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700';
        }
        root.querySelector('[data-phone-reminder]')?.classList.add('hidden');
        showToast(t('account.phone_verified_ok'));
    };

    /** Vérifie le téléphone du compte si besoin. Le numéro proposé est celui saisi pour la livraison. */
    async function ensurePhoneVerified() {
        if (phoneVerified) return true;
        const ok = await verify('phone', addressValue('phone'));
        if (ok) markPhoneVerified();

        return ok;
    }

    root.querySelector('[data-phone-reminder]')?.addEventListener('click', ensurePhoneVerified);

    // ------------------------------------------------------------------ Validation de la commande

    /** Numéro Mobile Money du moyen de paiement choisi, ou null si le paiement n'en utilise pas. */
    function momoNumberInUse() {
        if (paymentMethod === 'mobile_money') return root.querySelector('[data-momo-input="mobile_money"]')?.value ?? '';
        if (paymentMethod === 'installments' && channel === 'mobile_money') return root.querySelector('[data-momo-input="installments"]')?.value ?? '';

        return null;
    }

    const notice = root.querySelector('[data-checkout-notice]');
    const setPlacing = (on) => {
        placing = on;
        refreshSummary();
    };

    root.querySelector('[data-confirm-order]')?.addEventListener('click', async () => {
        if (placing || !hasItems()) return;
        hide(notice);

        // 1) Numéro Mobile Money : format, puis vérification par SMS (une seule fois par numéro).
        const momo = momoNumberInUse();
        if (momo !== null) {
            const check = validateMobileMoney(momo);
            if (!check.valid) {
                show(notice, t('checkout.err.momo', { reason: check.message }));
                root.querySelector('[data-momo-input]:not([disabled])')?.focus();

                return;
            }
            if (!verifiedMomo.has(normalize(momo))) {
                if (!(await verify('momo', momo))) return;
                verifiedMomo.add(normalize(momo));
            }
        }

        // 2) Téléphone du compte vérifié (obligatoire avant la première commande).
        if (!(await ensurePhoneVerified())) {
            show(notice, t('checkout.err.phone'));

            return;
        }

        // 3) Envoi : le serveur relit le panier et recalcule tout.
        setPlacing(true);
        const result = await postJson(ep.place, {
            items: lines().map((l) => ({ ref: l.ref, quantity: l.quantity })),
            address: {
                fullName: addressValue('fullName'), phone: addressValue('phone'), street: addressValue('street'),
                city: addressValue('city'), postalCode: addressValue('postalCode'), courierNote: addressValue('courierNote'),
                // Carnet d'adresses : une adresse déjà enregistrée n'est jamais recréée (le serveur compare) ; les options ne
                // comptent que pour une adresse saisie ici.
                save: saveOptionsVisible() ? optionChecked('saveAddress') : true,
                makeDefault: saveOptionsVisible() ? optionChecked('makeDefaultAddress') : false,
            },
            payment: {
                method: paymentMethod, installments: paymentMethod === 'installments' ? installments : 1,
                channel, mobileMoneyNumber: momo ?? '',
            },
            idempotencyKey,
        });
        setPlacing(false);

        if (result.ok) {
            clearCart();
            showToast(t('checkout.placed'));
            goTo(result.body.redirect, '/compte?section=orders');

            return;
        }
        show(notice, errorText(result));
        // Le serveur peut contester une vérification (compte modifié entre-temps) : on redemande proprement.
        if (result.body?.error === 'phone_not_verified') { phoneVerified = false; await ensurePhoneVerified(); }
        if (result.body?.error === 'mobile_money_not_verified' && momo !== null) verifiedMomo.delete(normalize(momo));
    });

    goToStep(1);
    selectPayment('installments');
    setInstallments(3);
    refreshSummary();
}
