/**
 * Shared confirm / alert helpers (SweetAlert2 when available, native fallback).
 *
 * Usage:
 *   const ok = await slbConfirm({ title: 'Delete?', text: '...', danger: true });
 *   await slbAlert({ icon: 'success', title: 'Deleted' });
 *
 * Declarative (forms / submit buttons):
 *   <form data-slb-confirm="Delete this item?" data-slb-confirm-title="Delete?" data-slb-confirm-danger="1">
 *   <button type="submit" data-slb-confirm="Cancel this order?" data-slb-confirm-text="Cancel order">
 */
(function (global) {
    'use strict';

    var BRAND = '#185054';
    var DANGER = '#b91c1c';
    var MUTED = '#6b7280';

    function hasSwal() {
        return !!(global.Swal && typeof global.Swal.fire === 'function');
    }

    /**
     * @param {object} opts
     * @param {string} [opts.title]
     * @param {string} [opts.text]
     * @param {string} [opts.html]
     * @param {string} [opts.icon] warning|question|info|error|success
     * @param {string} [opts.confirmText]
     * @param {string} [opts.cancelText]
     * @param {boolean} [opts.danger]
     * @returns {Promise<boolean>}
     */
    function slbConfirm(opts) {
        opts = opts || {};
        var title = opts.title || 'Please confirm';
        var text = opts.text || opts.message || '';
        var confirmText = opts.confirmText || opts.confirmButtonText || 'Confirm';
        var cancelText = opts.cancelText || opts.cancelButtonText || 'Cancel';
        var icon = opts.icon || (opts.danger ? 'warning' : 'question');
        var danger = !!opts.danger;

        if (hasSwal()) {
            return global.Swal.fire({
                title: title,
                text: text || undefined,
                html: opts.html || undefined,
                icon: icon,
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: cancelText,
                confirmButtonColor: danger ? DANGER : BRAND,
                cancelButtonColor: MUTED,
                reverseButtons: true,
                focusCancel: !!danger,
            }).then(function (result) {
                return !!(result && result.isConfirmed);
            });
        }

        var message = [title, text].filter(Boolean).join('\n\n');
        return Promise.resolve(!!global.confirm(message));
    }

    /**
     * @param {object} opts
     * @returns {Promise<void>}
     */
    function slbAlert(opts) {
        opts = opts || {};
        var title = opts.title || opts.text || 'Done';
        var text = opts.title && opts.text ? opts.text : (opts.title ? '' : (opts.text || ''));
        var icon = opts.icon || 'info';
        var toast = opts.toast !== false && (opts.toast === true || icon === 'success' || icon === 'error');

        if (hasSwal()) {
            if (toast) {
                return global.Swal.fire({
                    toast: true,
                    position: opts.position || 'top-end',
                    icon: icon,
                    title: title,
                    showConfirmButton: false,
                    timer: opts.timer || 2200,
                    timerProgressBar: true,
                });
            }
            return global.Swal.fire({
                icon: icon,
                title: title,
                text: text || undefined,
                confirmButtonColor: BRAND,
            });
        }

        if (typeof global.showToast === 'function') {
            global.showToast(title, icon === 'error' ? 'error' : (icon === 'warning' ? 'warning' : 'success'));
            return Promise.resolve();
        }

        global.alert(text ? title + '\n\n' + text : title);
        return Promise.resolve();
    }

    function readConfirmOpts(el) {
        if (!el || !el.getAttribute) return null;
        var text = el.getAttribute('data-slb-confirm');
        if (!text) return null;
        return {
            title: el.getAttribute('data-slb-confirm-title') || 'Please confirm',
            text: text,
            confirmText: el.getAttribute('data-slb-confirm-text') || 'Confirm',
            cancelText: el.getAttribute('data-slb-confirm-cancel') || 'Cancel',
            danger: el.getAttribute('data-slb-confirm-danger') === '1'
                || el.getAttribute('data-slb-confirm-danger') === 'true',
            icon: el.getAttribute('data-slb-confirm-icon') || undefined,
        };
    }

    function bindDeclarativeConfirms() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.slbAllowSubmit === '1') {
                delete form.dataset.slbAllowSubmit;
                return;
            }

            var submitter = e.submitter || null;
            var source = null;
            if (submitter && submitter.hasAttribute && submitter.hasAttribute('data-slb-confirm')) {
                source = submitter;
            } else if (form.hasAttribute('data-slb-confirm')) {
                source = form;
            }
            if (!source) return;

            var opts = readConfirmOpts(source);
            if (!opts) return;

            e.preventDefault();
            e.stopPropagation();

            slbConfirm(opts).then(function (ok) {
                if (!ok) return;
                form.dataset.slbAllowSubmit = '1';
                if (submitter && typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter);
                } else if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    HTMLFormElement.prototype.submit.call(form);
                }
            });
        }, true);
    }

    global.slbConfirm = slbConfirm;
    global.slbAlert = slbAlert;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindDeclarativeConfirms);
    } else {
        bindDeclarativeConfirms();
    }
})(typeof window !== 'undefined' ? window : this);
