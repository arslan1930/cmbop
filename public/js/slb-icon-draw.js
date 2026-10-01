(function () {
    var cache = {};
    var sel = '.fa,.fas,.far,.fal,.fab,.fa-solid,.fa-regular,.fa-brands,.fa-classic';
    var cssBase = (function () {
        var links = document.querySelectorAll('link[rel="stylesheet"][href*="slb-icons.css"]');
        var href = links.length ? links[links.length - 1].href : (window.location.origin + '/assets/css/slb-icons.css');
        return href;
    })();

    function rawIconUrl(el) {
        var raw = window.getComputedStyle(el).getPropertyValue('--slb-icon').trim();
        var match = raw.match(/url\(\s*["']?([^"')]+)["']?\s*\)/);
        return match ? match[1] : '';
    }

    function resolveUrl(raw) {
        if (!raw) {
            return '';
        }
        try {
            return new URL(raw, cssBase).href;
        } catch (e) {
            if (raw.charAt(0) === '/') {
                return raw;
            }
            return window.location.origin + '/assets/icons/' + raw.replace(/^(\.\.\/)+icons\//, '');
        }
    }

    function prepare(svgText) {
        var wrap = document.createElement('div');
        wrap.innerHTML = String(svgText || '').trim();
        var svg = wrap.querySelector('svg');
        if (!svg) {
            return '';
        }
        svg.removeAttribute('width');
        svg.removeAttribute('height');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('fill', 'none');
        svg.querySelectorAll('[stroke]').forEach(function (node) {
            node.setAttribute('stroke', 'currentColor');
        });
        return svg.outerHTML;
    }

    function markDrawHost(el) {
        el.classList.add('slb-icon-svg');
        var host = el.closest('a,button,.dropdown-item,.btn');
        if (host && (host.classList.contains('bulk-draft-icon-btn') || host.classList.contains('staff-action-icon-btn'))) {
            return;
        }
        el.classList.add('m-draw');
        if (host) {
            host.classList.add('m-draw');
        }
    }

    function apply(el, svgText) {
        if (!svgText || el.querySelector('svg')) {
            if (el.querySelector('svg')) {
                markDrawHost(el);
            }
            return;
        }
        markDrawHost(el);
        el.insertAdjacentHTML('afterbegin', svgText);
    }

    function inline(el) {
        if (el.querySelector('svg')) {
            markDrawHost(el);
            return;
        }
        if (el.dataset.slbInlined === '1') {
            return;
        }
        var url = resolveUrl(rawIconUrl(el));
        if (!url) {
            return;
        }
        el.dataset.slbInlined = '1';
        if (!cache[url]) {
            cache[url] = fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.ok ? res.text() : Promise.reject(res.status); })
                .then(prepare)
                .catch(function () {
                    el.dataset.slbInlined = '';
                    return '';
                });
        }
        cache[url].then(function (svg) { apply(el, svg); });
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        if (scope.nodeType === 1 && scope.matches && scope.matches(sel)) {
            inline(scope);
        }
        if (scope.querySelectorAll) {
            scope.querySelectorAll(sel).forEach(inline);
        }
    }

    function boot() {
        scan(document);
        if (window.MutationObserver) {
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    record.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) {
                            scan(node);
                        }
                    });
                });
            }).observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
