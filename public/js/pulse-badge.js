/**
 * PulseBadge utility — one reusable API for unread counters/dots.
 * Does not animate parent icons; only the badge element itself.
 */
(function (window) {
  'use strict';

  function isReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function show(el, count) {
    if (!el) return;
    el.classList.add('pulse-badge');
    if (typeof count !== 'undefined' && count !== null) {
      var label = count > 99 ? '99+' : String(count);
      el.textContent = label;
    }
    el.style.display = el.dataset.pulseDisplay || 'inline-flex';
    el.classList.add('is-visible', 'is-pulsing');
    el.removeAttribute('hidden');
    if (el.classList.contains('d-none')) el.classList.remove('d-none');
    if (isReducedMotion()) el.classList.remove('is-pulsing');
  }

  function hide(el) {
    if (!el) return;
    el.classList.remove('is-pulsing', 'is-visible');
    el.style.display = 'none';
  }

  function sync(el, count) {
    if (!el) return;
    var n = Number(count) || 0;
    if (n > 0) show(el, n);
    else hide(el);
  }

  window.PulseBadge = { show: show, hide: hide, sync: sync, isReducedMotion: isReducedMotion };
})(window);
