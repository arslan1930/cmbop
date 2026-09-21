/**
 * Glass tip — accessible glassmorphism tooltips
 * Hover (desktop), click/focus toggle, Escape + outside click to dismiss,
 * viewport-aware placement.
 *
 * Native `title` attributes are adopted automatically so every hover hint in
 * the app looks the same instead of falling back to the grey OS tooltip.
 * Opt an element out with `data-no-tip`.
 */
(function (window, document) {
  'use strict';

  var PAD = 10;
  var OFFSET = 10;
  var SHOW_DELAY = 70;
  var HIDE_DELAY = 100;
  var active = null;
  var tipEl = null;
  var showTimer = null;
  var hideTimer = null;
  var tipIdSeq = 0;

  /**
   * Elements where `title` is not a hover hint: it names a frame for assistive
   * tech, carries constraint-validation copy, or belongs to a foreign markup
   * namespace. Stealing it there would break behaviour rather than restyle it.
   */
  var TITLE_ADOPT_SKIP = {
    IFRAME: 1,
    INPUT: 1,
    SELECT: 1,
    TEXTAREA: 1,
    OPTION: 1,
    OPTGROUP: 1,
    AREA: 1,
    LINK: 1,
    TRACK: 1,
    SOURCE: 1,
    PARAM: 1,
    STYLE: 1,
    SCRIPT: 1,
  };

  /** Already focusable and already carrying a role — never re-label these. */
  var NATIVELY_INTERACTIVE = {
    A: 1,
    BUTTON: 1,
    INPUT: 1,
    SELECT: 1,
    TEXTAREA: 1,
    SUMMARY: 1,
  };

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
    var explicit = (trigger.getAttribute('data-glass-tip-placement') || '').toLowerCase();
    if (explicit) return explicit;
    // Catalog Buy column: a top tip sits on Add to cart. Open inward instead.
    if (trigger.closest('.catalog-row-actions, .catalog-td-action, .catalog-card-buy')) {
      return 'left';
    }
    return 'top';
  }

  function rectsOverlap(a, b, pad) {
    pad = pad || 4;
    return !(a.right + pad < b.left || a.left - pad > b.right || a.bottom + pad < b.top || a.top - pad > b.bottom);
  }

  function nearbyClickTargets(trigger) {
    var scope = trigger.closest('.catalog-td-action, .catalog-mobile-card, .catalog-row-actions');
    if (!scope) return [];
    return Array.prototype.slice.call(
      scope.querySelectorAll('.buy-now, .btn-claim-site, .favorite-btn, .blacklist-btn')
    ).filter(function (el) {
      return el !== trigger;
    });
  }

  /**
   * aria-expanded only belongs on elements that actually expand something, so
   * adopted plain-text triggers never get it.
   */
  function setExpanded(el, expanded) {
    if (el && el.hasAttribute('aria-expanded')) {
      el.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
  }

  function isAutoAdopted(el) {
    return !!el && el.getAttribute('data-glass-tip-auto') === '1';
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
    var blockers = nearbyClickTargets(trigger);

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

      var placed = { top: top, left: left, right: left + tipRect.width, bottom: top + tipRect.height };
      blockers.forEach(function (el) {
        if (rectsOverlap(placed, el.getBoundingClientRect())) overflow += 500;
      });

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
      // Hover-only / adopted titles must never steal clicks from Add to cart.
      tip.style.pointerEvents = isHoverOnlyTip(trigger) ? 'none' : '';
      measureAndPlace(trigger);
      tip.style.visibility = '';

      requestAnimationFrame(function () {
        measureAndPlace(trigger);
        tip.classList.add('is-visible');
      });

      if (active && active !== trigger) {
        active.classList.remove('is-open');
        setExpanded(active, false);
        active.removeAttribute('data-glass-tip-pinned');
      }
      active = trigger;
      trigger.classList.add('is-open');
      setExpanded(trigger, true);
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
          setExpanded(current, false);
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

  function isHoverOnlyTip(trigger) {
    if (!trigger) return true;
    if (trigger.getAttribute('data-glass-tip-hover-only') === '1') return true;
    // An adopted title must never swallow the element's own click handler.
    if (isAutoAdopted(trigger)) return true;
    // Action controls keep their own click handlers; only .glass-tip-trigger pins on click.
    if (NATIVELY_INTERACTIVE[trigger.tagName] && !trigger.classList.contains('glass-tip-trigger')) {
      return true;
    }
    return false;
  }

  function onTriggerClick(e) {
    var trigger = e.currentTarget;
    if (isHoverOnlyTip(trigger)) return;

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

    // Declared triggers are controls the user can click to pin. Adopted titles
    // stay plain content, so a table cell never announces itself as a button.
    if (!isAutoAdopted(el) && !NATIVELY_INTERACTIVE[el.tagName]) {
      if (!el.hasAttribute('tabindex')) {
        el.setAttribute('tabindex', '0');
      }
      if (!el.hasAttribute('aria-expanded')) {
        el.setAttribute('aria-expanded', 'false');
      }
      if (!el.hasAttribute('role')) {
        el.setAttribute('role', 'button');
      }
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

  function canAdoptTitle(el) {
    if (!el || el.nodeType !== 1) return false;
    // SVG/MathML keep their own title semantics.
    if (el.namespaceURI && el.namespaceURI !== 'http://www.w3.org/1999/xhtml') return false;
    if (TITLE_ADOPT_SKIP[el.tagName]) return false;
    if (el.hasAttribute('data-no-tip')) return false;
    // A declared glass tip already owns its copy.
    if (el.hasAttribute('data-glass-tip') && !isAutoAdopted(el)) return false;
    // Catalog listing cells: names, metrics, chips, and empty space used to
    // fire a glass tip on every hover and sit on top of Buy / Details.
    // Column headers and status chips declare data-glass-tip; action buttons
    // (heart, blacklist, claim, eye) still adopt their titles.
    if (el.closest('#catalogResults, .catalog-results-card') && !NATIVELY_INTERACTIVE[el.tagName]) {
      return false;
    }

    return (el.getAttribute('title') || '').trim() !== '';
  }

  /**
   * Turn a native `title` into a glass tip, keeping the text as the element's
   * accessible name when the tooltip was the only label it had.
   */
  function adoptTitle(el) {
    if (!canAdoptTitle(el)) return;

    var text = el.getAttribute('title').trim();

    if (!el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby') && !el.textContent.trim()) {
      el.setAttribute('aria-label', text);
    }

    el.setAttribute('data-glass-tip-auto', '1');
    el.setAttribute('data-glass-tip-body', text);
    el.removeAttribute('title');

    // Re-adoption after a script rewrote the title: listeners are already on.
    if (el.getAttribute('data-glass-tip-ready') === '1') return;

    el.setAttribute('data-glass-tip', '');
    bindTrigger(el);
  }

  function enhanceTriggers(root) {
    var scope = root || document;

    if (scope.nodeType === 1) {
      if (scope.matches('[data-glass-tip]')) bindTrigger(scope);
      else if (scope.hasAttribute('title')) adoptTitle(scope);
    }

    scope.querySelectorAll('[data-glass-tip]').forEach(bindTrigger);
    scope.querySelectorAll('[title]').forEach(adoptTitle);
  }

  /**
   * Most tables, modals and toasts are rendered after load, and some scripts
   * reset `title` when they toggle state. Watch for both so those hints get the
   * same treatment as server-rendered markup.
   */
  function watchForNewTips() {
    if (!window.MutationObserver || !document.body) return;

    var queued = false;

    new MutationObserver(function (mutations) {
      var rescan = false;

      for (var i = 0; i < mutations.length; i++) {
        var mutation = mutations[i];
        if (tipEl && (mutation.target === tipEl || tipEl.contains(mutation.target))) continue;

        if (mutation.type === 'attributes') {
          adoptTitle(mutation.target);
        } else if (mutation.addedNodes.length) {
          rescan = true;
        }
      }

      if (!rescan || queued) return;

      // Batch: a re-rendered table inserts every row in the same tick.
      queued = true;
      window.requestAnimationFrame(function () {
        queued = false;
        enhanceTriggers(document);
      });
    }).observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['title'],
    });
  }

  function init() {
    ensureTipEl();
    enhanceTriggers(document);
    watchForNewTips();

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
