/**
 * Compact per-order publisher↔advertiser thread for Tasks / order details.
 * Uses the same /chat/messages and /chat/send APIs as the modal OrderChat.
 */
(function (window, document) {
  'use strict';

  var POLL_MS = 8000;
  var instances = {};

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
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

  function safeChatError(message, fallback) {
    var text = typeof message === 'string' ? message.trim() : '';
    if (!text || text.length > 200 || /SQLSTATE|Unknown column|no such table|Integrity constraint|stack trace|\.php/i.test(text)) {
      return fallback;
    }
    return text;
  }

  function messageHtml(msg, currentUserId) {
    var isOwn = Number(msg.user_id) === Number(currentUserId);
    var isBlocked = !!(msg.is_blocked);
    var messageClass = isOwn ? 'chat-bubble chat-bubble--own' : 'chat-bubble chat-bubble--other';
    if (isBlocked) {
      messageClass += ' chat-bubble--blocked';
    }
    var alignClass = isOwn ? 'justify-content-end' : 'justify-content-start';
    var senderName = isOwn ? 'You' : escapeHtml((msg.user && msg.user.name) || 'User');
    var time = msg.created_at ? new Date(msg.created_at).toLocaleString() : '';
    var blockedNote = isBlocked
      ? '<div class="chat-bubble__blocked-note">Not delivered — personal contact details aren’t allowed.</div>'
      : '';
    var ticks = isOwn
      ? '<span class="chat-ticks' + (msg.is_read ? ' chat-ticks--read' : '') + '" aria-hidden="true"><i class="fa-solid fa-check-double"></i></span>'
      : '';
    return (
      '<div class="d-flex ' + alignClass + ' mb-2" data-message-id="' + escapeHtml(msg.id) + '">' +
      '<div class="' + messageClass + '">' +
      '<div class="chat-bubble__meta mb-1">' + senderName + ' · ' + time + '</div>' +
      '<div>' + escapeHtml(msg.message || '') + '</div>' +
      blockedNote + ticks +
      '</div></div>'
    );
  }

  function OrderInlineThread(container, options) {
    this.container = container;
    this.options = options || {};
    this.baseUrl = (this.options.baseUrl || window.location.origin || '').replace(/\/$/, '');
    this.orderId = this.options.orderId;
    this.orderNumber = this.options.orderNumber || '';
    this.currentUserId = null;
    this.lastMessageId = 0;
    this.pollTimer = null;
    this.canSend = true;
    this.uid = 'oit-' + String(this.orderId || 'x') + '-' + String(Date.now());
  }

  OrderInlineThread.prototype.mount = function () {
    if (!this.container || !this.orderId) {
      return this;
    }
    this.container.innerHTML =
      '<div class="order-inline-thread" data-order-id="' + escapeHtml(this.orderId) + '">' +
        '<div class="d-flex justify-content-between align-items-center gap-2 mb-2">' +
          '<h6 class="mb-0">Messages' + (this.orderNumber ? ' · ' + escapeHtml(this.orderNumber) : '') + '</h6>' +
          '<span class="small text-muted order-inline-thread__presence" hidden></span>' +
        '</div>' +
        '<div class="order-inline-thread__messages" role="log" aria-live="polite"></div>' +
        '<p class="small text-muted mb-2 d-none order-inline-thread__note"></p>' +
        '<form class="order-inline-thread__form">' +
          '<label class="visually-hidden" for="' + this.uid + '-input">Message</label>' +
          '<div class="d-flex gap-2">' +
            '<textarea id="' + this.uid + '-input" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="Write a message to the other party…"></textarea>' +
            '<button type="submit" class="btn btn-sm btn-primary align-self-end">' +
              '<i class="fa fa-paper-plane" aria-hidden="true"></i><span class="visually-hidden"> Send</span>' +
            '</button>' +
          '</div>' +
        '</form>' +
      '</div>';

    var self = this;
    this.messagesEl = this.container.querySelector('.order-inline-thread__messages');
    this.formEl = this.container.querySelector('.order-inline-thread__form');
    this.inputEl = this.container.querySelector('textarea');
    this.noteEl = this.container.querySelector('.order-inline-thread__note');
    this.presenceEl = this.container.querySelector('.order-inline-thread__presence');
    this.sendBtn = this.container.querySelector('button[type="submit"]');

    if (this.formEl) {
      this.formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        self.send();
      });
    }
    if (this.inputEl) {
      this.inputEl.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'Enter') {
          e.preventDefault();
          self.send();
        }
      });
    }

    this.load(false);
    this.startPoll();
    return this;
  };

  OrderInlineThread.prototype.unmount = function () {
    this.stopPoll();
    if (this.container) {
      this.container.innerHTML = '';
    }
  };

  OrderInlineThread.prototype.startPoll = function () {
    var self = this;
    this.stopPoll();
    this.pollTimer = window.setInterval(function () {
      if (document.hidden) return;
      self.load(true);
    }, POLL_MS);
  };

  OrderInlineThread.prototype.stopPoll = function () {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  };

  OrderInlineThread.prototype.setComposerEnabled = function (enabled, note) {
    this.canSend = !!enabled;
    if (this.inputEl) {
      this.inputEl.disabled = !enabled;
      this.inputEl.placeholder = enabled
        ? 'Write a message to the other party…'
        : 'Chat is read-only for this order';
    }
    if (this.sendBtn) this.sendBtn.disabled = !enabled;
    if (this.noteEl) {
      if (note) {
        this.noteEl.textContent = note;
        this.noteEl.classList.remove('d-none');
      } else {
        this.noteEl.textContent = '';
        this.noteEl.classList.add('d-none');
      }
    }
  };

  OrderInlineThread.prototype.renderPresence = function (counterpart) {
    if (!this.presenceEl) return;
    var label = counterpart && counterpart.label ? String(counterpart.label) : '';
    if (!label) {
      this.presenceEl.hidden = true;
      this.presenceEl.textContent = '';
      return;
    }
    this.presenceEl.textContent = label;
    this.presenceEl.hidden = false;
  };

  OrderInlineThread.prototype.showError = function (message) {
    if (!this.messagesEl) return;
    this.messagesEl.innerHTML =
      '<div class="text-center text-danger py-3 small">' + escapeHtml(message) + '</div>';
  };

  OrderInlineThread.prototype.renderMessages = function (messages, currentUserId) {
    if (!this.messagesEl) return;
    if (!messages || messages.length === 0) {
      this.messagesEl.innerHTML =
        '<div class="text-center text-muted py-3 small">No messages yet. Start the conversation.</div>';
      return;
    }
    this.messagesEl.innerHTML = messages.map(function (msg) {
      return messageHtml(msg, currentUserId);
    }).join('');
    this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
  };

  OrderInlineThread.prototype.appendMessages = function (messages, currentUserId) {
    if (!this.messagesEl || !messages.length) return;
    var empty = this.messagesEl.querySelector('.text-muted, .text-danger');
    if (empty && this.messagesEl.children.length === 1) {
      this.messagesEl.innerHTML = '';
    }
    var wasNearBottom = this.messagesEl.scrollHeight - this.messagesEl.scrollTop - this.messagesEl.clientHeight < 80;
    var self = this;
    messages.forEach(function (msg) {
      if (self.messagesEl.querySelector('[data-message-id="' + msg.id + '"]')) return;
      self.messagesEl.insertAdjacentHTML('beforeend', messageHtml(msg, currentUserId));
    });
    if (wasNearBottom) {
      this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    }
  };

  OrderInlineThread.prototype.load = function (incremental) {
    var self = this;
    if (!this.orderId) return;
    var url = this.baseUrl + '/chat/messages/' + this.orderId;
    if (incremental && this.lastMessageId > 0) {
      url += '?since_id=' + encodeURIComponent(this.lastMessageId);
    }

    fetch(url, {
      method: 'GET',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      credentials: 'same-origin',
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          if (!incremental) {
            self.showError(safeChatError(data.message, 'Failed to load messages. Please try again.'));
          }
          return;
        }
        self.currentUserId = data.current_user_id;
        self.setComposerEnabled(data.can_send !== false, data.composer_note || (data.order_details && data.order_details.composer_note));
        self.renderPresence(data.order_details && data.order_details.counterpart);
        var messages = data.messages || [];
        if (incremental) {
          if (messages.length) {
            self.appendMessages(messages, data.current_user_id);
          }
        } else {
          self.renderMessages(messages, data.current_user_id);
        }
        messages.forEach(function (msg) {
          if (msg.id && msg.id > self.lastMessageId) {
            self.lastMessageId = msg.id;
          }
        });
      })
      .catch(function () {
        if (!incremental) {
          self.showError('Failed to load messages. Please try again.');
        }
      });
  };

  OrderInlineThread.prototype.send = function () {
    var self = this;
    var message = this.inputEl ? this.inputEl.value.trim() : '';
    if (!this.orderId || !message) return;
    if (!this.canSend) {
      if (window.Swal) Swal.fire('Chat closed', 'This order chat is read-only.', 'info');
      return;
    }

    if (this.sendBtn) {
      this.sendBtn.disabled = true;
    }

    fetch(this.baseUrl + '/chat/send/' + this.orderId, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
      },
      credentials: 'same-origin',
      body: JSON.stringify({ message: message }),
    })
      .then(function (r) {
        return r.json().then(function (data) {
          return { ok: r.ok, status: r.status, data: data };
        });
      })
      .then(function (res) {
        if (res.data && res.data.success) {
          if (self.inputEl) self.inputEl.value = '';
          if (res.data.message) {
            self.appendMessages([res.data.message], res.data.current_user_id || self.currentUserId);
            if (res.data.message.id && res.data.message.id > self.lastMessageId) {
              self.lastMessageId = res.data.message.id;
            }
            if (self.messagesEl) self.messagesEl.scrollTop = self.messagesEl.scrollHeight;
          } else {
            self.load(false);
          }
        } else {
          var msg = safeChatError(res.data && res.data.message, 'Failed to send message');
          if (res.status === 422 && res.data && res.data.can_send === false) {
            self.setComposerEnabled(false, msg);
          }
          if (window.Swal) Swal.fire('Error', msg, 'error');
        }
      })
      .catch(function () {
        if (window.Swal) Swal.fire('Error', 'Failed to send message', 'error');
      })
      .finally(function () {
        if (self.sendBtn) self.sendBtn.disabled = !self.canSend;
      });
  };

  function mount(container, options) {
    if (typeof container === 'string') {
      container = document.querySelector(container);
    }
    if (!container) return null;
    var key = container.id || container.getAttribute('data-thread-host') || 'default';
    if (instances[key]) {
      instances[key].unmount();
    }
    var thread = new OrderInlineThread(container, options || {});
    instances[key] = thread;
    thread.mount();
    return thread;
  }

  function unmount(container) {
    if (typeof container === 'string') {
      container = document.querySelector(container);
    }
    var key = container && (container.id || container.getAttribute('data-thread-host') || 'default');
    if (key && instances[key]) {
      instances[key].unmount();
      delete instances[key];
    }
  }

  window.OrderInlineThread = {
    mount: mount,
    unmount: unmount,
  };
})(window, document);
