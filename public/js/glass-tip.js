/**
 * Glass tip — accessible glassmorphism tooltips
 * Hover (desktop), click/focus toggle, Escape + outside click to dismiss,
 * viewport-aware placement.
 */
(function (window, document) {
  'use strict';

  var PAD = 10;
  var OFFSET = 10;
  var SHOW_DELAY = 80;
  var HIDE_DELAY = 140;
  var active = null;
  var tipEl = null;
  var showTimer = null;
  var hideTimer = null;
  var tipIdSeq = 0;

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function canHover() {
    return !(window.matchMedia && window.matchMedia('(hover: none)').matches);
  }

  function ensureTipEl() {
    if (tipEl && document.body.contains(tipEl)) return tipEl;
    tipEl = document.createElement('div');
    tipEl.className = 'glass-tip';
    tipEl.setAttribute('role', 'tooltip');
    tipEl.hidden = true;
    tipEl.innerHTML =
      '<div class="glass-tip-arrow" aria-hidden="true"></div>' +
      '<strong class="glass-tip-title"></strong>' +
      '<p class="glass-tip-body"></p>';
    document.body.appendChild(tipEl);

    tipEl.addEventListener('mouseenter', function () {
      clearTimers();
    });
    tipEl.addEventListener('mouseleave', function () {
      if (active && active.getAttribute('data-glass-tip-pinned') !== '1') hide(false);
    });

    return tipEl;
  }

  function clearTimers() {
    if (showTimer) { clearTimeout(showTimer); showTimer = null; }
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
  }

  function getContent(trigger) {
    var title = (trigger.getAttribute('data-glass-tip-title') || '').trim();
    var body =
      (trigger.getAttribute('data-glass-tip-body') ||
        trigger.getAttribute('data-glass-tip') ||
        '').trim();
    return { title: title, body: body };
  }

  function preferredPlacement(trigger) {
    return (trigger.getAttribute('data-glass-tip-placement') || 'top').toLowerCase();
  }

  function measureAndPlace(trigger) {
    var tip = ensureTipEl();
    var rect = trigger.getBoundingClientRect();
    var tipRect = tip.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    var order = [preferredPlacement(trigger), 'top', 'bottom', 'right', 'left'];
    var seen = {};
    var placements = order.filter(function (p) {
      if (seen[p]) return false;
      seen[p] = true;
      return true;
    });

    var best = null;

    placements.forEach(function (placement) {
      var top = 0;
      var left = 0;

      if (placement === 'top') {
        top = rect.top - tipRect.height - OFFSET;
        left = rect.left + rect.width / 2 - tipRect.width / 2;
      } else if (placement === 'bottom') {
        top = rect.bottom + OFFSET;
        left = rect.left + rect.width / 2 - tipRect.width / 2;
      } else if (placement === 'left') {
        top = rect.top + rect.height / 2 - tipRect.height / 2;
        left = rect.left - tipRect.width - OFFSET;
      } else {
        top = rect.top + rect.height / 2 - tipRect.height / 2;
        left = rect.right + OFFSET;
      }

      left = Math.max(PAD, Math.min(left, vw - tipRect.width - PAD));
      top = Math.max(PAD, Math.min(top, vh - tipRect.height - PAD));

      var overflow = 0;
      if (placement === 'top' && rect.top - tipRect.height - OFFSET < PAD) overflow += 1000;
      if (placement === 'bottom' && rect.bottom + tipRect.height + OFFSET > vh - PAD) overflow += 1000;
      if (placement === 'left' && rect.left - tipRect.width - OFFSET < PAD) overflow += 1000;
      if (placement === 'right' && rect.right + tipRect.width + OFFSET > vw - PAD) overflow += 1000;

      if (!best || overflow < best.overflow) {
        best = { top: top, left: left, placement: placement, overflow: overflow };
      }
    });

    tip.style.top = best.top + 'px';
    tip.style.left = best.left + 'px';
    tip.setAttribute('data-placement', best.placement);

    var arrow = tip.querySelector('.glass-tip-arrow');
    if (arrow) {
      arrow.style.top = '';
      arrow.style.bottom = '';
      arrow.style.left = '';
      arrow.style.right = '';

      if (best.placement === 'top' || best.placement === 'bottom') {
        var ax = rect.left + rect.width / 2 - best.left;
        ax = Math.max(14, Math.min(ax, tipRect.width - 14));
        arrow.style.left = ax + 'px';
        if (best.placement === 'top') arrow.style.bottom = '-5px';
        else arrow.style.top = '-5px';
      } else {
        var ay = rect.top + rect.height / 2 - best.top;
        ay = Math.max(14, Math.min(ay, tipRect.height - 14));
        arrow.style.top = ay + 'px';
        if (best.placement === 'left') arrow.style.right = '-5px';
        else arrow.style.left = '-5px';
      }
    }
  }

  function show(trigger, immediate) {
    var content = getContent(trigger);
    if (!content.body && !content.title) return;

    clearTimers();
    var delay = immediate || prefersReducedMotion() ? 0 : SHOW_DELAY;

    showTimer = setTimeout(function () {
      var tip = ensureTipEl();
      var titleEl = tip.querySelector('.glass-tip-title');
      var bodyEl = tip.querySelector('.glass-tip-body');

      if (content.title) {
        titleEl.textContent = content.title;
        titleEl.hidden = false;
      } else {
        titleEl.textContent = '';
        titleEl.hidden = true;
      }
      bodyEl.textContent = content.body || '';
      bodyEl.hidden = !content.body;

      if (!trigger.id) {
        tipIdSeq += 1;
        trigger.id = 'glass-tip-trigger-' + tipIdSeq;
      }
      tip.id = trigger.id + '-tip';
      trigger.setAttribute('aria-describedby', tip.id);

      tip.hidden = false;
      tip.style.visibility = 'hidden';
      tip.classList.remove('is-visible');
      tip.style.top = '0px';
      tip.style.left = '0px';
      measureAndPlace(trigger);
      tip.style.visibility = '';

      requestAnimationFrame(function () {
        measureAndPlace(trigger);
        tip.classList.add('is-visible');
      });

      if (active && active !== trigger) {
        active.classList.remove('is-open');
        active.setAttribute('aria-expanded', 'false');
        active.removeAttribute('data-glass-tip-pinned');
      }
      active = trigger;
      trigger.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
    }, delay);
  }

  function hide(immediate) {
    clearTimers();
    var delay = immediate || prefersReducedMotion() ? 0 : HIDE_DELAY;
    var current = active;

    hideTimer = setTimeout(function () {
      var tip = tipEl;
      if (!tip) return;
      tip.classList.remove('is-visible');

      var finish = function () {
        tip.hidden = true;
        if (current) {
          current.classList.remove('is-open');
          current.setAttribute('aria-expanded', 'false');
          current.removeAttribute('aria-describedby');
          if (active === current) active = null;
        }
      };

      if (prefersReducedMotion()) finish();
      else setTimeout(finish, 180);
    }, delay);
  }

  function onTriggerEnter(e) {
    var trigger = e.currentTarget;
    if (!canHover()) return;
    show(trigger, false);
  }

  function onTriggerLeave(e) {
    var trigger = e.currentTarget;
    var next = e.relatedTarget;
    if (tipEl && next && tipEl.contains(next)) return;
    if (trigger.contains(next)) return;
    if (trigger.getAttribute('data-glass-tip-pinned') === '1') return;
    hide(false);
  }

  function onTriggerFocus(e) {
    show(e.currentTarget, true);
  }

  function onTriggerBlur(e) {
    var trigger = e.currentTarget;
    if (trigger.getAttribute('data-glass-tip-pinned') === '1') return;
    hide(true);
  }

  function onTriggerClick(e) {
    var trigger = e.currentTarget;
    e.preventDefault();
    e.stopPropagation();

    if (active === trigger && trigger.getAttribute('data-glass-tip-pinned') === '1') {
      trigger.removeAttribute('data-glass-tip-pinned');
      hide(true);
      return;
    }

    document.querySelectorAll('[data-glass-tip-pinned="1"]').forEach(function (el) {
      el.removeAttribute('data-glass-tip-pinned');
    });
    trigger.setAttribute('data-glass-tip-pinned', '1');
    show(trigger, true);
  }

  function onKeydown(e) {
    if (e.key === 'Escape' && active) {
      var el = active;
      el.removeAttribute('data-glass-tip-pinned');
      hide(true);
      if (el && typeof el.focus === 'function') el.focus();
    }
  }

  function onDocumentClick(e) {
    if (!active) return;
    if (active.contains(e.target)) return;
    if (tipEl && tipEl.contains(e.target)) return;
    active.removeAttribute('data-glass-tip-pinned');
    hide(true);
  }

  function onScrollOrResize() {
    if (active && tipEl && tipEl.classList.contains('is-visible')) {
      measureAndPlace(active);
    }
  }

  function bindTrigger(el) {
    if (el.getAttribute('data-glass-tip-ready') === '1') return;
    el.setAttribute('data-glass-tip-ready', '1');

    if (!el.hasAttribute('tabindex') && el.tagName !== 'BUTTON' && el.tagName !== 'A') {
      el.setAttribute('tabindex', '0');
    }
    if (!el.hasAttribute('aria-expanded')) {
      el.setAttribute('aria-expanded', 'false');
    }
    if (!el.hasAttribute('role') && el.tagName !== 'BUTTON' && el.tagName !== 'A') {
      el.setAttribute('role', 'button');
    }

    // Migrate legacy title → glass tip body, then remove native tooltip
    if (el.hasAttribute('title') && !el.getAttribute('data-glass-tip-body') && !el.getAttribute('data-glass-tip')) {
      el.setAttribute('data-glass-tip-body', el.getAttribute('title'));
    }
    el.removeAttribute('title');

    el.addEventListener('mouseenter', onTriggerEnter);
    el.addEventListener('mouseleave', onTriggerLeave);
    el.addEventListener('focus', onTriggerFocus);
    el.addEventListener('blur', onTriggerBlur);
    el.addEventListener('click', onTriggerClick);
  }

  function enhanceTriggers(root) {
    (root || document).querySelectorAll('[data-glass-tip]').forEach(bindTrigger);
  }

  function init() {
    ensureTipEl();
    enhanceTriggers(document);

    document.addEventListener('click', onDocumentClick, true);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
  }

  window.GlassTip = {
    init: init,
    enhance: enhanceTriggers,
    hide: function () { hide(true); }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
