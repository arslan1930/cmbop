(function () {
    if (window.__slbPromoTrack) {
        return;
    }
    window.__slbPromoTrack = true;

    var csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var url = document.querySelector('[data-promo-track-url]')?.getAttribute('data-promo-track-url');
    if (!url) {
        return;
    }

    function send(id) {
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                subject_type: 'banner',
                subject_id: id,
                event: 'impression'
            }),
            credentials: 'same-origin'
        }).catch(function () {});
    }

    var seen = {};
    if (!('IntersectionObserver' in window)) {
        document.querySelectorAll('[data-track-banner]').forEach(function (el) {
            var id = parseInt(el.getAttribute('data-track-banner'), 10);
            if (id) {
                send(id);
            }
        });
        return;
    }

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting || entry.intersectionRatio < 0.5) {
                return;
            }
            var id = parseInt(entry.target.getAttribute('data-track-banner'), 10);
            if (!id || seen[id]) {
                return;
            }
            seen[id] = true;
            send(id);
            io.unobserve(entry.target);
        });
    }, { threshold: 0.5 });

    document.querySelectorAll('[data-track-banner]').forEach(function (el) {
        io.observe(el);
    });
})();
