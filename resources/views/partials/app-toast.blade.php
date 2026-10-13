{{-- Shared app toast: window.showAppToast(message, type, { actionLabel, onAction, delay }) --}}
<script>
(function () {
    function escapeToastHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.showAppToast = function showAppToast(message, type, options) {
        options = options || {};
        type = type || 'success';

        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            toastContainer.className = 'slb-toast-stack';
            document.body.appendChild(toastContainer);
        }

        const toastId = 'toast-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
        const normalized = String(type || 'success').toLowerCase();
        const isError = normalized === 'error' || normalized === 'danger';
        const isSuccess = normalized === 'success';
        const isInfo = normalized === 'info';
        const bgClass = isSuccess ? 'bg-success'
            : (isError ? 'bg-danger' : (isInfo ? 'bg-info' : 'bg-warning'));
        const solid = isSuccess || isError;
        const textClass = solid ? 'text-white' : 'text-dark';
        const closeClass = solid ? 'btn-close btn-close-white' : 'btn-close';

        // The theme flattens warning/info toasts to white surfaces on purpose —
        // "the icon carries the signal". Without an icon a warning was
        // indistinguishable from an info message, so render one.
        const quiet = !!options.quiet && !isError;
        const icon = isSuccess ? 'fa-circle-check'
            : (isError ? 'fa-circle-exclamation'
                : (isInfo ? 'fa-circle-info' : 'fa-triangle-exclamation'));
        const iconTone = quiet ? ' text-brand-primary'
            : (solid ? '' : (isInfo ? ' text-brand-live' : ' text-brand-warning'));
        const iconHtml = `<i class="fa-solid ${icon} app-toast-icon${iconTone}" aria-hidden="true"></i>`;
        const delay = typeof options.delay === 'number' ? options.delay : (options.actionLabel ? 6000 : (quiet ? 1800 : 3000));
        const actionLabel = options.actionLabel ? String(options.actionLabel) : '';
        const actionBtnClass = (solid && !quiet) ? 'btn btn-sm btn-light' : 'btn btn-sm btn-outline-secondary';
        const actionHtml = actionLabel
            ? `<button type="button" class="${actionBtnClass} ms-2 py-0 px-2 app-toast-action" data-toast-action>${escapeToastHtml(actionLabel)}</button>`
            : '';
        const toastTone = quiet
            ? 'app-toast-quiet text-dark border'
            : `${textClass} ${bgClass} border-0`;
        const closeBtnClass = quiet ? 'btn-close' : closeClass;

        toastContainer.insertAdjacentHTML('beforeend', `
            <div id="${toastId}" class="toast align-items-center ${toastTone}" role="${isError ? 'alert' : 'status'}" data-bs-autohide="true" data-bs-delay="${delay}">
                <div class="d-flex align-items-center">
                    <div class="toast-body d-flex align-items-center gap-2">
                        ${iconHtml}
                        <span class="app-toast-message">${escapeToastHtml(message)}</span>
                        ${actionHtml}
                    </div>
                    <button type="button" class="${closeBtnClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `);

        const toastElement = document.getElementById(toastId);
        const actionBtn = toastElement.querySelector('[data-toast-action]');
        if (actionBtn && typeof options.onAction === 'function') {
            actionBtn.addEventListener('click', function () {
                try { options.onAction(); } catch (e) { console.error(e); }
                const instance = bootstrap.Toast.getInstance(toastElement);
                if (instance) instance.hide();
            });
        }

        const toast = new bootstrap.Toast(toastElement, { delay: delay, autohide: true });
        toast.show();
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
            if (toastContainer && !toastContainer.querySelector('.toast')) {
                toastContainer.remove();
            }
        });
    };

    // Backward-compatible alias used across advertiser pages
    window.showToast = function (message, type, options) {
        return window.showAppToast(message, type, options);
    };

    // Shared flash banners: success is a quiet toast everywhere (save / update / delete).
    try {
        document.querySelectorAll('[data-slb-flash-toast]').forEach(function (el) {
            var type = el.getAttribute('data-slb-flash-toast') || 'success';
            var body = el.querySelector('.flex-grow-1');
            var message = body ? body.textContent.trim() : '';
            if (!message || typeof bootstrap === 'undefined' || !bootstrap.Toast) return;
            window.showAppToast(message, type, { quiet: type === 'success' });
            el.remove();
            var stack = document.querySelector('[data-slb-flash]');
            if (stack && !stack.querySelector('.alert')) {
                stack.remove();
            }
        });
    } catch (e) {}
})();
</script>
