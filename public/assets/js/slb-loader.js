(function (global) {
    'use strict';

    var anim = null;
    var count = 0;
    var lottieWaiters = [];
    var lottieStarted = false;
    var loaderScript = document.currentScript;
    var lottieSrc = (loaderScript && loaderScript.getAttribute('data-lottie-src')) || '';

    function flushLottieWaiters() {
        var queue = lottieWaiters.splice(0);
        queue.forEach(function (fn) {
            try { fn(); } catch (err) {}
        });
    }

    global.slbWhenLottie = function slbWhenLottie(fn) {
        if (typeof fn !== 'function') return;
        if (global.lottie && typeof global.lottie.loadAnimation === 'function') {
            fn();
            return;
        }
        lottieWaiters.push(fn);
        if (lottieStarted) return;
        lottieStarted = true;
        var src = lottieSrc || '/assets/vendor/lottie-web/lottie_light.min.js';
        var script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = flushLottieWaiters;
        script.onerror = flushLottieWaiters;
        (document.head || document.documentElement).appendChild(script);
    };

    function root() {
        return document.getElementById('slbPageLoader');
    }

    function host() {
        var el = root();
        return el ? el.querySelector('.slb-page-loader__anim') : null;
    }

    function reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function ensure() {
        var box = host();
        if (!box || anim || reduced() || !global.lottie || typeof global.lottie.loadAnimation !== 'function') {
            return anim;
        }
        var src = box.getAttribute('data-lottie');
        if (!src) return null;
        anim = global.lottie.loadAnimation({
            container: box,
            renderer: 'svg',
            loop: true,
            autoplay: false,
            path: src
        });
        return anim;
    }

    function show() {
        var el = root();
        if (!el) return;
        count += 1;
        el.hidden = false;
        el.classList.add('is-on');
        el.setAttribute('aria-hidden', 'false');
        var play = function () {
            var player = ensure();
            if (player && typeof player.goToAndPlay === 'function') {
                player.goToAndPlay(0, true);
            }
        };
        if (global.lottie && typeof global.lottie.loadAnimation === 'function') {
            play();
        } else if (typeof global.slbWhenLottie === 'function') {
            global.slbWhenLottie(play);
        }
    }

    function hide() {
        count = Math.max(0, count - 1);
        if (count > 0) return;
        var el = root();
        if (!el) return;
        el.classList.remove('is-on');
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        if (anim && typeof anim.stop === 'function') {
            anim.stop();
        }
    }

    function hideAll() {
        count = 1;
        hide();
    }

    function promoteDeferredStyles() {
        var links = document.querySelectorAll('link[data-slb-defer-css]');
        var i;
        for (i = 0; i < links.length; i++) {
            (function (link) {
                var apply = function () {
                    link.media = 'all';
                };
                if (link.sheet) {
                    apply();
                    return;
                }
                link.addEventListener('load', apply);
            })(links[i]);
        }
    }

    promoteDeferredStyles();

    global.SlbLoader = { show: show, hide: hide, hideAll: hideAll };
})(window);
