/**
 * Confirm before switching active role (SweetAlert when available).
 */
(function () {
    function bindRoleSwitchForms(root) {
        (root || document).querySelectorAll('.role-switch-form').forEach(function (form) {
            if (form.dataset.slbRoleSwitchBound === '1') return;
            form.dataset.slbRoleSwitchBound = '1';
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var btn = form.querySelector('.role-switch-btn');
                var roleName = (btn && btn.dataset.roleName) || 'the other role';
                var proceed = function () {
                    // Native submit after confirm (avoid re-triggering this handler loop)
                    HTMLFormElement.prototype.submit.call(form);
                };

                if (window.Swal && typeof window.Swal.fire === 'function') {
                    window.Swal.fire({
                        title: 'Switch role?',
                        html: 'You are about to switch to <strong>' + roleName + '</strong>. Your current page will change to that workspace.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Switch to ' + roleName,
                        cancelButtonText: 'Stay here',
                        confirmButtonColor: '#185054',
                        cancelButtonColor: '#6b7280',
                        reverseButtons: true,
                    }).then(function (result) {
                        if (result.isConfirmed) proceed();
                    });
                    return;
                }

                if (window.confirm('Switch to ' + roleName + '?')) {
                    proceed();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bindRoleSwitchForms(document); });
    } else {
        bindRoleSwitchForms(document);
    }

    window.slbBindRoleSwitchForms = bindRoleSwitchForms;
})();
