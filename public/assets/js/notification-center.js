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
      // Cross-origin absolute URL from misconfigured APP_URL — use path only.
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
    this.status = 'active';
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
    this.limit = 3;

    this.bind();
    this.refreshCount();
    this.pollTimer = setInterval(() => this.refreshCount(true), 45000);
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
      if (self.root.contains(e.target) || self.panel.contains(e.target)) return;
      self.close();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.open) self.close();
    });

    window.addEventListener('resize', function () {
      if (self.open) self.positionPanel();
    });
    window.addEventListener('scroll', function () {
      if (self.open) self.positionPanel();
    }, true);

    this.root.querySelectorAll('[data-nc-filter]').forEach(function (el) {
      el.addEventListener('click', function () {
        self.root.querySelectorAll('[data-nc-filter]').forEach(function (b) {
          b.classList.remove('is-active');
        });
        el.classList.add('is-active');
        const value = el.getAttribute('data-nc-filter');
        if (value === 'unread') {
          self.status = 'unread';
          self.filter = 'all';
        } else {
          self.status = 'active';
          self.filter = value;
        }
        self.reload();
      });
    });

    const markAll = this.root.querySelector('[data-nc-mark-all]');
    if (markAll) {
      markAll.addEventListener('click', function () {
        self.markAllRead();
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
    }
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
    // Portal to body so sticky/overflow topbar chrome cannot clip or swallow clicks.
    // Keep nc-theme on the panel so CSS variables still resolve outside .nc-bell-wrap.
    this.panel.classList.add('nc-theme');
    if (this.panel.parentElement !== document.body) {
      this._panelHome = this.panel.parentElement;
      document.body.appendChild(this.panel);
    }
    this.positionPanel();
    this.panel.classList.add('is-open');
    this.btn.classList.add('is-open');
    this.btn.setAttribute('aria-expanded', 'true');
    this.reload();
  };

  NotificationCenter.prototype.close = function () {
    this.open = false;
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

  NotificationCenter.prototype.setUnread = function (count) {
    this.unread = count || 0;
    // Pulse only the badge — never the bell button
    this.btn.classList.remove('has-unread');
    if (window.PulseBadge) {
      window.PulseBadge.sync(this.badge, this.unread);
      return;
    }
    if (this.unread > 0) {
      this.badge.textContent = this.unread > 99 ? '99+' : String(this.unread);
      this.badge.classList.add('is-visible', 'is-pulsing', 'pulse-badge');
      this.badge.style.display = 'inline-flex';
    } else {
      this.badge.classList.remove('is-visible', 'is-pulsing');
      this.badge.style.display = 'none';
    }
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
        self.setUnread(next);
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
      per_page: String(this.limit),
      status: this.status,
      category: this.filter,
      q: this.query || ''
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
        const batch = data.notifications || [];
        self.items = batch.slice(0, self.limit);
        self.hasMore = !!(data.pagination && (data.pagination.has_more || data.pagination.total > self.limit));
        try {
          self.setUnread(data.unread_count || 0);
          self.renderList();
        } catch (err) {
          self.showLoadError(err);
          return;
        }
        if (self.footer) self.footer.style.display = 'block';
        if (self.showAllLink) {
          self.showAllLink.style.display = (data.pagination && data.pagination.total > 0) ? 'inline-flex' : 'none';
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

  NotificationCenter.prototype.renderList = function () {
    const self = this;
    if (!this.items.length) {
      this.list.innerHTML = '<div class="nc-empty">You\'re all caught up. New activity will show up here.</div>';
      return;
    }

    const groups = { Today: [], Yesterday: [], Earlier: [] };
    this.items.forEach(function (item) {
      groups[groupKey(item.created_at)].push(item);
    });

    let html = '';
    ['Today', 'Yesterday', 'Earlier'].forEach(function (label) {
      const rows = groups[label];
      if (!rows.length) return;
      html += '<div class="nc-group-label">' + label + '</div>';
      rows.forEach(function (n) {
        html += self.renderItem(n);
      });
    });
    this.list.innerHTML = html;

    this.list.querySelectorAll('[data-nc-id]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (e.target.closest('[data-nc-tool]')) return;
        const id = el.getAttribute('data-nc-id');
        const url = el.getAttribute('data-nc-url');
        self.openNotification(id, url);
      });
    });

    this.list.querySelectorAll('[data-nc-tool="read"]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        self.markRead(btn.getAttribute('data-id'));
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
  NotificationCenter.prototype.renderItem = function (n) {
    const iconName = iconByType[n.type] || n.icon || 'bell';
    const unreadClass = n.is_unread ? ' is-unread' : '';
    const dotPulse = n.is_unread ? ' pulse-badge is-pulsing' : '';
    return (
      '<button type="button" class="nc-item' + unreadClass + '" data-nc-id="' + n.id + '" data-nc-url="' + escapeHtml(n.action_url || '') + '">' +
        '<div class="nc-icon" aria-hidden="true">' + lucideIcon(iconName) + '</div>' +
        '<div class="nc-item-main">' +
          '<p class="nc-item-title">' + escapeHtml(n.title) + '</p>' +
          (n.message ? '<p class="nc-item-msg">' + escapeHtml(n.message) + '</p>' : '') +
          '<div class="nc-item-meta">' +
            '<span class="nc-item-time">' + escapeHtml(relativeTime(n.created_at)) + '</span>' +
            (n.action_url ? '<span class="nc-item-action">' + escapeHtml(n.action_label || 'View details') + ' →</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="nc-item-aside">' +
          '<span class="nc-dot' + dotPulse + '" aria-hidden="true"></span>' +
          '<div class="nc-item-tools">' +
            (n.is_unread ? '<span class="nc-tool" data-nc-tool="read" data-id="' + n.id + '">Read</span>' : '') +
            '<span class="nc-tool" data-nc-tool="archive" data-id="' + n.id + '">Archive</span>' +
            '<span class="nc-tool" data-nc-tool="delete" data-id="' + n.id + '">Delete</span>' +
          '</div>' +
        '</div>' +
      '</button>'
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

  NotificationCenter.prototype.markAllRead = function () {
    const self = this;
    this.post(this.config.readAllUrl).then(function () {
      self.setUnread(0);
      self.reload();
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
      readAllUrl: sameOriginUrl(root.getAttribute('data-read-all-url'), '/notifications/read-all'),
      archiveUrl: sameOriginUrl(root.getAttribute('data-archive-url'), '/notifications/__ID__/archive'),
      destroyUrl: sameOriginUrl(root.getAttribute('data-destroy-url'), '/notifications/__ID__'),
      allUrl: sameOriginUrl(root.getAttribute('data-all-url'), '/notifications/all')
    };
    return new NotificationCenter(root, cfg);
  };

  window.renderOrderActivityTimeline = function (container, activities) {
    if (!container) return;
    if (!activities || !activities.length) {
      container.innerHTML = '<div class="text-muted small">No activity recorded yet.</div>';
      return;
    }
    container.innerHTML = '<div class="oa-timeline">' + activities.map(function (a) {
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
    window.initNotificationCenter();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootNotificationCenter);
  } else {
    bootNotificationCenter();
  }
})(window, document);
