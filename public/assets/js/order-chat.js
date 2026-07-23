/**
 * Shared order chat modal: load, poll, send, deep-links.
 * Pages provide role-specific order-details rendering via callbacks.
 */
(function (window, document) {
  'use strict';

  var POLL_MS = 8000;

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

  function OrderChat(config) {
    this.config = config || {};
    this.baseUrl = (config.baseUrl || window.location.origin || '').replace(/\/$/, '');
    this.currentOrderId = null;
    this.currentUserId = null;
    this.lastMessageId = 0;
    this.pollTimer = null;
    this.canSend = true;
  }

  OrderChat.prototype.init = function () {
    var self = this;
    var form = document.getElementById('chatForm');
    var input = document.getElementById('chatMessageInput');
    var modal = document.getElementById('chatModal');

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        self.send();
      });
    }

    if (input) {
      input.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'Enter') {
          e.preventDefault();
          self.send();
        }
      });
    }

    if (modal) {
      var onHidden = function () {
        self.stopPoll();
        self.currentOrderId = null;
        self.lastMessageId = 0;
        if (typeof self.config.onClose === 'function') {
          self.config.onClose();
        }
      };
      // Prefer jQuery when present (Bootstrap 4/5 modal events); else native.
      if (window.jQuery) {
        window.jQuery(modal).off('hidden.bs.modal.orderChat').on('hidden.bs.modal.orderChat', onHidden);
      } else {
        modal.addEventListener('hidden.bs.modal', onHidden);
      }
    }

    this.handleDeepLink();
  };

  OrderChat.prototype.open = function (orderId, orderNumber) {
    this.currentOrderId = orderId;
    this.lastMessageId = 0;
    var idInput = document.getElementById('chatOrderId');
    var numEl = document.getElementById('chatOrderNumber');
    var detailsEl = document.getElementById('chatOrderDetails');
    if (idInput) idInput.value = orderId;
    if (numEl) numEl.innerText = orderNumber || ('#' + orderId);
    if (detailsEl) {
      detailsEl.classList.add('d-none');
      detailsEl.innerHTML = '';
    }
    this.setComposerEnabled(true, null);
    this.load(false);
    this.showModal();
    this.startPoll();
  };

  OrderChat.prototype.showModal = function () {
    var modalEl = document.getElementById('chatModal');
    if (!modalEl) return;

    // Prefer Bootstrap 5 API. jQuery is loaded without the Bootstrap jQuery plugin,
    // so `$('#chatModal').modal('show')` throws and the chat never opens.
    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
      return;
    }

    if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
      window.jQuery(modalEl).modal('show');
    }
  };

  OrderChat.prototype.hideModal = function () {
    var modalEl = document.getElementById('chatModal');
    if (!modalEl) return;

    if (window.bootstrap && bootstrap.Modal) {
      var instance = bootstrap.Modal.getInstance(modalEl);
      if (instance) instance.hide();
      return;
    }

    if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
      window.jQuery(modalEl).modal('hide');
    }
  };

  OrderChat.prototype.startPoll = function () {
    var self = this;
    this.stopPoll();
    this.pollTimer = setInterval(function () {
      if (self.currentOrderId) {
        self.load(true);
      }
    }, POLL_MS);
  };

  OrderChat.prototype.stopPoll = function () {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  };

  OrderChat.prototype.load = function (incremental) {
    var self = this;
    var orderId = this.currentOrderId;
    if (!orderId) return;

    var url = this.baseUrl + '/chat/messages/' + orderId;
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
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.success) {
          if (!incremental) {
            self.showError(data.message || 'Failed to load messages. Please try again.');
          }
          return;
        }

        self.currentUserId = data.current_user_id;
        self.applyComposerState(data.can_send !== false, data.composer_note || (data.order_details && data.order_details.composer_note));

        if (typeof self.config.renderOrderDetails === 'function') {
          self.config.renderOrderDetails(data.order_details || null);
        }

        var messages = data.messages || [];
        if (incremental) {
          if (messages.length) {
            self.appendMessages(messages, data.current_user_id);
          }
        } else {
          self.renderMessages(messages, data.current_user_id);
          var chatDiv = document.getElementById('chatMessages');
          if (chatDiv) chatDiv.scrollTop = chatDiv.scrollHeight;
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

  OrderChat.prototype.applyComposerState = function (canSend, note) {
    this.canSend = !!canSend;
    this.setComposerEnabled(this.canSend, note || null);
  };

  OrderChat.prototype.setComposerEnabled = function (enabled, note) {
    var input = document.getElementById('chatMessageInput');
    var form = document.getElementById('chatForm');
    var btn = form ? form.querySelector('button[type="submit"]') : null;
    var noteEl = document.getElementById('chatComposerNote');
    if (input) {
      input.disabled = !enabled;
      input.placeholder = enabled ? 'Type your message...' : 'Chat is read-only for this order';
    }
    if (btn) btn.disabled = !enabled;
    if (noteEl) {
      if (note) {
        noteEl.textContent = note;
        noteEl.classList.remove('d-none');
      } else {
        noteEl.textContent = '';
        noteEl.classList.add('d-none');
      }
    }
  };

  OrderChat.prototype.showError = function (message) {
    var el = document.getElementById('chatMessages');
    if (!el) return;
    el.innerHTML =
      '<div class="text-center text-danger py-5">' +
      '<i class="fa fa-exclamation-circle fa-3x mb-3"></i>' +
      '<p>' +
      escapeHtml(message) +
      '</p></div>';
  };

  OrderChat.prototype.renderMessages = function (messages, currentUserId) {
    var el = document.getElementById('chatMessages');
    if (!el) return;
    if (!messages || messages.length === 0) {
      el.innerHTML =
        '<div class="text-center text-muted py-5">' +
        '<i class="fa fa-comments fa-3x mb-3"></i>' +
        '<p>No messages yet. Start the conversation!</p></div>';
      return;
    }
    el.innerHTML = messages.map(function (msg) {
      return OrderChat.messageHtml(msg, currentUserId);
    }).join('');
  };

  OrderChat.prototype.appendMessages = function (messages, currentUserId) {
    var el = document.getElementById('chatMessages');
    if (!el || !messages.length) return;
    var empty = el.querySelector('.text-muted, .text-danger');
    if (empty && el.children.length === 1) {
      el.innerHTML = '';
    }
    var wasNearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    messages.forEach(function (msg) {
      if (el.querySelector('[data-message-id="' + msg.id + '"]')) return;
      el.insertAdjacentHTML('beforeend', OrderChat.messageHtml(msg, currentUserId));
    });
    if (wasNearBottom) {
      el.scrollTop = el.scrollHeight;
    }
  };

  OrderChat.messageHtml = function (msg, currentUserId) {
    var isOwn = msg.user_id === currentUserId;
    var messageClass = isOwn ? 'chat-bubble chat-bubble--own' : 'chat-bubble chat-bubble--other';
    var alignClass = isOwn ? 'justify-content-end' : 'justify-content-start';
    var senderName = isOwn ? 'You' : escapeHtml((msg.user && msg.user.name) || 'User');
    var time = msg.created_at ? new Date(msg.created_at).toLocaleString() : '';
    var messageText = escapeHtml(msg.message || '');
    return (
      '<div class="d-flex ' +
      alignClass +
      ' mb-3" data-message-id="' +
      escapeHtml(msg.id) +
      '">' +
      '<div class="' +
      messageClass +
      '">' +
      '<div class="chat-bubble__meta mb-1">' +
      senderName +
      ' · ' +
      time +
      '</div>' +
      '<div>' +
      messageText +
      '</div></div></div>'
    );
  };

  OrderChat.prototype.send = function () {
    var self = this;
    var orderId = this.currentOrderId || (document.getElementById('chatOrderId') || {}).value;
    var input = document.getElementById('chatMessageInput');
    var message = input ? input.value.trim() : '';
    if (!orderId || !message) return;
    if (!this.canSend) {
      if (window.Swal) Swal.fire('Chat closed', 'This order chat is read-only.', 'info');
      return;
    }

    var form = document.getElementById('chatForm');
    var sendBtn = form ? form.querySelector('button[type="submit"]') : null;
    if (sendBtn) {
      sendBtn.disabled = true;
      sendBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Sending...';
    }

    fetch(this.baseUrl + '/chat/send/' + orderId, {
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
          if (input) input.value = '';
          if (res.data.message) {
            self.appendMessages([res.data.message], res.data.current_user_id || self.currentUserId);
            if (res.data.message.id && res.data.message.id > self.lastMessageId) {
              self.lastMessageId = res.data.message.id;
            }
            var chatDiv = document.getElementById('chatMessages');
            if (chatDiv) chatDiv.scrollTop = chatDiv.scrollHeight;
          } else {
            self.load(false);
          }
        } else {
          var msg = (res.data && res.data.message) || 'Failed to send message';
          if (res.status === 422 && res.data && res.data.can_send === false) {
            self.applyComposerState(false, msg);
          }
          if (window.Swal) Swal.fire('Error', msg, 'error');
        }
      })
      .catch(function () {
        if (window.Swal) Swal.fire('Error', 'Failed to send message', 'error');
      })
      .finally(function () {
        if (sendBtn) {
          sendBtn.disabled = !self.canSend;
          sendBtn.innerHTML = '<i class="fa fa-paper-plane"></i> Send';
        }
      });
  };

  OrderChat.prototype.clearFocusParams = function () {
    var url = new URL(window.location.href);
    if (!url.searchParams.has('focus') && !url.searchParams.has('order')) return;
    url.searchParams.delete('focus');
    url.searchParams.delete('order');
    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
  };

  OrderChat.prototype.handleDeepLink = function () {
    var self = this;
    var params = new URLSearchParams(window.location.search);
    var focus = params.get('focus');
    var orderId = params.get('order');

    if (focus === 'order' && orderId) {
      this.clearFocusParams();
      if (typeof this.config.onFocusOrder === 'function') {
        this.config.onFocusOrder(orderId);
      }
      return;
    }

    if (focus !== 'messages') return;

    if (orderId) {
      this.clearFocusParams();
      this.open(orderId, '#' + orderId);
      return;
    }

    fetch(this.baseUrl + '/chat/unread-summary', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        self.clearFocusParams();
        if (data.success && data.latest_unread_order) {
          self.open(data.latest_unread_order.id, data.latest_unread_order.order_number);
          return;
        }
        if (typeof self.config.onFocusMessagesFallback === 'function') {
          self.config.onFocusMessagesFallback();
        }
      })
      .catch(function () {
        self.clearFocusParams();
      });
  };

  window.OrderChat = OrderChat;
  window.OrderChatEscapeHtml = escapeHtml;
})(window, document);
