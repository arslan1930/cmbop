/**
 * Shared single-select keyboard / focus helpers (P1 #12)
 * Works with markup using .single-select-wrapper / .single-select-input / .single-select-dropdown
 */
(function () {
  function closeAll(except) {
    document.querySelectorAll('.single-select-dropdown.show').forEach(function (dd) {
      if (except && dd === except) return;
      dd.classList.remove('show');
      var input = dd.previousElementSibling;
      if (input && input.classList.contains('single-select-input')) {
        input.setAttribute('aria-expanded', 'false');
      }
    });
  }

  function visibleOptions(dropdown) {
    return Array.prototype.slice.call(dropdown.querySelectorAll('.single-select-option')).filter(function (el) {
      return !el.classList.contains('hidden') && el.style.display !== 'none';
    });
  }

  function focusOption(dropdown, index) {
    var opts = visibleOptions(dropdown);
    if (!opts.length) return;
    var i = ((index % opts.length) + opts.length) % opts.length;
    opts.forEach(function (el) { el.classList.remove('is-keyboard-focus'); });
    opts[i].classList.add('is-keyboard-focus');
    opts[i].scrollIntoView({ block: 'nearest' });
    dropdown.dataset.focusIndex = String(i);
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.single-select-wrapper')) {
      closeAll();
    }
  });

  document.addEventListener('keydown', function (e) {
    var input = e.target.closest && e.target.closest('.single-select-input');
    var open = document.querySelector('.single-select-dropdown.show');

    if (input && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) {
      e.preventDefault();
      var wrapper = input.closest('.single-select-wrapper');
      var dropdown = wrapper ? wrapper.querySelector('.single-select-dropdown') : null;
      if (!dropdown) return;
      var willOpen = !dropdown.classList.contains('show');
      closeAll(dropdown);
      dropdown.classList.toggle('show', willOpen);
      input.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) {
        var search = dropdown.querySelector('.single-select-search input');
        if (search) setTimeout(function () { search.focus(); }, 10);
        dropdown.dataset.focusIndex = '-1';
      }
      return;
    }

    if (!open) return;

    if (e.key === 'Escape') {
      e.preventDefault();
      open.classList.remove('show');
      var trigger = open.previousElementSibling;
      if (trigger) {
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
      }
      return;
    }

    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var cur = parseInt(open.dataset.focusIndex || '-1', 10);
      focusOption(open, e.key === 'ArrowDown' ? cur + 1 : cur - 1);
      return;
    }

    if (e.key === 'Enter') {
      var focused = open.querySelector('.single-select-option.is-keyboard-focus');
      if (focused) {
        e.preventDefault();
        focused.click();
      }
    }
  });
})();
