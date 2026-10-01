/* Admin content library live search — boot from window.AdminLibraryBoot */
(function () {
    const boot = window.AdminLibraryBoot || {};
    const form = document.getElementById('adminLibraryFilterForm');
    const region = document.getElementById('adminLibraryLiveRegion');
    const resultsUrl = boot.resultsUrl || '';
    if (!form || !region || !resultsUrl) {
        return;
    }

    let timer = null;
    let abort = null;

    function paramsFromForm() {
        const next = new URLSearchParams();
        const fd = new FormData(form);
        fd.forEach(function (value, key) {
            const v = String(value == null ? '' : value).trim();
            if (v !== '' && v !== 'all') {
                next.append(key, String(value));
            }
        });
        return next;
    }

    function fetchResults(params, push) {
        if (abort) {
            abort.abort();
        }
        abort = new AbortController();
        const href = resultsUrl + (params.toString() ? '?' + params.toString() : '');
        fetch(href, {
            headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: abort.signal,
        })
            .then(function (res) {
                if (!res.ok) {
                    throw new Error('library-results');
                }
                return res.text();
            })
            .then(function (html) {
                region.innerHTML = html;
                if (push && window.history && boot.indexUrl) {
                    const indexHref = boot.indexUrl + (params.toString() ? '?' + params.toString() : '');
                    window.history.replaceState({}, '', indexHref);
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
            });
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            fetchResults(paramsFromForm(), true);
        }, 250);
    }

    form.addEventListener('submit', function (e) {
        if (e.submitter && e.submitter.getAttribute('type') === 'submit') {
            // Keep Apply as a full navigation when JS is mid-flight; still intercept.
        }
        e.preventDefault();
        fetchResults(paramsFromForm(), true);
    });

    ['adminContentLibrarySearch', 'adminLibraryAdvertiser'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', schedule);
        }
    });

    ['adminLibraryCountry', 'adminLibraryLanguage', 'adminLibrarySort', 'adminLibraryAttachment', 'adminLibraryExpiring'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', function () {
                fetchResults(paramsFromForm(), true);
            });
        }
    });

    ['adminLibraryFrom', 'adminLibraryTo'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', schedule);
        }
    });

    region.addEventListener('change', function (e) {
        if (!e.target || e.target.id !== 'adminLibrarySelectPage') {
            return;
        }
        region.querySelectorAll('input[name="ids[]"]').forEach(function (box) {
            box.checked = !!e.target.checked;
        });
    });

    region.addEventListener('click', function (e) {
        const chip = e.target.closest('#adminLibraryChips a');
        const pageLink = e.target.closest('.pagination a');
        const link = chip || pageLink;
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        e.preventDefault();
        try {
            const url = new URL(link.href, window.location.origin);
            if (chip) {
                const availability = url.searchParams.get('availability') || 'all';
                const hidden = document.getElementById('adminLibraryAvailability');
                if (hidden) {
                    hidden.value = availability;
                }
                fetchResults(paramsFromForm(), true);
                return;
            }
            fetchResults(url.searchParams, true);
        } catch (err) {
            window.location.href = link.href;
        }
    });
})();
