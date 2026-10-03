(function () {
    var cache = {};
    var urlByClass = {};
    var mapReady = false;
    var queue = [];
    var queued = false;
    var sel = '.fa,.fas,.far,.fal,.fab,.fa-solid,.fa-regular,.fa-brands,.fa-classic';
    var skipClass = /^(fa|fas|far|fal|fab|fa-solid|fa-regular|fa-brands|fa-classic|fa-fw|fa-sm|fa-lg|fa-xl|fa-2x|fa-3x|fa-spin|slb-icon-svg|m-draw|nav-icon-heart)$/;
    var CHUNK = 12;
    var BUDGET_MS = 5;
    var cssBase = (function () {
        var links = document.querySelectorAll('link[rel="stylesheet"][href*="slb-icons.css"]');
        var href = links.length ? links[links.length - 1].href : (window.location.origin + '/assets/css/slb-icons.css');
        return href;
    })();

    function nowMs() {
        return (window.performance && performance.now) ? performance.now() : Date.now();
    }

    function hydrateMapFromSheets() {
        if (mapReady) {
            return;
        }
        var sheets = document.styleSheets;
        var i;
        var j;
        var k;
        var sheet;
        var rules;
        var rule;
        var raw;
        var match;
        var selectors;
        var cls;
        for (i = 0; i < sheets.length; i++) {
            sheet = sheets[i];
            if (!sheet.href || String(sheet.href).indexOf('slb-icons.css') === -1) {
                continue;
            }
            try {
                rules = sheet.cssRules;
            } catch (e) {
                continue;
            }
            if (!rules) {
                continue;
            }
            for (j = 0; j < rules.length; j++) {
                rule = rules[j];
                if (!rule.style || !rule.selectorText) {
                    continue;
                }
                raw = rule.style.getPropertyValue('--slb-icon');
                if (!raw) {
                    continue;
                }
                match = String(raw).match(/url\(\s*["']?([^"')]+)["']?\s*\)/);
                if (!match) {
                    continue;
                }
                selectors = String(rule.selectorText).split(',');
                for (k = 0; k < selectors.length; k++) {
                    cls = selectors[k].trim().match(/\.fa-[\w-]+/);
                    if (!cls) {
                        continue;
                    }
                    cls = cls[0].slice(1);
                    if (skipClass.test(cls) || urlByClass[cls]) {
                        continue;
                    }
                    urlByClass[cls] = match[1];
                }
            }
        }
        mapReady = true;
    }

    function iconClassName(el) {
        var classes = el.classList;
        var i;
        var name;
        for (i = 0; i < classes.length; i++) {
            name = classes[i];
            if (name.indexOf('fa-') === 0 && !skipClass.test(name)) {
                return name;
            }
        }
        return '';
    }

    function rawIconUrl(el) {
        var key = iconClassName(el);
        if (key && Object.prototype.hasOwnProperty.call(urlByClass, key)) {
            return urlByClass[key];
        }
        if (!mapReady) {
            hydrateMapFromSheets();
            if (key && Object.prototype.hasOwnProperty.call(urlByClass, key)) {
                return urlByClass[key];
            }
        }
        var raw = window.getComputedStyle(el).getPropertyValue('--slb-icon').trim();
        var match = raw.match(/url\(\s*["']?([^"')]+)["']?\s*\)/);
        var url = match ? match[1] : '';
        if (key) {
            urlByClass[key] = url;
        }
        return url;
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

    function enqueue(el) {
        if (!el || el.nodeType !== 1 || el.dataset.slbQueued === '1') {
            return;
        }
        if (el.closest && el.closest('.slb-chat-mark, .slb-live-chat, .slb-tawk-theme-header')) {
            return;
        }
        if (el.querySelector && el.querySelector('svg')) {
            markDrawHost(el);
            return;
        }
        el.dataset.slbQueued = '1';
        queue.push(el);
    }

    function scheduleDrain() {
        if (queued || !queue.length) {
            return;
        }
        queued = true;
        var run = function (deadline) {
            queued = false;
            drain(deadline);
        };
        if (window.requestIdleCallback) {
            window.requestIdleCallback(run, { timeout: 240 });
        } else if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () { run(null); });
        } else {
            window.setTimeout(function () { run(null); }, 32);
        }
    }

    function drain(deadline) {
        var start = nowMs();
        var n = 0;
        while (queue.length && n < CHUNK) {
            if (deadline && typeof deadline.timeRemaining === 'function' && n > 0 && deadline.timeRemaining() < 1) {
                break;
            }
            if (nowMs() - start > BUDGET_MS) {
                break;
            }
            var el = queue.shift();
            if (el && el.isConnected) {
                inline(el);
            }
            n += 1;
        }
        if (queue.length) {
            scheduleDrain();
        }
    }

    function collect(scope, out) {
        if (!scope) {
            return;
        }
        if (scope.nodeType === 1 && scope.matches && scope.matches(sel)) {
            out.push(scope);
        }
        if (!scope.querySelectorAll) {
            return;
        }
        var found = scope.querySelectorAll(sel);
        var i;
        for (i = 0; i < found.length; i++) {
            out.push(found[i]);
        }
    }

    function scan(root) {
        var list = [];
        collect(root && root.querySelectorAll ? root : document, list);
        var i;
        for (i = 0; i < list.length; i++) {
            enqueue(list[i]);
        }
        scheduleDrain();
    }

    function isOwnIconMutation(node) {
        if (!node || node.nodeType !== 1) {
            return true;
        }
        var tag = node.tagName;
        if (tag === 'SVG' || tag === 'PATH' || tag === 'CIRCLE' || tag === 'LINE' || tag === 'POLYLINE' || tag === 'RECT' || tag === 'ELLIPSE' || tag === 'POLYGON' || tag === 'G') {
            return true;
        }
        if (node.classList && node.classList.contains('slb-icon-svg')) {
            return true;
        }
        return false;
    }

    function boot() {
        hydrateMapFromSheets();
        var chrome = document.getElementById('sidebar');
        if (chrome) {
            scan(chrome);
        }
        var startRest = function () {
            scan(document);
        };
        if (window.requestIdleCallback) {
            window.requestIdleCallback(startRest, { timeout: 400 });
        } else {
            window.setTimeout(startRest, 80);
        }
        document.addEventListener('pointerenter', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }
            var el = target.closest(sel);
            if (!el) {
                return;
            }
            enqueue(el);
            inline(el);
        }, true);
        if (window.MutationObserver) {
            new MutationObserver(function (records) {
                var found = [];
                var r;
                var n;
                var node;
                for (r = 0; r < records.length; r++) {
                    for (n = 0; n < records[r].addedNodes.length; n++) {
                        node = records[r].addedNodes[n];
                        if (isOwnIconMutation(node)) {
                            continue;
                        }
                        collect(node, found);
                    }
                }
                if (!found.length) {
                    return;
                }
                for (n = 0; n < found.length; n++) {
                    enqueue(found[n]);
                }
                scheduleDrain();
            }).observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
