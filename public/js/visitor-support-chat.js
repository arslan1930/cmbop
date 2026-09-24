/**
 * First-party visitor support chat UI.
 * Talks only to POST /support/chat — API keys never leave the server.
 */
(function () {
  'use strict';

  var root = document.getElementById('slbLiveChat');
  if (!root) return;

  var STORAGE_KEY = root.getAttribute('data-storage-key') || 'slb-support-chat-v1';
  var endpoint = root.getAttribute('data-endpoint') || '';
  var welcome = root.getAttribute('data-welcome') || 'Hi! 👋 How can we help you today?';
  var panel = document.getElementById('slbLiveChatPanel');
  var launcher = document.getElementById('slbLiveChatLauncher');
  var closer = document.getElementById('slbLiveChatClose');
  var logEl = document.getElementById('slbLiveChatMessages');
  var form = document.getElementById('slbLiveChatForm');
  var input = document.getElementById('slbLiveChatInput');
  var sendBtn = document.getElementById('slbLiveChatSend');
  var typing = document.getElementById('slbLiveChatTyping');
  var errorEl = document.getElementById('slbLiveChatError');
  var sending = false;
  var messages = [];
  var sessionId = '';

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function nowIso() {
    return new Date().toISOString();
  }

  function formatTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function uuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      var v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  function loadState() {
    try {
      var raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      var parsed = JSON.parse(raw);
      if (parsed && Array.isArray(parsed.messages)) {
        messages = parsed.messages.slice(-50);
      }
      if (parsed && typeof parsed.sessionId === 'string' && parsed.sessionId) {
        sessionId = parsed.sessionId;
      }
    } catch (e) {
      messages = [];
    }
  }

  function saveState() {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify({
        sessionId: sessionId,
        messages: messages.slice(-50),
      }));
    } catch (e) {
      /* private mode / quota */
    }
  }

  function ensureWelcome() {
    if (messages.length) return;
    messages.push({
      role: 'assistant',
      content: welcome,
      at: nowIso(),
    });
    saveState();
  }

  function historyForApi() {
    var items = messages.filter(function (m) {
      return m.role === 'user' || m.role === 'assistant';
    });
    if (items.length && items[items.length - 1].role === 'user') {
      items = items.slice(0, -1);
    }
    return items.slice(-12).map(function (m) {
      return { role: m.role, content: String(m.content || '').slice(0, 2000) };
    });
  }

  function render() {
    if (!logEl) return;
    logEl.innerHTML = '';
    messages.forEach(function (m) {
      var row = document.createElement('div');
      var isUser = m.role === 'user';
      row.className = 'slb-live-chat__row ' + (isUser ? 'slb-live-chat__row--user' : 'slb-live-chat__row--support');

      var bubble = document.createElement('div');
      bubble.className = 'slb-live-chat__bubble ' + (isUser ? 'slb-live-chat__bubble--user' : 'slb-live-chat__bubble--support');
      bubble.textContent = m.content || '';

      var meta = document.createElement('div');
      meta.className = 'slb-live-chat__meta';
      meta.textContent = (isUser ? 'You · ' : 'Support · ') + formatTime(m.at);

      row.appendChild(bubble);
      row.appendChild(meta);

      var reactions = document.createElement('div');
      reactions.className = 'slb-live-chat__reactions';
      ['👍', '❤️', '🙏'].forEach(function (emoji) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'slb-live-chat__reaction' + (m.reaction === emoji ? ' is-on' : '');
        button.textContent = emoji;
        button.setAttribute('aria-label', 'React ' + emoji);
        button.addEventListener('click', function () {
          m.reaction = m.reaction === emoji ? '' : emoji;
          saveState();
          render();
        });
        reactions.appendChild(button);
      });
      row.appendChild(reactions);
      logEl.appendChild(row);
    });
    if (typing) {
      logEl.appendChild(typing);
    }
    logEl.scrollTop = logEl.scrollHeight;
  }

  function setTyping(on) {
    if (!typing) return;
    typing.classList.toggle('is-on', !!on);
    typing.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function setError(text) {
    if (!errorEl) return;
    if (!text) {
      errorEl.textContent = '';
      errorEl.classList.remove('is-on');
      return;
    }
    errorEl.textContent = text;
    errorEl.classList.add('is-on');
  }

  function failMessage(source, fallback) {
    if (typeof window.slbHttpMessage === 'function') {
      return window.slbHttpMessage(source, fallback);
    }
    return fallback || 'Something went wrong. Please try again.';
  }

  function sendChatMessage(message) {
    var body = {
      message: message,
      session_id: sessionId || null,
      history: historyForApi(),
    };

    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok && !!(data && data.ok), status: res.status, data: data };
      });
    }).then(function (result) {
      if (result.ok && result.data && result.data.reply) {
        return { ok: true, reply: String(result.data.reply) };
      }
      var msg = (result.data && (result.data.message || result.data.error))
        || failMessage(result, 'We could not send that message.');
      return { ok: false, error: msg };
    }).catch(function () {
      return { ok: false, error: 'Network error. Check your connection and try again.' };
    });
  }

  function isOpen() {
    return root.classList.contains('is-open');
  }

  function openChat() {
    root.classList.add('is-open');
    if (launcher) launcher.setAttribute('aria-expanded', 'true');
    if (panel) {
      panel.setAttribute('aria-hidden', 'false');
      panel.removeAttribute('inert');
    }
    render();
    window.setTimeout(function () {
      if (input) input.focus();
    }, 20);
  }

  function closeChat() {
    root.classList.remove('is-open');
    if (launcher) {
      launcher.setAttribute('aria-expanded', 'false');
      launcher.focus();
    }
    if (panel) {
      panel.setAttribute('aria-hidden', 'true');
      panel.setAttribute('inert', '');
    }
  }

  function openTawk() {
    if (!window.Tawk_API || typeof window.Tawk_API.maximize !== 'function') return false;
    window.slbTawkKeepOpen = true;
    if (typeof window.Tawk_API.showWidget === 'function') window.Tawk_API.showWidget();
    document.documentElement.classList.add('tawk-open');
    window.Tawk_API.maximize();
    if (typeof window.slbPinTawk === 'function') window.slbPinTawk();
    return true;
  }

  function toggleChat() {
    if (openTawk()) return;
    if (isOpen()) closeChat();
    else openChat();
  }

  function syncKeyboard() {
    var inset = 0;
    var vv = window.visualViewport;
    if (vv) {
      inset = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
    }
    root.style.setProperty('--slb-live-chat-keyboard', inset + 'px');
  }

  function submitComposer() {
    if (sending || !input) return;
    var text = String(input.value || '').trim();
    if (!text) return;
    if (text.length > 4000) {
      setError('Please keep messages under 4,000 characters.');
      return;
    }

    sending = true;
    if (sendBtn) sendBtn.disabled = true;
    setError('');
    messages.push({ role: 'user', content: text, at: nowIso() });
    input.value = '';
    saveState();
    render();
    setTyping(true);
    logEl.scrollTop = logEl.scrollHeight;

    sendChatMessage(text).then(function (result) {
      setTyping(false);
      if (result.ok && result.reply) {
        messages.push({ role: 'assistant', content: result.reply, at: nowIso() });
      } else {
        setError(result.error || 'We could not send that message.');
      }
      saveState();
      render();
    }).finally(function () {
      sending = false;
      if (sendBtn) sendBtn.disabled = false;
      if (input) input.focus();
    });
  }

  if (!sessionId) sessionId = uuid();
  loadState();
  if (!sessionId) sessionId = uuid();
  ensureWelcome();
  if (panel) {
    panel.setAttribute('aria-hidden', 'true');
    panel.setAttribute('inert', '');
  }
  render();

  function initLauncherLottie() {
    var box = document.getElementById('slbLiveChatLottie');
    if (!box || !window.lottie || typeof window.lottie.loadAnimation !== 'function') return;
    var src = box.getAttribute('data-lottie');
    if (!src || box._slbLottie) return;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var anim = window.lottie.loadAnimation({
      container: box,
      renderer: 'svg',
      loop: true,
      autoplay: false,
      path: src,
    });
    box._slbLottie = anim;
    anim.addEventListener('DOMLoaded', function () {
      anim.goToAndStop(0, true);
    });
    if (reduce || !launcher) return;
    launcher.addEventListener('mouseenter', function () {
      anim.goToAndPlay(0, true);
    });
    launcher.addEventListener('mouseleave', function () {
      anim.goToAndStop(0, true);
    });
  }

  initLauncherLottie();
  if (launcher) launcher.addEventListener('click', toggleChat);
  if (closer) closer.addEventListener('click', closeChat);
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitComposer();
    });
  }
  if (input) {
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submitComposer();
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) {
      e.preventDefault();
      closeChat();
    }
  });

  window.addEventListener('resize', syncKeyboard);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncKeyboard);
    window.visualViewport.addEventListener('scroll', syncKeyboard);
  }
  syncKeyboard();

  window.sendChatMessage = sendChatMessage;
  window.slbOpenSupport = function () {
    if (openTawk()) return;
    openChat();
  };
})();
