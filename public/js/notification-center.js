(function (window, document) {
  'use strict';

  /* Lucide path sets — rendered with currentColor (theme-aware, monochrome) */
  const lucidePaths = {
    'message-circle': '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
    package: '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
    'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
    'x-circle': '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
    rocket: '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
    pencil: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
    'file-text': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'refresh-cw': '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    wallet: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    'alert-triangle': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'badge-check': '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    bell: '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>'
  };

  const iconByType = {
    message: 'message-circle',
    chat_reply: 'message-circle',
    order_created: 'package',
    order_accepted: 'check-circle',
    order_rejected: 'x-circle',
    guest_post_published: 'rocket',
    order_completed: 'badge-check',
    order_updated: 'refresh-cw',
    modification_requested: 'pencil',
    content_revision_requested: 'file-text',
    content_revision_fulfilled: 'check-circle',
    payment_received: 'wallet',
    payment_failed: 'alert-triangle',
    payment_pending: 'wallet',
    site_status: 'check-circle',
    content_approved: 'check-circle',
    content_needs_changes: 'alert-triangle',
    system: 'bell',
    account: 'user'
  };

  function lucideIcon(name) {
    const paths = lucidePaths[name] || lucidePaths.bell;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true">' + paths + '</svg>';
  }

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  /**
   * Force same-origin relative paths. Absolute APP_URL hosts (www vs apex)
   * break fetch(..., { credentials: 'same-origin' }) and cause the Retry error.
   */
  function sameOriginUrl(url, fallback) {
    const raw = (url == null || url === '') ? fallback : String(url);
    if (!raw) return fallback || '/notifications';
    try {
      if (raw.charAt(0) === '/' && raw.charAt(1) !== '/') {
        return raw;
      }
      const parsed = new URL(raw, window.location.origin);
      if (parsed.origin === window.location.origin) {
        return parsed.pathname + parsed.search + parsed.hash;
      }
      return parsed.pathname + parsed.search + parsed.hash;
    } catch (e) {
      return fallback || '/notifications';
    }
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatBadgeCount(n) {
    n = Number(n) || 0;
    return n > 9 ? '9+' : String(n);
  }

  function relativeTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    const diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    if (diff < 172800) return 'Yesterday';
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  function clockTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function displayTime(iso, dayLabel) {
    if (!iso) return '';
    if (dayLabel === 'Today') return relativeTime(iso);
    return clockTime(iso);
  }

  function groupKey(iso) {
    if (!iso) return 'Earlier';
    const date = new Date(iso);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const startYesterday = new Date(startToday);
    startYesterday.setDate(startYesterday.getDate() - 1);
    if (date >= startToday) return 'Today';
    if (date >= startYesterday) return 'Yesterday';
    return 'Earlier';
  }

  function NotificationCenter(root, config) {
    this.root = root;
    this.config = config || {};
    this.open = false;
    this.loading = false;
    this.page = 1;
    this.hasMore = false;
    this.items = [];
    this.filter = 'all';
    this.status = 'unread';
    this.query = '';
    this.pollTimer = null;
    this.searchTimer = null;
    this.unread = 0;

    this.btn = root.querySelector('[data-nc-bell]');
    this.panel = root.querySelector('[data-nc-panel]');
    this.badge = root.querySelector('[data-nc-badge]');
    this.list = root.querySelector('[data-nc-list]');
    this.search = root.querySelector('[data-nc-search]');
    this.showAllLink = root.querySelector('[data-nc-show-all]');
    this.footer = root.querySelector('[data-nc-footer]');
    // Fetch a wider window so order events can collapse into cards.
    this.fetchLimit = 40;
    this.cardLimit = 5;

    this.bind();
    this.refreshCount();
    this.pollTimer = setInterval(() => {
      if (document.hidden) {
        return;
      }
      this.refreshCount(true);
    }, 60000);
  }

  NotificationCenter.prototype.bind = function () {
    const self = this;

    this.btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      self.toggle();
    });

    document.addEventListener('click', function (e) {
      if (!self.open) return;
      if (e.target.closest('[data-nc-kebab]')) return;
      if (self.root.contains(e.target) || self.panel.contains(e.target)) return;
      self.close();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.open) {
        if (self.closeOpenKebab()) return;
        self.close();
      }
    });

    window.addEventListener('resize', function () {
      if (self.open) {
        self.positionPanel();
        self.syncFilterOverflow();
      }
    });
    window.addEventListener('scroll', function () {
      if (self.open) self.positionPanel();
    }, true);

    const filterButtons = (this.panel || this.root).querySelectorAll('[data-nc-filter]');
    filterButtons.forEach(function (el) {
      el.addEventListener('click', function () {
        const value = el.getAttribute('data-nc-filter') || 'all';
        const wasActive = el.classList.contains('is-active');

        let next = value;
        if (wasActive && value !== 'all') {
          next = 'all';
        }

        if (next === 'unread') {
          self.status = 'unread';
          self.filter = 'all';
        } else {
          self.status = 'active';
          self.filter = next;
        }
        self.syncFilterChips();
        self.reload();
      });
    });

    const markAllBtns = [];
    [this.root, this.panel].forEach(function (scope) {
      if (!scope) return;
      scope.querySelectorAll('[data-nc-mark-all]').forEach(function (btn) {
        if (markAllBtns.indexOf(btn) === -1) markAllBtns.push(btn);
      });
    });
    markAllBtns.forEach(function (markAll) {
      markAll.addEventListener('click', function () {
        self.markAllRead();
      });
    });

    if (this.showAllLink) {
      this.showAllLink.addEventListener('click', function (e) {
        const href = self.showAllLink.getAttribute('href')
          || sameOriginUrl(self.config.allUrl, '/notifications/all');
        e.preventDefault();
        window.location.assign(href);
      });
    }

    if (this.search) {
      this.search.addEventListener('input', function () {
        clearTimeout(self.searchTimer);
        self.searchTimer = setTimeout(function () {
          self.query = self.search.value.trim();
          self.reload();
        }, 280);
      });
      this.search.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(self.searchTimer);
        self.query = self.search.value.trim();
        self.reload();
      });
    }

    const wrap = (this.panel || this.root).querySelector('[data-nc-filters-wrap]');
    if (wrap) {
      const prev = wrap.querySelector('[data-nc-filters-prev]');
      const next = wrap.querySelector('[data-nc-filters-next]');
      const scroller = wrap.querySelector('[data-nc-filters]');
      if (prev && scroller) {
        prev.addEventListener('click', function (e) {
          e.preventDefault();
          scroller.scrollBy({ left: -120, behavior: 'smooth' });
        });
      }
      if (next && scroller) {
        next.addEventListener('click', function (e) {
          e.preventDefault();
          scroller.scrollBy({ left: 120, behavior: 'smooth' });
        });
      }
      if (scroller) {
        scroller.addEventListener('scroll', function () {
          self.syncFilterOverflow();
        }, { passive: true });
      }
    }
  };

  NotificationCenter.prototype.syncFilterOverflow = function () {
    const wrap = (this.panel || this.root).querySelector('[data-nc-filters-wrap]');
    if (!wrap) return;
    const scroller = wrap.querySelector('[data-nc-filters]');
    const prev = wrap.querySelector('[data-nc-filters-prev]');
    const next = wrap.querySelector('[data-nc-filters-next]');
    if (!scroller) return;
    const maxScroll = scroller.scrollWidth - scroller.clientWidth;
    const overflow = maxScroll > 4;
    wrap.classList.toggle('has-overflow', overflow);
    wrap.classList.toggle('at-start', scroller.scrollLeft <= 2);
    wrap.classList.toggle('at-end', scroller.scrollLeft >= maxScroll - 2);
    if (prev) prev.hidden = !overflow || scroller.scrollLeft <= 2;
    if (next) next.hidden = !overflow || scroller.scrollLeft >= maxScroll - 2;
  };

  NotificationCenter.prototype.toggle = function () {
    if (this.open) this.close();
    else this.openPanel();
  };

  NotificationCenter.prototype.positionPanel = function () {
    if (!this.panel || !this.btn) return;
    const rect = this.btn.getBoundingClientRect();
    const width = Math.min(420, window.innerWidth - 24);
    let left = rect.right - width;
    if (left < 12) left = 12;
    const top = Math.min(rect.bottom + 10, window.innerHeight - 120);
    this.panel.style.position = 'fixed';
    this.panel.style.top = top + 'px';
    this.panel.style.left = left + 'px';
    this.panel.style.right = 'auto';
    this.panel.style.width = width + 'px';
    this.panel.style.zIndex = '2000';
    this.panel.style.maxHeight = Math.max(240, window.innerHeight - top - 16) + 'px';
  };

  NotificationCenter.prototype.openPanel = function () {
    this.open = true;
    this.panel.classList.add('nc-theme');
    if (this.panel.parentElement !== document.body) {
      this._panelHome = this.panel.parentElement;
      document.body.appendChild(this.panel);
    }
    this.positionPanel();
    this.panel.classList.add('is-open');
    this.btn.classList.add('is-open');
    this.btn.setAttribute('aria-expanded', 'true');
    this.syncFilterChips();
    this.reload();
    const self = this;
    window.requestAnimationFrame(function () {
      self.syncFilterOverflow();
    });
  };

  NotificationCenter.prototype.close = function () {
    this.open = false;
    this.closeOpenKebab();
    this.panel.classList.remove('is-open');
    this.btn.classList.remove('is-open');
    this.btn.setAttribute('aria-expanded', 'false');
    if (this._panelHome && this.panel.parentElement === document.body) {
      this._panelHome.appendChild(this.panel);
      this.panel.style.position = '';
      this.panel.style.top = '';
      this.panel.style.left = '';
      this.panel.style.right = '';
      this.panel.style.width = '';
      this.panel.style.zIndex = '';
      this.panel.style.maxHeight = '';
    }
  };

  NotificationCenter.prototype.setUnread = function (count, opts) {
    opts = opts || {};
    this.unread = count || 0;
    this.btn.classList.remove('has-unread');
    if (window.PulseBadge) {
      window.PulseBadge.sync(this.badge, this.unread, {
        alertOnIncrease: !!opts.alertOnIncrease,
        beep: opts.beep !== false,
        capAt: 9
      });
      this.syncUnreadLabel();
      this.syncMarkAllBar();
      return;
    }
    if (this.unread > 0) {
      this.badge.textContent = formatBadgeCount(this.unread);
      this.badge.classList.add('is-visible', 'is-pulsing', 'pulse-badge');
      this.badge.style.display = 'inline-flex';
    } else {
      this.badge.classList.remove('is-visible', 'is-pulsing', 'is-alerting');
      this.badge.style.display = 'none';
    }
    this.syncUnreadLabel();
    this.syncMarkAllBar();
  };

  NotificationCenter.prototype.syncMarkAllBar = function () {
    const buttons = [];
    [this.root, this.panel].forEach(function (scope) {
      if (!scope) return;
      scope.querySelectorAll('.nc-mark-all-bar[data-nc-mark-all]').forEach(function (btn) {
        if (buttons.indexOf(btn) === -1) buttons.push(btn);
      });
    });
    const show = this.unread > 0;
    buttons.forEach(function (btn) {
      btn.hidden = !show;
    });
  };

  NotificationCenter.prototype.syncUnreadLabel = function () {
    const el = (this.panel || this.root).querySelector('[data-nc-unread-label]');
    if (!el) return;
    el.textContent = this.unread > 0
      ? (this.unread === 1 ? '1 unread' : (this.unread + ' unread'))
      : 'All caught up';
  };

  NotificationCenter.prototype.showLoadError = function (detail) {
    if (!this.list) return;
    if (detail && typeof console !== 'undefined' && console.warn) {
      console.warn('[notification-center]', detail);
    }
    this.list.innerHTML = '<div class="nc-empty">Could not load notifications. <button type="button" class="nc-link-btn" data-nc-retry>Retry</button></div>';
    const retry = this.list.querySelector('[data-nc-retry]');
    const self = this;
    if (retry) retry.addEventListener('click', function () { self.reload(); });
  };

  NotificationCenter.prototype.activeChipValue = function () {
    if (this.status === 'unread') return 'unread';
    return this.filter || 'all';
  };

  NotificationCenter.prototype.syncFilterChips = function () {
    const scope = this.panel || this.root;
    if (!scope) return;
    const active = this.activeChipValue();
    scope.querySelectorAll('[data-nc-filter]').forEach(function (b) {
      const on = (b.getAttribute('data-nc-filter') || 'all') === active;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  };

  NotificationCenter.prototype.refreshCount = function (pulseOnIncrease) {
    const self = this;
    const prev = this.unread;
    const unreadUrl = sameOriginUrl(this.config.unreadUrl, '/notifications/unread-count');
    fetch(unreadUrl, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) {
        return r.text().then(function (text) {
          let data = null;
          try {
            data = text ? JSON.parse(text) : null;
          } catch (e) {
            data = null;
          }
          return { ok: r.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || !result.data.success) return;
        const next = result.data.unread_count || 0;
        self.setUnread(next, {
          alertOnIncrease: !!pulseOnIncrease && next > prev
        });
        if (self.open && next !== prev) self.reload();
      })
      .catch(function () {});
  };

  NotificationCenter.prototype.reload = function () {
    this.page = 1;
    this.items = [];
    this.fetchPage(false);
  };

  NotificationCenter.prototype.fetchPage = function (append) {
    const self = this;
    this.loading = true;
    if (!append) this.renderSkeleton();

    const indexUrl = sameOriginUrl(this.config.indexUrl, '/notifications');
    const params = new URLSearchParams({
      page: '1',
      per_page: String(this.fetchLimit),
      status: this.status,
      category: this.filter,
      q: this.query || '',
      group: '1'
    });

    fetch(indexUrl + (indexUrl.indexOf('?') === -1 ? '?' : '&') + params.toString(), {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (r) {
        return r.text().then(function (text) {
          let data = null;
          try {
            data = text ? JSON.parse(text) : null;
          } catch (e) {
            data = null;
          }
          if (!r.ok || !data) {
            const err = new Error('notifications_http_' + r.status);
            err.status = r.status;
            err.bodyPreview = (text || '').slice(0, 180);
            throw err;
          }
          return data;
        });
      })
      .then(function (data) {
        self.loading = false;
        if (!data.success) {
          self.showLoadError(data.message || 'success=false');
          return;
        }
        let batch = data.notifications || [];
        if (self.filter && self.filter !== 'all') {
          batch = batch.filter(function (n) {
            return n && String(n.category || '') === self.filter;
          });
        }
        if (self.status === 'unread') {
          batch = batch.filter(function (n) { return n && n.is_unread; });
        }
        self.items = batch.slice(0, self.cardLimit);
        self.hasMore = !!(data.pagination && (data.pagination.has_more || data.pagination.total > self.fetchLimit))
          || batch.length > self.cardLimit;
        try {
          self.syncFilterChips();
          self.setUnread(data.unread_count || 0);
          self.renderList();
          self.syncFilterOverflow();
        } catch (err) {
          self.showLoadError(err);
          return;
        }
        if (self.footer) self.footer.style.display = 'block';
        if (self.showAllLink) {
          self.showAllLink.style.display = 'inline-flex';
          const baseAll = sameOriginUrl(self.config.allUrl, '/notifications/all');
          const allParams = new URLSearchParams();
          if (self.filter && self.filter !== 'all') allParams.set('category', self.filter);
          if (self.query) allParams.set('q', self.query);
          const qs = allParams.toString();
          self.showAllLink.setAttribute('href', baseAll + (qs ? (baseAll.indexOf('?') === -1 ? '?' : '&') + qs : ''));
        }
      })
      .catch(function (err) {
        self.loading = false;
        self.showLoadError(err);
      });
  };

  NotificationCenter.prototype.renderSkeleton = function () {
    this.list.innerHTML = [0, 1, 2].map(function () {
      return '<div class="nc-skeleton"><div class="nc-skel-line med"></div><div class="nc-skel-line short"></div></div>';
    }).join('');
  };

  NotificationCenter.prototype.closeOpenKebab = function () {
    const open = (this.panel || this.root).querySelector('.nc-kebab-menu:not([hidden])');
    if (!open) return false;
    const wrap = open.closest('[data-nc-kebab]');
    const btn = wrap && wrap.querySelector('[data-nc-kebab-toggle]');
    open.hidden = true;
    if (btn) btn.setAttribute('aria-expanded', 'false');
    return true;
  };

  NotificationCenter.prototype.renderList = function () {
    const self = this;
    if (!this.items.length) {
      const filtered = (this.filter && this.filter !== 'all')
        || this.status === 'unread'
        || !!(this.query && String(this.query).trim());
      const emptyMsg = (this.status === 'unread' && this.filter === 'all' && !this.query)
        ? 'You\'re all caught up. Switch to All to see earlier notifications.'
        : (filtered
          ? 'No matching notifications.'
          : 'You\'re all caught up. New activity will show up here.');
      this.list.innerHTML = '<div class="nc-empty">' + emptyMsg + '</div>';
      return;
    }

    // Pin action-needed cards above info-only, then day-group within each bucket.
    const actionNeeded = [];
    const rest = [];
    this.items.forEach(function (item) {
      if (item && item.action_needed) actionNeeded.push(item);
      else rest.push(item);
    });

    function renderDayGroups(list, html) {
      const groups = { Today: [], Yesterday: [], Earlier: [] };
      list.forEach(function (item) {
        const day = item.day_group || groupKey(item.created_at);
        if (!groups[day]) groups[day] = [];
        groups[day].push(item);
      });
      ['Today', 'Yesterday', 'Earlier'].forEach(function (label) {
        const rows = groups[label] || [];
        if (!rows.length) return;
        html.value += '<div class="nc-group-label">' + label + '</div>';
        rows.forEach(function (n) {
          html.value += self.renderItem(n, label);
        });
      });
    }

    const html = { value: '' };
    if (actionNeeded.length) {
      html.value += '<div class="nc-group-label">Action needed</div>';
      actionNeeded.forEach(function (n) {
        const day = n.day_group || groupKey(n.created_at);
        html.value += self.renderItem(n, day);
      });
    }
    renderDayGroups(rest, html);
    this.list.innerHTML = html.value;
    this.bindListEvents();
  };

  NotificationCenter.prototype.bindListEvents = function () {
    const self = this;

    this.list.querySelectorAll('[data-nc-favicon]').forEach(function (img) {
      img.addEventListener('error', function () {
        img.style.display = 'none';
        const fb = img.nextElementSibling;
        if (fb) fb.hidden = false;
      });
    });

    this.list.querySelectorAll('.nc-item').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (e.target.closest('[data-nc-tool], [data-nc-kebab], .nc-open-link, .nc-group-details, a')) {
          return;
        }
        const id = el.getAttribute('data-nc-id');
        const url = el.getAttribute('data-nc-url');
        self.openNotification(id, url);
      });
    });

    this.list.querySelectorAll('[data-nc-kebab-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const wrap = btn.closest('[data-nc-kebab]');
        const menu = wrap && wrap.querySelector('.nc-kebab-menu');
        const willOpen = menu && menu.hidden;
        self.closeOpenKebab();
        if (!menu) return;
        menu.hidden = !willOpen;
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
          const first = menu.querySelector('[role="menuitem"]');
          if (first) first.focus();
        }
      });
    });

    this.list.querySelectorAll('[data-nc-tool="read"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        self.markRead(btn.getAttribute('data-id'));
      });
    });
    this.list.querySelectorAll('[data-nc-tool="unread"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        self.markUnread(btn.getAttribute('data-id'));
      });
    });
    this.list.querySelectorAll('[data-nc-tool="archive"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        self.archive(btn.getAttribute('data-id'));
      });
    });
    this.list.querySelectorAll('[data-nc-tool="delete"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        self.destroy(btn.getAttribute('data-id'));
      });
    });
  };

  /**
   * Shared NotificationCard markup (mirrors partials/notification-card.blade.php).
   */
  NotificationCenter.prototype.renderItem = function (n, dayLabel) {
    const iconName = iconByType[n.type] || n.icon || 'bell';
    const unreadClass = n.is_unread ? ' is-unread' : '';
    const actionClass = n.action_needed ? ' is-action-needed' : '';
    const groupClass = n.is_group ? ' is-group' : '';
    const dotPulse = n.is_unread ? ' pulse-badge is-pulsing' : '';
    const timeLabel = n.display_time || displayTime(n.created_at, dayLabel || n.day_group);
    const ids = (n.event_ids && n.event_ids.length)
      ? n.event_ids.join(',')
      : String(n.id || '');
    const primaryId = n.id || (n.event_ids && n.event_ids[0]) || '';

    let iconHtml;
    if (n.favicon_url) {
      iconHtml = '<img src="' + escapeHtml(n.favicon_url) + '" alt="" class="nc-favicon" width="18" height="18" loading="lazy" data-nc-favicon>' +
        '<span class="nc-icon-fallback" hidden>' + lucideIcon(iconName) + '</span>';
    } else {
      iconHtml = lucideIcon(iconName);
    }

    let timelineHtml = '';
    if (n.timeline && n.timeline.length) {
      timelineHtml = '<ol class="nc-timeline" aria-label="Order status">' +
        n.timeline.map(function (step) {
          return '<li class="nc-timeline-step is-' + escapeHtml(step.state || 'todo') + '">' +
            escapeHtml(step.label || '') + '</li>';
        }).join('') + '</ol>';
    }

    let eventsHtml = '';
    if (n.is_group && n.events && n.events.length > 1) {
      eventsHtml = '<details class="nc-group-details">' +
        '<summary>' + n.events.length + ' updates</summary>' +
        '<ul class="nc-group-events">' +
        n.events.map(function (ev) {
          return '<li><strong>' + escapeHtml(ev.title || '') + '</strong> ' +
            '<span class="nc-item-time">' + escapeHtml(ev.display_time || displayTime(ev.created_at, dayLabel)) + '</span></li>';
        }).join('') +
        '</ul></details>';
    }

    const actions = [];
    if (n.action_url) {
      actions.push('<a class="nc-item-action" href="' + escapeHtml(n.action_url) + '">' +
        escapeHtml(n.action_label || 'View details') + ' →</a>');
    }
    if (n.live_url) {
      actions.push('<a class="nc-open-link" href="' + escapeHtml(n.live_url) +
        '" target="_blank" rel="noopener noreferrer">Open link</a>');
    }

    const kebabRead = n.is_unread
      ? '<button type="button" class="nc-kebab-item" role="menuitem" data-nc-tool="read" data-id="' + primaryId + '">Mark as read</button>'
      : (!n.is_archived
        ? '<button type="button" class="nc-kebab-item" role="menuitem" data-nc-tool="unread" data-id="' + primaryId + '">Mark as unread</button>'
        : '');

    return (
      '<div class="nc-item' + unreadClass + actionClass + groupClass + '" data-nc-id="' + primaryId +
        '" data-nc-ids="' + escapeHtml(ids) + '" data-nc-url="' + escapeHtml(n.action_url || '') + '">' +
        '<div class="nc-icon" aria-hidden="true">' + iconHtml + '</div>' +
        '<div class="nc-item-main">' +
          '<div class="nc-item-title-row">' +
            '<p class="nc-item-title">' + escapeHtml(n.title) + '</p>' +
            (n.action_needed ? '<span class="nc-action-tag">Action needed</span>' : '') +
          '</div>' +
          (n.message ? '<p class="nc-item-msg">' + escapeHtml(n.message) + '</p>' : '') +
          (n.auto_approve_label ? '<p class="nc-auto-approve">' + escapeHtml(n.auto_approve_label) + '</p>' : '') +
          timelineHtml +
          eventsHtml +
          '<div class="nc-item-meta">' +
            '<span class="nc-item-time">' + escapeHtml(timeLabel) + '</span>' +
            (n.is_unread ? '<span class="nc-item-state">Unread</span>' : (n.is_archived ? '<span class="nc-item-state is-archived">Archived</span>' : '')) +
            actions.join('') +
          '</div>' +
        '</div>' +
        '<div class="nc-item-aside">' +
          '<span class="nc-dot' + dotPulse + '" aria-hidden="true"></span>' +
          '<div class="nc-kebab" data-nc-kebab>' +
            '<button type="button" class="nc-kebab-btn" data-nc-kebab-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Notification actions">' +
              '<span aria-hidden="true">⋯</span>' +
            '</button>' +
            '<div class="nc-kebab-menu" role="menu" hidden>' +
              kebabRead +
              '<button type="button" class="nc-kebab-item" role="menuitem" data-nc-tool="archive" data-id="' + primaryId + '">Archive</button>' +
              '<button type="button" class="nc-kebab-item nc-kebab-item--danger" role="menuitem" data-nc-tool="delete" data-id="' + primaryId + '">Delete</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>'
    );
  };

  NotificationCenter.prototype.post = function (url) {
    return fetch(sameOriginUrl(url, '/notifications'), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  };

  NotificationCenter.prototype.del = function (url) {
    return fetch(sameOriginUrl(url, '/notifications'), {
      method: 'DELETE',
      headers: {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  };

  NotificationCenter.prototype.openNotification = function (id, url) {
    const self = this;
    this.post(this.config.readUrl.replace('__ID__', id))
      .then(function (data) {
        if (data.unread_count != null) self.setUnread(data.unread_count);
        if (url) window.location.href = url;
        else self.reload();
      })
      .catch(function () {
        if (url) window.location.href = url;
      });
  };

  NotificationCenter.prototype.markRead = function (id) {
    const self = this;
    this.post(this.config.readUrl.replace('__ID__', id)).then(function (data) {
      if (data.unread_count != null) self.setUnread(data.unread_count);
      self.reload();
    });
  };

  NotificationCenter.prototype.markUnread = function (id) {
    const self = this;
    this.post(this.config.unreadItemUrl.replace('__ID__', id)).then(function (data) {
      if (data.unread_count != null) self.setUnread(data.unread_count);
      self.reload();
    });
  };

  NotificationCenter.prototype.markAllRead = function () {
    const self = this;
    const n = this.unread || 0;
    const msg = n > 0
      ? 'Mark all ' + n + ' notifications as read?'
      : 'Mark all notifications as read?';
    window.slbConfirm({ title: 'Mark all as read?', text: msg, confirmText: 'Mark all read' }).then(function (ok) {
      if (!ok) return;
      self.post(self.config.readAllUrl).then(function () {
        self.setUnread(0);
        self.reload();
      });
    });
  };

  NotificationCenter.prototype.archive = function (id) {
    const self = this;
    this.post(this.config.archiveUrl.replace('__ID__', id)).then(function (data) {
      if (data.unread_count != null) self.setUnread(data.unread_count);
      self.reload();
    });
  };

  NotificationCenter.prototype.destroy = function (id) {
    const self = this;
    this.del(this.config.destroyUrl.replace('__ID__', id)).then(function (data) {
      if (data.unread_count != null) self.setUnread(data.unread_count);
      self.reload();
    });
  };

  window.NotificationCenter = NotificationCenter;

  window.initNotificationCenter = function () {
    const root = document.querySelector('[data-notification-center]');
    if (!root || root.dataset.ncReady) return null;
    root.dataset.ncReady = '1';
    const cfg = {
      indexUrl: sameOriginUrl(root.getAttribute('data-index-url'), '/notifications'),
      unreadUrl: sameOriginUrl(root.getAttribute('data-unread-url'), '/notifications/unread-count'),
      readUrl: sameOriginUrl(root.getAttribute('data-read-url'), '/notifications/__ID__/read'),
      unreadItemUrl: sameOriginUrl(root.getAttribute('data-unread-item-url'), '/notifications/__ID__/unread'),
      readAllUrl: sameOriginUrl(root.getAttribute('data-read-all-url'), '/notifications/read-all'),
      archiveUrl: sameOriginUrl(root.getAttribute('data-archive-url'), '/notifications/__ID__/archive'),
      destroyUrl: sameOriginUrl(root.getAttribute('data-destroy-url'), '/notifications/__ID__'),
      allUrl: sameOriginUrl(root.getAttribute('data-all-url'), '/notifications/all'),
      settingsUrl: sameOriginUrl(root.getAttribute('data-settings-url'), '/profile/notifications')
    };
    return new NotificationCenter(root, cfg);
  };

  window.renderOrderActivityTimeline = function (container, activities, options) {
    if (!container) return;
    options = options || {};
    if (!activities || !activities.length) {
      container.innerHTML = '<div class="text-muted small">No activity recorded yet.</div>';
      return;
    }
    var reconstructed = !!options.reconstructed || activities.some(function (a) {
      return a && a.meta && a.meta.reconstructed;
    });
    var note = reconstructed
      ? '<div class="oa-reconstructed text-muted small mb-2">Reconstructed from order dates.</div>'
      : '';
    container.innerHTML = note + '<div class="oa-timeline">' + activities.map(function (a) {
      return (
        '<div class="oa-item">' +
          '<span class="oa-dot" data-color="' + escapeHtml(a.badge_color || 'primary') + '"></span>' +
          '<div class="oa-time" title="' + escapeHtml(a.exact_time || '') + '">' +
            escapeHtml(a.relative_time || '') +
            (a.exact_time ? ' · ' + escapeHtml(a.exact_time) : '') +
          '</div>' +
          '<p class="oa-title">' + escapeHtml(a.title || '') +
            (a.actor_name ? '<span class="oa-badge">' + escapeHtml(a.actor_name) + '</span>' : '') +
          '</p>' +
          (a.description ? '<p class="oa-desc">' + escapeHtml(a.description) + '</p>' : '') +
        '</div>'
      );
    }).join('') + '</div>';
  };

  function bootNotificationCenter() {
    var run = function () {
      window.initNotificationCenter();
    };
    if (window.requestIdleCallback) {
      window.requestIdleCallback(run, { timeout: 250 });
      return;
    }
    window.setTimeout(run, 1);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootNotificationCenter);
  } else {
    bootNotificationCenter();
  }
})(window, document);
