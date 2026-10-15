/**
 * PulseBadge utility — one reusable API for unread counters/dots.
 * Does not animate parent icons; only the badge element itself.
 * Optional soft alert beep + pop when the count increases.
 */
(function (window) {
  'use strict';

  var audioCtx = null;

  function isReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function ensureAudio() {
    if (audioCtx) return audioCtx;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    try {
      audioCtx = new AC();
    } catch (e) {
      audioCtx = null;
    }
    return audioCtx;
  }

  /** Soft two-tone chime — UI cue only; never blocks or throws. */
  function playAlertBeep() {
    if (isReducedMotion()) return;
    if (typeof document !== 'undefined' && document.hidden) return;
    var ctx = ensureAudio();
    if (!ctx) return;

    function tone(freq, start, dur, gainPeak) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(gainPeak, start + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + dur);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start(start);
      osc.stop(start + dur + 0.02);
    }

    var resume = ctx.state === 'suspended' ? ctx.resume() : Promise.resolve();
    Promise.resolve(resume).then(function () {
      var t0 = ctx.currentTime;
      tone(880, t0, 0.09, 0.045);
      tone(1174.7, t0 + 0.1, 0.12, 0.035);
    }).catch(function () { /* autoplay / resume blocked — ignore */ });
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
    el.classList.remove('is-pulsing', 'is-visible', 'is-alerting');
    el.style.display = 'none';
    el.dataset.pulseCount = '0';
    el.textContent = '';
  }

  function alertOnce(el, opts) {
    if (!el) return;
    opts = opts || {};
    if (!isReducedMotion()) {
      el.classList.remove('is-alerting');
      // Force reflow so repeated alerts restart the animation.
      void el.offsetWidth;
      el.classList.add('is-alerting');
      window.setTimeout(function () {
        el.classList.remove('is-alerting');
      }, 750);
    }
    if (opts.beep !== false) {
      playAlertBeep();
    }
  }

  function sync(el, count, opts) {
    if (!el) return;
    opts = opts || {};
    var n = Number(count) || 0;
    var prev = Number(el.dataset.pulseCount);
    if (!Number.isFinite(prev)) prev = 0;
    el.dataset.pulseCount = String(n);

    if (n > 0) {
      show(el, n);
      if (opts.alertOnIncrease && n > prev) {
        alertOnce(el, { beep: opts.beep !== false });
      }
    } else {
      hide(el);
    }
  }

  window.PulseBadge = {
    show: show,
    hide: hide,
    sync: sync,
    alert: alertOnce,
    playBeep: playAlertBeep,
    isReducedMotion: isReducedMotion
  };
})(window);
