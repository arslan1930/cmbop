/**
 * Staff site editor: Verify / Unverify / Activate / Deactivate.
 * Posts to the same JSON endpoints as Sites Management.
 */
(function (global) {
    'use strict';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function staffBase(el) {
        var root = el.closest('[data-staff-site-status]');
        var raw = root ? root.getAttribute('data-staff-base') : '';
        return String(raw || '').replace(/\/+$/, '');
    }

    function toast(message, type) {
        if (typeof global.showAppToast === 'function') {
            global.showAppToast(String(message || ''), type || 'success');
            return;
        }
        if (typeof global.slbAlert === 'function') {
            global.slbAlert({
                icon: type === 'error' ? 'error' : (type === 'warning' ? 'warning' : 'success'),
                title: String(message || ''),
            });
        }
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, data: data || {} };
            }).catch(function () {
                return { ok: false, data: { message: 'Request failed (' + res.status + ')' } };
            });
        });
    }

    function askReason(title, text, confirmText) {
        if (global.Swal && typeof global.Swal.fire === 'function') {
            return global.Swal.fire({
                title: title,
                text: text,
                icon: 'question',
                input: 'textarea',
                inputPlaceholder: 'Reason (min. 10 characters)',
                inputAttributes: { 'aria-label': 'Reason', maxlength: '1000' },
                showCancelButton: true,
                confirmButtonText: confirmText,
                preConfirm: function (value) {
                    var reason = String(value || '').trim();
                    if (reason.length < 10) {
                        global.Swal.showValidationMessage('Please enter a reason (at least 10 characters).');
                        return false;
                    }
                    if (reason.length > 1000) {
                        global.Swal.showValidationMessage('Reason must be 1000 characters or fewer.');
                        return false;
                    }
                    return reason;
                },
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return null;
                }
                return String(result.value || '').trim();
            });
        }

        var reason = String(global.prompt(title + '\n\n' + text, '') || '').trim();
        if (reason.length < 10) {
            return Promise.resolve(null);
        }
        return Promise.resolve(reason);
    }

    function confirmVerify() {
        if (typeof global.slbConfirm === 'function') {
            return global.slbConfirm({
                title: 'Verify Site?',
                text: 'Are you sure you want to verify this site?',
                icon: 'question',
                confirmText: 'Yes, verify',
            });
        }
        return Promise.resolve(!!global.confirm('Verify this site?'));
    }

    function confirmActivate(btn) {
        var name = btn.getAttribute('data-name') || '';
        var id = btn.getAttribute('data-id') || '';
        var opts = {
            looksEnglish: btn.getAttribute('data-description-english') !== '0',
            excerpt: btn.getAttribute('data-description-excerpt') || '',
            name: name,
            confirmText: 'Activate',
            editUrl: staffBase(btn) + '/sites/' + id + '/edit#description',
        };
        if (typeof global.slbConfirmActivate === 'function') {
            return global.slbConfirmActivate(opts);
        }
        if (typeof global.slbConfirm === 'function') {
            return global.slbConfirm({
                title: 'Activate Site?',
                text: name ? 'Make "' + name + '" live in the catalog?' : 'Activate this site?',
                icon: 'question',
                confirmText: 'Activate',
            });
        }
        return Promise.resolve(!!global.confirm('Activate this site?'));
    }

    function handleResult(result, fallback) {
        if (result.ok && result.data && result.data.success) {
            global.location.reload();
            return;
        }
        toast((result.data && result.data.message) || fallback, 'error');
    }

    if (global.__staffSiteStatusBound) {
        return;
    }
    global.__staffSiteStatusBound = true;

    document.addEventListener('click', function (e) {
        var verifyBtn = e.target.closest('.js-staff-verify');
        if (verifyBtn) {
            e.preventDefault();
            var verifyId = verifyBtn.getAttribute('data-id');
            var approving = verifyBtn.getAttribute('data-verified') === '1';
            var verifyUrl = staffBase(verifyBtn) + '/sites/' + verifyId + '/verify';
            var go = approving
                ? confirmVerify()
                : askReason(
                    'Unverify Site?',
                    'Explain why verification is being removed. The publisher will see this reason.',
                    'Yes, unverify'
                ).then(function (reason) {
                    return reason ? { reason: reason } : null;
                });

            Promise.resolve(go).then(function (ok) {
                if (!ok) {
                    return;
                }
                var payload = { verified: approving ? 1 : 0 };
                if (!approving && ok.reason) {
                    payload.reason = ok.reason;
                }
                return postJson(verifyUrl, payload).then(function (result) {
                    handleResult(result, approving ? 'Could not verify site' : 'Could not unverify site');
                });
            }).catch(function () {
                toast(approving ? 'Could not verify site' : 'Could not unverify site', 'error');
            });
            return;
        }

        var activateBtn = e.target.closest('.js-staff-activate');
        if (activateBtn) {
            e.preventDefault();
            var activateId = activateBtn.getAttribute('data-id');
            var activateUrl = staffBase(activateBtn) + '/sites/' + activateId + '/active';
            confirmActivate(activateBtn).then(function (ok) {
                if (!ok) {
                    return;
                }
                return postJson(activateUrl, { active: 1 }).then(function (result) {
                    handleResult(result, 'Could not activate site');
                });
            }).catch(function () {
                toast('Could not activate site', 'error');
            });
            return;
        }

        var deactivateBtn = e.target.closest('.js-staff-deactivate');
        if (deactivateBtn) {
            e.preventDefault();
            var deactivateId = deactivateBtn.getAttribute('data-id');
            var deactivateUrl = staffBase(deactivateBtn) + '/sites/' + deactivateId + '/active';
            askReason(
                'Deactivate Site?',
                'Explain why this listing is being deactivated. The publisher will see this reason in email and notifications.',
                'Yes, deactivate'
            ).then(function (reason) {
                if (!reason) {
                    return;
                }
                return postJson(deactivateUrl, { active: 0, reason: reason }).then(function (result) {
                    handleResult(result, 'Could not deactivate site');
                });
            }).catch(function () {
                toast('Could not deactivate site', 'error');
            });
        }
    });
})(window);
