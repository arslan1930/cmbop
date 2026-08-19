/**
 * Publisher Withdraw — request, history paging, and cancel.
 */
(function () {
    'use strict';

    var root = document.getElementById('publisherWithdrawApp');
    var form = document.getElementById('withdrawForm');
    if (!root || !form) {
        return;
    }

    var amountInput = document.getElementById('amount');
    var previewAmount = document.getElementById('previewAmount');
    var previewGross = document.getElementById('previewGross');
    var previewFee = document.getElementById('previewFee');
    var paymentMethod = document.getElementById('paymentMethod');
    var submitBtn = document.getElementById('submitWithdrawBtn');
    var maxAmount = parseFloat(root.getAttribute('data-max-amount') || '0');
    var minAmount = parseFloat(root.getAttribute('data-min-amount') || '20');
    var feePercent = parseFloat(root.getAttribute('data-fee-percent') || '0');
    var payoutLocked = root.getAttribute('data-payout-locked') === '1';
    var formBlocked = root.getAttribute('data-form-blocked') === '1';
    var historyUrl = root.getAttribute('data-history-url') || '';
    var cancelUrlTemplate = root.getAttribute('data-cancel-url-template') || '';
    var requestUrl = root.getAttribute('data-request-url') || '';
    var blockedMessage = root.getAttribute('data-blocked-message') || '';
    var promoMessage = root.getAttribute('data-promo-message') || '';
    var csrfToken = (form.querySelector('[name=_token]') || {}).value
        || (document.querySelector('meta[name="csrf-token"]') || {}).content
        || '';
    var submitting = false;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function money(n) {
        return '€' + (Number(n) || 0).toFixed(2);
    }

    function calcNet(gross) {
        var fee = Math.round((gross * feePercent) / 100 * 100) / 100;
        var net = Math.round((gross - fee) * 100) / 100;
        return { fee: fee, net: net };
    }

    function showError(message) {
        if (typeof window.slbAlert === 'function') {
            window.slbAlert({ icon: 'error', title: 'Check your details', text: message, toast: false });
            return;
        }
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(message, 'error');
        }
    }

    function showSuccess(title, text) {
        if (typeof window.slbAlert === 'function') {
            return window.slbAlert({ icon: 'success', title: title, text: text || '', toast: false });
        }
        return Promise.resolve();
    }

    function updatePreview() {
        var amount = parseFloat(amountInput && amountInput.value) || 0;
        if (amount > maxAmount) amount = maxAmount;
        if (amount < 0) amount = 0;
        var parts = calcNet(amount);
        if (previewGross) previewGross.textContent = money(amount);
        if (previewFee) previewFee.textContent = money(parts.fee);
        if (previewAmount) previewAmount.textContent = money(parts.net);
    }

    function currentMethod() {
        return paymentMethod ? paymentMethod.value : '';
    }

    function togglePaymentFields() {
        var method = currentMethod();
        document.querySelectorAll('.payout-fields').forEach(function (el) {
            el.classList.add('d-none');
        });
        if (method === 'bank') document.getElementById('bankFields')?.classList.remove('d-none');
        if (method === 'paypal') document.getElementById('paypalFields')?.classList.remove('d-none');
        if (method === 'wise') document.getElementById('wiseFields')?.classList.remove('d-none');
        if (method === 'crypto') document.getElementById('cryptoFields')?.classList.remove('d-none');
    }

    function summaryHtml(amount, method) {
        var parts = calcNet(amount);
        var details = '';
        if (method === 'bank') {
            details = '<p class="mb-1"><strong>Bank:</strong> ' + escapeHtml(form.bank_name && form.bank_name.value) + '</p>'
                + '<p class="mb-1"><strong>Holder:</strong> ' + escapeHtml(form.account_holder && form.account_holder.value) + '</p>'
                + '<p class="mb-1"><strong>Account:</strong> ' + escapeHtml(form.account_number && form.account_number.value) + '</p>';
        } else if (method === 'paypal') {
            details = '<p class="mb-1"><strong>PayPal:</strong> ' + escapeHtml(form.paypal_email && form.paypal_email.value) + '</p>';
        } else if (method === 'wise') {
            details = '<p class="mb-1"><strong>Wise:</strong> ' + escapeHtml(form.wise_email && form.wise_email.value) + '</p>';
        } else if (method === 'crypto') {
            details = '<p class="mb-1"><strong>Coin:</strong> ' + escapeHtml(form.crypto_type && form.crypto_type.value) + '</p>'
                + '<p class="mb-1"><strong>Wallet:</strong> ' + escapeHtml(form.wallet_address && form.wallet_address.value) + '</p>';
        }
        var feeLine = feePercent > 0
            ? '<p class="mb-1"><strong>Fee (' + escapeHtml(feePercent) + '%):</strong> ' + money(parts.fee) + '</p>'
            : '';
        return '<div style="text-align:left">'
            + '<p class="mb-1"><strong>Requested:</strong> ' + money(amount) + '</p>'
            + feeLine
            + '<p><strong>You will receive:</strong> ' + money(parts.net) + '</p>'
            + '<hr>'
            + details
            + (!payoutLocked ? '<p class="text-muted small mt-2 mb-0">These payout details will lock after this request. Contact support to change them later.</p>' : '')
            + '</div>';
    }

    function validateForm() {
        if (formBlocked) {
            showError(blockedMessage);
            return false;
        }

        var amount = parseFloat(amountInput && amountInput.value) || 0;
        var method = currentMethod();

        if (amount < minAmount) {
            showError('Minimum withdrawal amount is €' + minAmount.toFixed(2) + '.');
            return false;
        }
        if (amount > maxAmount) {
            showError(maxAmount <= 0 ? promoMessage : 'Maximum withdrawal amount is €' + maxAmount.toFixed(2) + '.');
            return false;
        }
        if (!method) {
            showError('Please select a payment method');
            return false;
        }

        if (!payoutLocked) {
            if (form.details_confirmed && !form.details_confirmed.checked) {
                showError('Please confirm you have double-checked your payout details.');
                return false;
            }
            if (method === 'bank') {
                if (!form.bank_name.value || !form.account_holder.value || !form.account_number.value) {
                    showError('Please fill in all bank details');
                    return false;
                }
                if (form.account_number.value !== form.account_number_confirm.value) {
                    showError('IBAN / account numbers must match.');
                    return false;
                }
            }
            if (method === 'paypal') {
                if (!form.paypal_email.value) {
                    showError('Please enter your PayPal email');
                    return false;
                }
                if (form.paypal_email.value !== form.paypal_email_confirm.value) {
                    showError('PayPal emails must match.');
                    return false;
                }
            }
            if (method === 'wise') {
                if (!form.wise_email.value) {
                    showError('Please enter your Wise email');
                    return false;
                }
                if (form.wise_email.value !== form.wise_email_confirm.value) {
                    showError('Wise emails must match.');
                    return false;
                }
            }
            if (method === 'crypto') {
                if (!form.wallet_address.value) {
                    showError('Please enter your wallet address');
                    return false;
                }
                if (form.wallet_address.value !== form.wallet_address_confirm.value) {
                    showError('Wallet addresses must match.');
                    return false;
                }
            }
        }

        return true;
    }

    function statusBadgeClass(status) {
        if (status === 'completed') return 'status-paid';
        if (status === 'cancelled') return 'status-rejected';
        return 'status-pending';
    }

    function formatDate(iso) {
        if (!iso) return '—';
        var d = new Date(iso);
        if (Number.isNaN(d.getTime())) return escapeHtml(iso);
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function renderPager(pager, pageData) {
        if (!pager) return;
        if (pageData.last_page > 1) {
            pager.classList.remove('d-none');
            var links = '';
            for (var p = 1; p <= pageData.last_page; p++) {
                links += '<button type="button" class="btn btn-sm '
                    + (p === pageData.current_page ? 'btn-primary' : 'btn-outline-secondary')
                    + ' me-1 btn-history-page" data-page="' + p + '">' + p + '</button>';
            }
            pager.innerHTML = links;
        } else {
            pager.classList.add('d-none');
        }
    }

    function loadHistory(page) {
        var body = document.getElementById('withdrawalsHistoryBody');
        var pager = document.getElementById('withdrawalsPagination');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr>';
        fetch(historyUrl + '?page=' + page, {
            headers: { 'Accept': 'application/json' },
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, data: data };
            });
        }).then(function (result) {
            if (!result.data || !result.data.success) {
                throw new Error((result.data && result.data.message) || 'Failed');
            }
            var pageData = result.data.data;
            var rows = pageData.data || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">'
                    + '<i class="fa fa-receipt fa-2x mb-2 d-block opacity-50"></i>No withdrawal requests yet'
                    + '</td></tr>';
                if (pager) pager.classList.add('d-none');
                return;
            }
            body.innerHTML = rows.map(function (w) {
                var feeNote = Number(w.fee) > 0
                    ? '<div class="small text-muted">Fee ' + money(w.fee) + ' · Net ' + money(w.net_amount) + '</div>'
                    : (Number(w.net_amount) !== Number(w.amount)
                        ? '<div class="small text-muted">Net ' + money(w.net_amount) + '</div>'
                        : '');
                var cancelBtn = w.cancellable
                    ? '<button type="button" class="btn btn-sm btn-outline-danger btn-cancel-withdrawal" data-id="' + escapeHtml(w.id) + '">Cancel</button>'
                    : '';
                var copyBtn = w.destination_copy_text
                    ? '<button type="button" class="btn btn-sm btn-outline-secondary btn-copy-destination" data-copy="' + escapeHtml(w.destination_copy_text) + '">Copy</button>'
                    : '';
                return '<tr>'
                    + '<td class="small fw-semibold">' + escapeHtml(w.reference) + '</td>'
                    + '<td class="small">' + formatDate(w.created_at) + '</td>'
                    + '<td class="fw-semibold">' + money(w.amount) + feeNote + '</td>'
                    + '<td class="small text-muted">' + escapeHtml(w.destination_snippet) + '</td>'
                    + '<td><span class="badge ' + statusBadgeClass(w.status) + '">' + escapeHtml(w.status_label) + '</span></td>'
                    + '<td class="text-end"><div class="d-flex gap-1 justify-content-end flex-wrap">' + copyBtn + cancelBtn + '</div></td>'
                    + '</tr>';
            }).join('');
            renderPager(pager, pageData);
        }).catch(function () {
            body.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load history.</td></tr>';
        });
    }

    amountInput && amountInput.addEventListener('input', updatePreview);
    paymentMethod && paymentMethod.addEventListener('change', togglePaymentFields);
    updatePreview();
    togglePaymentFields();

    document.getElementById('withdrawalsPagination')?.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-history-page');
        if (!btn) return;
        loadHistory(Number(btn.dataset.page) || 1);
    });

    document.getElementById('withdrawalsHistoryBody')?.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.btn-copy-destination');
        if (copyBtn) {
            navigator.clipboard.writeText(copyBtn.dataset.copy || '').then(function () {
                if (typeof window.slbAlert === 'function') {
                    window.slbAlert({ icon: 'success', title: 'Copied' });
                }
            }).catch(function () {
                showError('Could not copy to clipboard.');
            });
            return;
        }

        var cancelBtn = e.target.closest('.btn-cancel-withdrawal');
        if (!cancelBtn) return;
        var id = cancelBtn.dataset.id;
        var confirmFn = window.slbConfirm;
        var proceed = typeof confirmFn === 'function'
            ? confirmFn({
                title: 'Cancel this withdrawal?',
                text: 'The full amount will be returned to your wallet.',
                confirmText: 'Yes, cancel',
                icon: 'warning',
                danger: true,
            })
            : Promise.resolve(true);

        proceed.then(function (ok) {
            if (!ok) return;
            fetch(cancelUrlTemplate.replace('__ID__', id), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            }).then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            }).then(function (result) {
                if (!result.data || !result.data.success) {
                    showError((result.data && result.data.message) || 'Cancel failed.');
                    return;
                }
                return showSuccess('Cancelled', result.data.message).then(function () {
                    window.location.reload();
                });
            }).catch(function () {
                showError('Network error. Please try again.');
            });
        });
    });

    submitBtn && submitBtn.addEventListener('click', function () {
        if (submitting || formBlocked) return;
        if (!validateForm()) return;

        var amount = parseFloat(amountInput.value);
        var method = currentMethod();
        var confirmFn = window.slbConfirm;
        var proceed = typeof confirmFn === 'function'
            ? confirmFn({
                title: 'Confirm withdrawal',
                html: summaryHtml(amount, method),
                confirmText: 'Yes, withdraw',
                cancelText: 'Cancel',
                icon: 'question',
            })
            : Promise.resolve(true);

        proceed.then(function (ok) {
            if (!ok) return;
            submitting = true;
            submitBtn.disabled = true;

            var formData = new FormData(form);
            if (payoutLocked) {
                ['bank_name', 'account_holder', 'account_number', 'swift_code', 'paypal_email', 'wise_email', 'crypto_type', 'wallet_address']
                    .forEach(function (name) {
                        var el = form.elements.namedItem(name);
                        if (el && el.disabled && el.value) formData.set(name, el.value);
                    });
            }

            fetch(requestUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: formData,
            }).then(function (response) {
                return response.json().then(function (data) {
                    return { response: response, data: data };
                }).catch(function () {
                    return { response: response, data: null };
                });
            }).then(function (result) {
                if (result.data && result.data.success) {
                    return showSuccess('Submitted', result.data.message).then(function () {
                        window.location.reload();
                    });
                }
                submitting = false;
                submitBtn.disabled = formBlocked;
                if (typeof window.slbHandleHttpError === 'function') {
                    window.slbHandleHttpError({
                        status: result.response.status,
                        data: result.data,
                    });
                    return;
                }
                showError((result.data && result.data.message) || 'Withdrawal failed.');
            }).catch(function (err) {
                submitting = false;
                submitBtn.disabled = formBlocked;
                if (typeof window.slbHandleHttpError === 'function') {
                    window.slbHandleHttpError(err);
                    return;
                }
                showError('Network error. Please try again.');
            });
        });
    });
})();
