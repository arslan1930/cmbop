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
            toastContainer.className = 'position-fixed bottom-0 end-0 p-3';
            toastContainer.style.zIndex = '1100';
            document.body.appendChild(toastContainer);
        }

        const toastId = 'toast-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
        const bgClass = type === 'success' ? 'bg-success'
            : (type === 'error' ? 'bg-danger' : 'bg-warning');
        const delay = typeof options.delay === 'number' ? options.delay : (options.actionLabel ? 6000 : 3000);
        const actionLabel = options.actionLabel ? String(options.actionLabel) : '';
        const actionHtml = actionLabel
            ? `<button type="button" class="btn btn-sm btn-light ms-2 py-0 px-2 app-toast-action" data-toast-action>${escapeToastHtml(actionLabel)}</button>`
            : '';

        toastContainer.insertAdjacentHTML('beforeend', `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert" data-bs-autohide="true" data-bs-delay="${delay}">
                <div class="d-flex align-items-center">
                    <div class="toast-body d-flex align-items-center flex-wrap gap-1">
                        <span>${escapeToastHtml(message)}</span>
                        ${actionHtml}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
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
        toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
    };

    // Backward-compatible alias used across advertiser pages
    window.showToast = function (message, type, options) {
        return window.showAppToast(message, type, options);
    };
})();
</script>
