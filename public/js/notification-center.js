(function (window, document) {
  'use strict';

  const emojiByType = {
    message: '📩',
    chat_reply: '💬',
    order_created: '📦',
    order_accepted: '✅',
    order_rejected: '❌',
    guest_post_published: '✍',
    order_completed: '🚀',
    order_updated: '📈',
    modification_requested: '⚠',
    payment_received: '💰',
    payment_failed: '⚠',
    system: '🔔',
    account: '👤'
  };

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
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
    this.loadMoreBtn = root.querySelector('[data-nc-load-more]');
    this.footer = root.querySelector('[data-nc-footer]');

    this.bind();
    this.refreshCount();
    this.pollTimer = setInterval(() => this.refreshCount(true), 20000);
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
      if (!self.root.contains(e.target)) self.close();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && self.open) self.close();
    });

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

    if (this.loadMoreBtn) {
      this.loadMoreBtn.addEventListener('click', function () {
        if (self.hasMore && !self.loading) {
          self.page += 1;
          self.fetchPage(true);
        }
      });
    }
  };

  NotificationCenter.prototype.toggle = function () {
    if (this.open) this.close();
    else this.openPanel();
  };

  NotificationCenter.prototype.openPanel = function () {
    this.open = true;
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
  };

  NotificationCenter.prototype.setUnread = function (count, pulse) {
    this.unread = count || 0;
    if (this.unread > 0) {
      this.badge.textContent = this.unread > 99 ? '99+' : String(this.unread);
      this.badge.classList.add('is-visible');
      if (pulse) {
        this.btn.classList.remove('has-unread');
        void this.btn.offsetWidth;
        this.btn.classList.add('has-unread');
      } else {
        this.btn.classList.add('has-unread');
      }
    } else {
      this.badge.classList.remove('is-visible');
      this.btn.classList.remove('has-unread');
    }
  };

  NotificationCenter.prototype.refreshCount = function (pulseOnIncrease) {
    const self = this;
    const prev = this.unread;
    fetch(this.config.unreadUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) return;
        const next = data.unread_count || 0;
        self.setUnread(next, !!(pulseOnIncrease && next > prev));
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

    const params = new URLSearchParams({
      page: String(this.page),
      per_page: '15',
      status: this.status,
      category: this.filter,
      q: this.query || ''
    });

    fetch(this.config.indexUrl + '?' + params.toString(), {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        self.loading = false;
        if (!data.success) {
          self.list.innerHTML = '<div class="nc-empty">Could not load notifications.</div>';
          return;
        }
        const batch = data.notifications || [];
        self.items = append ? self.items.concat(batch) : batch;
        self.hasMore = !!(data.pagination && data.pagination.has_more);
        self.setUnread(data.unread_count || 0);
        self.renderList();
        if (self.footer) self.footer.style.display = self.hasMore ? 'block' : 'none';
      })
      .catch(function () {
        self.loading = false;
        self.list.innerHTML = '<div class="nc-empty">Could not load notifications.</div>';
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

  NotificationCenter.prototype.renderItem = function (n) {
    const icon = emojiByType[n.type] || '🔔';
    const unreadClass = n.is_unread ? ' is-unread' : '';
    return (
      '<button type="button" class="nc-item' + unreadClass + '" data-nc-id="' + n.id + '" data-nc-url="' + escapeHtml(n.action_url || '') + '">' +
        '<div class="nc-icon" data-cat="' + escapeHtml(n.category || 'system') + '" aria-hidden="true">' + icon + '</div>' +
        '<div>' +
          '<p class="nc-item-title">' + escapeHtml(n.title) + '</p>' +
          '<p class="nc-item-msg">' + escapeHtml(n.message || '') + '</p>' +
          '<div class="nc-item-meta">' +
            '<span>' + escapeHtml(relativeTime(n.created_at)) + '</span>' +
            '<span>' + (n.is_unread ? 'Unread' : 'Read') + '</span>' +
            '<span>' + escapeHtml((n.priority || 'normal')) + '</span>' +
            (n.action_url ? '<span class="nc-item-action">' + escapeHtml(n.action_label || 'View details') + ' →</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="nc-item-tools">' +
          '<span class="nc-dot" aria-hidden="true"></span>' +
          (n.is_unread ? '<span class="nc-tool" data-nc-tool="read" data-id="' + n.id + '">Read</span>' : '') +
          '<span class="nc-tool" data-nc-tool="archive" data-id="' + n.id + '">Archive</span>' +
          '<span class="nc-tool" data-nc-tool="delete" data-id="' + n.id + '">Delete</span>' +
        '</div>' +
      '</button>'
    );
  };

  NotificationCenter.prototype.post = function (url) {
    return fetch(url, {
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
    return fetch(url, {
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
      indexUrl: root.getAttribute('data-index-url'),
      unreadUrl: root.getAttribute('data-unread-url'),
      readUrl: root.getAttribute('data-read-url'),
      readAllUrl: root.getAttribute('data-read-all-url'),
      archiveUrl: root.getAttribute('data-archive-url'),
      destroyUrl: root.getAttribute('data-destroy-url')
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

  document.addEventListener('DOMContentLoaded', function () {
    window.initNotificationCenter();
  });
})(window, document);
