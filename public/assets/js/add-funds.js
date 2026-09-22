/* public/assets/js/add-funds.js — deposit rails (Add Funds) */
(function () {
document.addEventListener('DOMContentLoaded', function() {
    const boot = window.AddFundsBoot || {};

    const stripeReady = !!(boot.stripeReady);
    const paypalReady = !!(boot.paypalReady);
    const wisePayUrl = boot.wisePayUrl || '';
    const cryptoEnabled = !!boot.cryptoEnabled;

    let selectedAmount = 0;
    let selectedMethod = null;
    let referenceCode = null;
    let invoiceLocked = false;
    let invoiceMarkPaidUrl = null;
    let invoiceViewUrl = null;
    const prefillAmount = boot.prefillAmount || null;
    const prefillMethod = boot.prefillMethod || null;

    function sameOriginRoute(raw, fallbackPath) {
        const fallback = String(fallbackPath || '').trim();
        const value = String(raw || '').trim();
        if (!value) {
            return fallback;
        }
        try {
            if (/^https?:\/\//i.test(value)) {
                const u = new URL(value, window.location.href);
                const here = window.location;
                const sameHost = u.hostname === here.hostname;
                const samePort = String(u.port || '') === String(here.port || '');
                const sameProtocol = u.protocol === here.protocol;
                if (!sameHost || !samePort || !sameProtocol) {
                    return (u.pathname + u.search) || fallback;
                }
            }
        } catch (e) {}
        return value;
    }

    // Leftover Hostinger APP_URL (localhost / www / http) must not send
    // invoice POSTs to another host — that drops the session and SweetAlert
    // shows a generic submit error.
    boot.routes = boot.routes || {};
    const routeFallbacks = {
        store: '/advertiser/add-funds',
        addFunds: '/advertiser/add-funds',
        saveBilling: '/advertiser/save-billing-info',
        paySavedCard: '/advertiser/add-funds/pay-saved-card',
        createCheckout: '/advertiser/create-checkout-session',
        createPaypal: '/advertiser/add-funds/paypal',
        getBilling: '/advertiser/get-billing-info',
        paymentMethodsSetup: '/advertiser/payment-methods/setup',
        paymentMethodsBase: '/advertiser/payment-methods',
        wiseQr: '/advertiser/add-funds/wise-qr',
    };
    Object.keys(routeFallbacks).forEach(function (key) {
        boot.routes[key] = sameOriginRoute(boot.routes[key], routeFallbacks[key]);
    });

    function isManualMethod(method) {
        return method === 'wise' || method === 'bank' || method === 'crypto';
    }

    function isRealReference(code) {
        const s = String(code || '').trim().replace(/^REF/i, '');
        if (!s || s === '—' || s === '-') {
            return false;
        }
        // Leftover dummy placeholders (XXXXXXXX) must never become a pay instruction.
        if (/^x+$/i.test(s)) {
            return false;
        }
        return /^\d{6,}$/.test(s);
    }

    // Instant rails only: metadata REF at proceed time. Never shown as a pay instruction.
    function instantRailReference() {
        return Math.floor(100000 + Math.random() * 900000).toString();
    }

    function stampServerReference(code) {
        referenceCode = isRealReference(code) ? String(code).trim().replace(/^REF/i, '') : null;
        const label = referenceCode ? ('REF' + referenceCode) : '—';
        const refCodeDisplay = document.getElementById('referenceCode');
        const refCodeTexts = document.querySelectorAll('.ref-code-display');
        const refCodeDisplaySpan = document.getElementById('refCodeDisplay');
        const copyBtn = document.getElementById('copyRefBtn');
        const hintPending = document.getElementById('refHintPending');
        const hintReady = document.getElementById('refHintReady');
        const hintBox = document.getElementById('refHintBox');

        if (refCodeDisplay) {
            refCodeDisplay.innerText = referenceCode || '—';
            refCodeDisplay.setAttribute('data-placeholder', referenceCode ? 'false' : 'true');
        }
        if (refCodeDisplaySpan) refCodeDisplaySpan.innerText = label;
        refCodeTexts.forEach(el => {
            el.innerText = label;
        });
        if (copyBtn) copyBtn.disabled = !referenceCode;
        if (hintPending) hintPending.classList.toggle('d-none', !!referenceCode);
        if (hintReady) hintReady.classList.toggle('d-none', !referenceCode);
        document.querySelectorAll('.js-manual-ref-alert').forEach(el => {
            el.classList.toggle('d-none', !referenceCode);
        });
        if (hintBox) {
            const instantRail = selectedMethod === 'card' || selectedMethod === 'paypal';
            hintBox.classList.toggle('d-none', instantRail && !referenceCode);
        }
    }

    function hideManualPayDetails() {
        if (wiseDetails) wiseDetails.style.display = 'none';
        if (cryptoDetails) cryptoDetails.style.display = 'none';
        if (bankDetails) bankDetails.style.display = 'none';
        const qr = document.getElementById('wiseQRCode');
        if (qr) {
            qr.removeAttribute('src');
            qr.style.display = 'none';
        }
    }

    function setInvoiceLockUi(locked) {
        const section = document.getElementById('depositSection');
        if (section) section.classList.toggle('is-invoice-locked', !!locked);
        document.querySelectorAll('.payment-option').forEach(opt => {
            const method = opt.dataset.method;
            const out = locked && method !== selectedMethod;
            opt.classList.toggle('is-locked-out', out);
            if (out) {
                opt.setAttribute('aria-disabled', 'true');
            } else if (opt.getAttribute('aria-disabled') === 'true' && opt.dataset.method) {
                const ready = !(
                    (method === 'card' && !stripeReady) ||
                    (method === 'paypal' && !paypalReady) ||
                    (method === 'crypto' && !cryptoEnabled)
                );
                if (ready) opt.removeAttribute('aria-disabled');
            }
        });
        amountBtns.forEach(btn => { btn.disabled = !!locked; });
        if (customAmountInput) customAmountInput.disabled = !!locked;
    }

    function clearInvoiceLock() {
        invoiceLocked = false;
        invoiceMarkPaidUrl = null;
        invoiceViewUrl = null;
        stampServerReference(null);
        hideManualPayDetails();
        const bar = document.getElementById('invoiceReadyBar');
        if (bar) bar.style.display = 'none';
        const details = document.getElementById('paymentDetailsSection');
        if (details) {
            details.setAttribute('data-invoice-ready', '0');
            if (!selectedMethod || isManualMethod(selectedMethod)) {
                details.style.display = 'none';
            }
        }
        setInvoiceLockUi(false);
        if (typeof setProceedBusy === 'function') {
            setProceedBusy(false);
        }
    }

    function applyInvoice(data) {
        try {
            const code = data && (data.reference_code || data.reference || data.ref)
                ? String(data.reference_code || data.reference || data.ref)
                : '';
            if (!isRealReference(code)) return;
            invoiceLocked = true;
            invoiceMarkPaidUrl = data.mark_paid_url || data.markPaidUrl || null;
            invoiceViewUrl = data.invoice_url || data.invoiceUrl || null;
            stampServerReference(code);
            const details = document.getElementById('paymentDetailsSection');
            if (details) {
                details.style.display = 'block';
                details.setAttribute('data-invoice-ready', '1');
            }
            hideManualPayDetails();
            if (selectedMethod === 'wise' && wiseDetails) {
                wiseDetails.style.display = 'block';
                if (selectedAmount >= 10) syncWiseQr(selectedAmount);
            }
            if (selectedMethod === 'crypto' && cryptoDetails) cryptoDetails.style.display = 'block';
            if (selectedMethod === 'bank' && bankDetails) bankDetails.style.display = 'block';
            const bar = document.getElementById('invoiceReadyBar');
            if (bar) bar.style.display = 'block';
            const view = document.getElementById('invoiceReadyView');
            if (view) {
                if (invoiceViewUrl) {
                    view.href = invoiceViewUrl;
                    view.classList.remove('d-none');
                } else {
                    view.classList.add('d-none');
                }
            }
            setInvoiceLockUi(true);
            setProceedBusy(false);
        } catch (e) {
            console.error(e);
        }
    }

    const amountBtns = document.querySelectorAll('.amount-btn');
    const customAmountInput = document.getElementById('customAmount');

    function applyPrefill() {
        if (prefillAmount && Number(prefillAmount) >= 10) {
            setSelectedAmount(Number(prefillAmount));
            const matchBtn = Array.from(document.querySelectorAll('.amount-btn')).find(
                btn => Number(btn.dataset.amount) === Number(prefillAmount)
            );
            if (matchBtn) {
                document.querySelectorAll('.amount-btn').forEach(b => b.classList.remove('active'));
                matchBtn.classList.add('active');
            } else if (customAmountInput) {
                customAmountInput.value = String(prefillAmount);
            }
        }
        if (prefillMethod) {
            if (prefillMethod === 'crypto' && !cryptoEnabled) {
                return;
            }
            if (prefillMethod === 'card' && !stripeReady) {
                return;
            }
            if (prefillMethod === 'paypal' && !paypalReady) {
                return;
            }
            const opt = document.querySelector('.payment-option[data-method="' + prefillMethod + '"]');
            if (!opt || opt.getAttribute('aria-disabled') === 'true') {
                return;
            }
            opt.click();
        }
    }
    const selectedAmountDisplay = document.getElementById('selectedAmountDisplay');
    const selectedAmountValue = document.getElementById('selectedAmountValue');
    const paymentOptions = document.querySelectorAll('.payment-option');
    const paymentDetailsSection = document.getElementById('paymentDetailsSection');
    const wiseDetails = document.getElementById('wisePaymentDetails');
    const cryptoDetails = document.getElementById('cryptoPaymentDetails');
    const bankDetails = document.getElementById('bankPaymentDetails');
    const cardDetails = document.getElementById('cardPaymentDetails');
    const paypalDetails = document.getElementById('paypalPaymentDetails');
    const proceedBtn = document.getElementById('proceedBtn');
    let proceedBusy = false;
    const depositFeeNote = document.getElementById('depositFeeNote');
    const depositFeeNotes = {
        card: 'No extra deposit fee — we cover card processing.',
        paypal: 'No extra deposit fee — we cover PayPal processing.',
        bank: 'SEPA usually 0–2 business days after you send; wallet credits after we confirm.',
        wise: 'SEPA usually 0–2 business days after you send; wallet credits after we confirm.',
        crypto: 'Credits after network confirmation and our review.',
    };

    function buildWisePayLink(amount) {
        const base = String(wisePayUrl || '').replace(/[?&]$/, '');
        return `${base}?amount=${amount}&currency=EUR`;
    }

    function wiseQrEndpoint() {
        const fromBoot = boot.routes && boot.routes.wiseQr;
        const img = document.getElementById('wiseQRCode');
        const fromDom = img && img.dataset ? img.dataset.qrBase : '';
        return String(fromBoot || fromDom || '').trim();
    }

    function syncWiseQr(amount) {
        // Pay instructions (QR + live link) only after the invoice exists.
        if (!invoiceLocked) {
            const qr = document.getElementById('wiseQRCode');
            if (qr) {
                qr.removeAttribute('src');
                qr.style.display = 'none';
            }
            return;
        }

        const wiseQRCode = document.getElementById('wiseQRCode');
        const wiseQrHint = document.getElementById('wiseQrHint');
        const wiseQrFallback = document.getElementById('wiseQrFallback');
        const wiseOpenLink = document.getElementById('wiseOpenLink');
        const wiseLink = document.getElementById('wisePaymentLink');
        const payLink = buildWisePayLink(amount);

        if (wiseLink) {
            wiseLink.textContent = payLink;
        }

        if (wiseOpenLink) {
            wiseOpenLink.href = payLink;
            wiseOpenLink.classList.toggle('d-none', !(amount >= 10));
        }

        if (!wiseQRCode) {
            return;
        }

        const qrBase = wiseQrEndpoint();
        if (amount >= 10 && qrBase) {
            const nextSrc = `${qrBase}?amount=${encodeURIComponent(amount)}`;
            wiseQRCode.onerror = function () {
                wiseQRCode.style.display = 'none';
                if (wiseQrHint) wiseQrHint.classList.add('d-none');
                if (wiseQrFallback) wiseQrFallback.classList.remove('d-none');
            };
            wiseQRCode.onload = function () {
                wiseQRCode.style.display = 'block';
                if (wiseQrHint) wiseQrHint.classList.add('d-none');
                if (wiseQrFallback) wiseQrFallback.classList.add('d-none');
            };
            // Force reload when amount changes or a prior error hid the image.
            if (wiseQRCode.getAttribute('src') === nextSrc) {
                wiseQRCode.removeAttribute('src');
            }
            wiseQRCode.src = nextSrc;
        } else {
            wiseQRCode.removeAttribute('src');
            wiseQRCode.style.display = 'none';
            if (wiseQrHint) wiseQrHint.classList.remove('d-none');
            if (wiseQrFallback) wiseQrFallback.classList.add('d-none');
        }
    }

    function syncFeeNote() {
        if (!depositFeeNote) return;
        const note = selectedMethod ? depositFeeNotes[selectedMethod] : '';
        if (note) {
            depositFeeNote.textContent = note;
            depositFeeNote.style.display = '';
        } else {
            depositFeeNote.textContent = '';
            depositFeeNote.style.display = 'none';
        }
    }

    function amountReady() {
        return Number.isFinite(Number(selectedAmount)) && Number(selectedAmount) >= 10;
    }

    function jsonHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': boot.csrfToken
        };
    }

    function httpFallbackMessage(status) {
        if (status === 419) {
            return 'Your session expired. Refresh the page and try again.';
        }
        if (status === 401 || status === 403) {
            return 'Please sign in again to continue.';
        }
        if (status === 422) {
            return 'Please check the amount and payment method.';
        }
        if (status === 429) {
            return 'Please wait a moment and try again.';
        }
        if (status === 503) {
            return 'Deposits are temporarily unavailable. Please try again shortly.';
        }
        return 'Could not create the invoice. Please try again.';
    }

    function jsonMessage(data, status) {
        if (data && typeof data.message === 'string' && data.message.trim()) {
            return data.message.trim();
        }
        if (data && data.errors && typeof data.errors === 'object') {
            const first = Object.values(data.errors).flat().find(Boolean);
            if (first) {
                return String(first);
            }
        }
        return httpFallbackMessage(status);
    }

    async function readJson(response) {
        const text = await response.text();
        if (!text) {
            return {};
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(httpFallbackMessage(response.status));
        }
    }

    function setProceedBusy(busy) {
        proceedBusy = !!busy;
        if (!proceedBtn) return;
        if (busy) {
            proceedBtn.disabled = true;
            proceedBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
            return;
        }
        if (invoiceLocked) {
            proceedBtn.disabled = true;
            proceedBtn.innerHTML = '<i class="fa fa-check me-2"></i> Invoice created';
            return;
        }
        syncProceedLabel();
    }

    window.__afProceedLabel = function () {
        if (!amountReady()) {
            return '<i class="fa fa-arrow-right me-2"></i> Select an amount';
        }
        const formatted = '€' + Number(selectedAmount).toFixed(2);
        if (selectedMethod === 'card') {
            return '<i class="fa fa-credit-card me-2"></i> Pay ' + formatted + ' with card';
        }
        if (selectedMethod === 'paypal') {
            const logo = boot.paypalLogo || '/assets/img/payments/paypal.png';
            return '<img src="' + logo + '" alt="" width="24" height="24" class="me-2" style="width:24px;height:24px;vertical-align:middle"> Pay ' + formatted + ' with PayPal';
        }
        if (selectedMethod) {
            return '<i class="fa fa-file-invoice me-2"></i> Get invoice & pay ' + formatted;
        }
        return '<i class="fa fa-arrow-right me-2"></i> Select a payment method';
    };
    function syncProceedLabel() {
        if (!proceedBtn || invoiceLocked || proceedBusy) return;
        proceedBtn.innerHTML = window.__afProceedLabel();
        proceedBtn.disabled = !amountReady() || !selectedMethod;
        syncFeeNote();
    };

    const paymentError = document.getElementById('paymentError');
    const summaryAmount = document.getElementById('summaryAmount');
    const summaryTotal = document.getElementById('summaryTotal');

    // Amount button click
    amountBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const amount = parseFloat(this.dataset.amount);
            setSelectedAmount(amount);
            amountBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            if (customAmountInput) customAmountInput.value = '';
        });
    });

    // Custom amount: allow mid-typing (e.g. "1" while entering "100").
    // Enforce the €10 minimum on blur and when proceeding — not on every keystroke.
    if (customAmountInput) {
    customAmountInput.addEventListener('input', function() {
        const raw = String(this.value || '').trim();
        if (raw === '') {
            selectedAmountDisplay.style.display = 'none';
            selectedAmount = 0;
            updateSummary(0);
            return;
        }

        const amount = parseFloat(raw);
        if (!isNaN(amount) && amount >= 10) {
            setSelectedAmount(amount);
            amountBtns.forEach(b => b.classList.remove('active'));
            return;
        }

        // Partial / below-minimum while typing — keep the field, clear the selection.
        selectedAmount = 0;
        selectedAmountDisplay.style.display = 'none';
        updateSummary(0);
    });

    customAmountInput.addEventListener('blur', function() {
        const raw = String(this.value || '').trim();
        if (raw === '') {
            return;
        }

        const amount = parseFloat(raw);
        if (isNaN(amount) || amount < 10) {
            Swal.fire({
                title: 'Invalid Amount',
                text: 'Minimum amount is €10',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            this.value = '';
            selectedAmount = 0;
            selectedAmountDisplay.style.display = 'none';
            updateSummary(0);
        }
    });
    }

    function setSelectedAmount(amount) {
        selectedAmount = amount;
        selectedAmountValue.innerText = `€${amount.toFixed(2)}`;
        selectedAmountDisplay.style.display = 'block';
        updateSummary(amount);
        
        // Update amount displays
        document.querySelectorAll('.amount-display').forEach(el => {
            el.innerText = amount.toFixed(2);
        });
        document.querySelectorAll('.amount-link').forEach(el => {
            el.innerText = amount;
        });
        
        // Wise QR stays off until applyInvoice() locks a server REF.
        if (invoiceLocked && selectedMethod === 'wise') {
            syncWiseQr(amount);
        }
        
        // Update crypto and bank amounts
        const cryptoAmount = document.getElementById('cryptoAmount');
        const bankAmount = document.getElementById('bankAmount');
        if (cryptoAmount) cryptoAmount.innerHTML = `€<span class="amount-display">${amount.toFixed(2)}</span>`;
        if (bankAmount) bankAmount.innerHTML = `€<span class="amount-display">${amount.toFixed(2)}</span>`;
    }
    
    function updateSummary(amount) {
        summaryAmount.innerText = `€${amount.toFixed(2)}`;
        summaryTotal.innerText = `€${amount.toFixed(2)}`;
        if (typeof syncProceedLabel === 'function') syncProceedLabel();
    }

    // Prefill amount/method comes from applyPrefill() above (server + ?amount=&method=).

    // Payment option click — manual rails do not reveal pay details until invoice.
    paymentOptions.forEach(option => {
        option.addEventListener('click', function() {
            if (!this.dataset.method || this.getAttribute('aria-disabled') === 'true') {
                return;
            }
            if (invoiceLocked) {
                return;
            }

            const method = this.dataset.method;
            selectedMethod = method;

            paymentOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');

            if (paymentError) paymentError.style.display = 'none';

            hideManualPayDetails();
            if (cardDetails) cardDetails.style.display = 'none';
            if (paypalDetails) paypalDetails.style.display = 'none';

            if (method === 'card' && cardDetails) {
                cardDetails.style.display = 'block';
                if (paymentDetailsSection) paymentDetailsSection.style.display = 'block';
            } else if (method === 'paypal' && paypalDetails) {
                paypalDetails.style.display = 'block';
                if (paymentDetailsSection) paymentDetailsSection.style.display = 'block';
            } else if (paymentDetailsSection) {
                paymentDetailsSection.style.display = 'none';
            }

            if (typeof syncProceedLabel === 'function') syncProceedLabel();
            if (typeof stampServerReference === 'function') stampServerReference(referenceCode);
        });
    });
    
    // Copy buttons
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const textEl = document.getElementById(targetId);
            if (textEl) {
                const textToCopy = textEl.innerText;
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const originalHtml = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(() => this.innerHTML = originalHtml, 1500);
                });
            }
        });
    });
    
    // Copy reference code button
    document.querySelectorAll('.copy-ref-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!referenceCode) {
                return;
            }
            navigator.clipboard.writeText('REF' + referenceCode).then(() => {
                const originalHtml = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check"></i> Copied!';
                setTimeout(() => this.innerHTML = originalHtml, 1500);
            });
        });
    });
    
    // Function to submit deposit
    function submitDeposit() {
        setProceedBusy(true);
        
        fetch(boot.routes.store, {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify({
                amount: selectedAmount,
                payment_method: selectedMethod,
                // Leftover Hostinger store() still requires a 6-digit REF.
                // Current store() ignores this and issues its own code.
                reference_code: instantRailReference()
            })
        })
        .then(async response => {
            const data = await readJson(response);
            data.__status = response.status;
            return data;
        })
        .then(data => {
            if (data.success) {
                try {
                    applyInvoice(data);
                } catch (e) {
                    console.error(e);
                }
                Swal.fire({
                    title: 'Invoice ready',
                    html: `Transfer <strong>€${selectedAmount.toFixed(2)}</strong> and include<br>
                           <strong class="font-monospace">REF${data.reference_code}</strong> in the payment note.<br><br>
                           Pay details are on this page. Status stays <strong>Pending</strong> until we confirm and credit your wallet.`,
                    icon: 'success',
                    confirmButtonText: 'Show pay details',
                    showCancelButton: false,
                });
            } else if (data.requires_billing) {
                // Show billing info modal
                const modal = new bootstrap.Modal(document.getElementById('billingInfoModal'));
                modal.show();
                setProceedBusy(false);
            } else {
                Swal.fire({
                    title: 'Error',
                    text: jsonMessage(data, data.__status),
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                setProceedBusy(false);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: (error && error.message) ? error.message : httpFallbackMessage(0),
                icon: 'error',
                confirmButtonText: 'OK'
            });
            setProceedBusy(false);
        });
    }
    
    // Save billing info
    const saveBillingInfoBtn = document.getElementById('saveBillingInfo');
    if (saveBillingInfoBtn) saveBillingInfoBtn.addEventListener('click', function() {
        const formData = {
            billing_name: document.getElementById('billing_name').value,
            company_name: document.getElementById('company_name').value,
            country: document.getElementById('country').value,
            state: document.getElementById('state').value,
            city: document.getElementById('city').value,
            address: document.getElementById('address').value,
            postal_code: document.getElementById('postal_code').value,
            vat_number: document.getElementById('vat_number').value,
            _token: boot.csrfToken
        };
        
        if (!String(formData.billing_name || '').trim()
            || !String(formData.company_name || '').trim()
            || !String(formData.country || '').trim()
            || !String(formData.city || '').trim()
            || !String(formData.address || '').trim()) {
            Swal.fire('Error', 'Please fill in all required fields (including company name)', 'error');
            return;
        }
        
        fetch(boot.routes.saveBilling, {
            method: 'POST',
            credentials: 'same-origin',
            headers: jsonHeaders(),
            body: JSON.stringify(formData)
        })
        .then(async response => {
            const data = await readJson(response);
            data.__status = response.status;
            return data;
        })
        .then(data => {
            if (data.success) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('billingInfoModal'));
                modal.hide();
                submitDeposit();
            } else {
                Swal.fire('Error', jsonMessage(data, data.__status), 'error');
            }
        })
        .catch(error => {
            Swal.fire('Error', (error && error.message) ? error.message : 'Failed to save billing information', 'error');
        });
    });
    
    // Proceed button
    if (proceedBtn) proceedBtn.addEventListener('click', async function() {
        if (invoiceLocked) {
            return;
        }
        if (selectedAmount < 10) {
            Swal.fire({
                title: 'Amount Required',
                text: 'Please select or enter an amount of at least €10.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        if (!selectedMethod) {
            if (paymentError) paymentError.style.display = 'block';
            Swal.fire({
                title: 'Payment Method Required',
                text: 'Please select a payment method to continue.',
                icon: 'warning',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        if (selectedMethod === 'paypal') {
            if (!paypalReady) {
                Swal.fire({
                    title: 'PayPal unavailable',
                    text: 'PayPal top-ups are offline. Use Card, Bank, Wise, or Crypto.',
                    icon: 'info',
                    confirmButtonText: 'OK'
                });
                return;
            }

            setProceedBusy(true);

            try {
                const response = await fetch(boot.routes.createPaypal, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': boot.csrfToken
                    },
                    body: JSON.stringify({
                        amount: selectedAmount,
                        reference_code: instantRailReference()
                    })
                });
                const data = await response.json();
                if (data.success && data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                throw new Error(data.message || 'Failed to start PayPal checkout');
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    title: 'Error',
                    text: error.message || 'Failed to start PayPal checkout. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                setProceedBusy(false);
            }
            return;
        }

        // For card payments: saved card charge or Stripe Checkout
        if (selectedMethod === 'card') {
            if (!stripeReady) {
                Swal.fire({
                    title: 'Card payments unavailable',
                    text: 'Card top-ups are offline. Use Bank, Wise, or Crypto.',
                    icon: 'info',
                    confirmButtonText: 'OK'
                });
                return;
            }

            setProceedBusy(true);
            const picked = document.querySelector('input[name="deposit_saved_card"]:checked');
            const savedPm = picked && picked.value !== 'new' ? picked.value : null;
            const cardReference = instantRailReference();

            try {
                if (savedPm) {
                    const response = await fetch(boot.routes.paySavedCard, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': boot.csrfToken
                        },
                        body: JSON.stringify({
                            amount: selectedAmount,
                            reference_code: cardReference,
                            payment_method_id: savedPm
                        })
                    });
                    const data = await response.json();
                    if (data.success && data.requires_action && data.client_secret && data.stripe_key) {
                        await new Promise((resolve, reject) => {
                            const script = document.createElement('script');
                            script.src = 'https://js.stripe.com/v3/';
                            script.onload = resolve;
                            script.onerror = reject;
                            document.head.appendChild(script);
                        });
                        const stripe = Stripe(data.stripe_key);
                        const result = await stripe.confirmCardPayment(data.client_secret, {
                            return_url: data.return_url
                        });
                        if (result.error) throw new Error(result.error.message || 'Authentication failed');
                        if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                            window.location.href = data.return_url + '&payment_intent=' + encodeURIComponent(result.paymentIntent.id);
                            return;
                        }
                    }
                    if (data.success && data.requires_payment && data.checkout_url) {
                        window.location.href = data.checkout_url;
                        return;
                    }
                    if (data.success) {
                        Swal.fire('Success', data.message || 'Funds added', 'success').then(() => {
                            window.location.href = data.redirect_url || boot.routes.addFunds;
                        });
                        return;
                    }
                    throw new Error(data.message || 'Saved card payment failed');
                }

                const response = await fetch(boot.routes.createCheckout, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': boot.csrfToken
                    },
                    body: JSON.stringify({
                        amount: selectedAmount,
                        reference_code: cardReference
                    })
                });
                
                const data = await response.json();
                
                if (data.success && data.checkout_url) {
                    window.location.href = data.checkout_url;
                } else {
                    throw new Error(data.message || 'Failed to create checkout session');
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.fire({
                    title: 'Error',
                    text: error.message || 'Failed to process card payment. Please try again.',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                setProceedBusy(false);
            }
        } else {
            // Bank / Wise / crypto invoices need company billing details
            if (selectedMethod === 'bank' || selectedMethod === 'wise' || selectedMethod === 'crypto') {
                fetch(boot.routes.getBilling, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: jsonHeaders()
                })
                .then(async response => {
                    const data = await readJson(response);
                    data.__status = response.status;
                    return data;
                })
                .then(billingData => {
                    if (!billingData.success || !billingData.data || !billingData.data.has_info) {
                        const modal = new bootstrap.Modal(document.getElementById('billingInfoModal'));
                        modal.show();
                    } else {
                        submitDeposit();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    // Leftover Hostinger may 404 get-billing-info; store() still checks billing.
                    submitDeposit();
                });
            } else {
                submitDeposit();
            }
        }
    });

    applyPrefill();
    if (typeof stampServerReference === 'function') stampServerReference(referenceCode);
    if (typeof syncProceedLabel === 'function') syncProceedLabel();

    if (boot.openCardsTab) {
        const cardsSection = document.getElementById('savedCardsSection');
        if (cardsSection) cardsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const addCardBtn = document.getElementById('addCardBtn');
    if (addCardBtn) {
        addCardBtn.addEventListener('click', async function () {
            addCardBtn.disabled = true;
            try {
                const res = await fetch(boot.routes.paymentMethodsSetup, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': boot.csrfToken
                    },
                    body: '{}'
                });
                const data = await res.json();
                if (data.success && data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                throw new Error(data.message || 'Unable to start card setup');
            } catch (e) {
                Swal.fire('Error', e.message || 'Unable to add card', 'error');
                addCardBtn.disabled = false;
            }
        });
    }

    document.querySelectorAll('.remove-card').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.pmId;
            const confirm = await Swal.fire({
                title: 'Remove this card?',
                text: 'You can add it again later.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remove'
            });
            if (!confirm.isConfirmed) return;
            const res = await fetch(boot.routes.paymentMethodsBase + '/' + encodeURIComponent(id), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': boot.csrfToken,
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                Swal.fire('Error', data.message || 'Could not remove card', 'error');
            }
        });
    });

    document.querySelectorAll('.set-default-card').forEach(btn => {
        btn.addEventListener('click', async function () {
            const id = this.dataset.pmId;
            const res = await fetch(boot.routes.paymentMethodsBase + '/' + encodeURIComponent(id) + '/default', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': boot.csrfToken,
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                Swal.fire('Error', data.message || 'Could not set default card', 'error');
            }
        });
    });

    window.markDepositPaid = function markDepositPaid(url, opts = {}) {
        const ref = opts.ref || 'this invoice';
        const amount = opts.amount ? ('€' + opts.amount) : 'the amount';

        return Swal.fire({
            title: 'Confirm payment sent?',
            html: `Have you already transferred <strong>${amount}</strong> with <strong>${ref}</strong> in the payment note?<br><br>
                   <span class="text-muted small">Your deposit stays <strong>Pending</strong> until we confirm funds and credit your wallet.</span>`,
            icon: 'question',
            input: 'text',
            inputPlaceholder: 'Optional: Wise/bank transfer reference',
            showCancelButton: true,
            confirmButtonText: 'OK, I have made the payment',
            cancelButtonText: 'Not yet',
        }).then((result) => {
            if (!result.isConfirmed) {
                return null;
            }

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': boot.csrfToken,
                },
                body: JSON.stringify({
                    user_payment_note: result.value || null,
                }),
            })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        Swal.fire('Error', data.message || 'Could not mark payment as sent.', 'error');
                        return data;
                    }

                    return Swal.fire({
                        icon: 'success',
                        title: 'Payment reported',
                        text: data.message,
                        confirmButtonText: 'OK',
                    }).then(() => {
                        if (opts.reloadOnSuccess !== false) {
                            window.location.reload();
                        }
                        return data;
                    });
                })
                .catch(() => {
                    Swal.fire('Error', 'Could not mark payment as sent. Please try again.', 'error');
                    return null;
                });
        });
    };

    $(document).on('click', '.mark-deposit-paid-btn', function () {
        markDepositPaid(this.dataset.markUrl, {
            ref: this.dataset.ref,
            amount: this.dataset.amount,
            reloadOnSuccess: true,
        });
    });

    const invoiceChangeBtn = document.getElementById('invoiceChangeBtn');
    if (invoiceChangeBtn) {
        invoiceChangeBtn.addEventListener('click', function () {
            if (!invoiceLocked) {
                return;
            }
            Swal.fire({
                icon: 'question',
                title: 'Start a new invoice?',
                html: 'You already have <strong>REF' + String(referenceCode || '') + '</strong>. Changing amount or method leaves that invoice pending and lets you create another.',
                showCancelButton: true,
                confirmButtonText: 'Change and start over',
                cancelButtonText: 'Keep this invoice',
            }).then(function (result) {
                if (result.isConfirmed) {
                    clearInvoiceLock();
                }
            });
        });
    }

    const invoiceReadyMarkPaid = document.getElementById('invoiceReadyMarkPaid');
    if (invoiceReadyMarkPaid) {
        invoiceReadyMarkPaid.addEventListener('click', function () {
            if (!invoiceMarkPaidUrl || !referenceCode) {
                return;
            }
            markDepositPaid(invoiceMarkPaidUrl, {
                ref: 'REF' + referenceCode,
                amount: Number(selectedAmount || 0).toFixed(2),
                reloadOnSuccess: true,
            });
        });
    }

});
})();
