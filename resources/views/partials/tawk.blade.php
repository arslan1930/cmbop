{{-- Visitor chat (public + advertiser/publisher). Order chat is separate. --}}
@php
    $useFirstParty = class_exists(\App\Support\VisitorSupportChat::class)
        && method_exists(\App\Support\VisitorSupportChat::class, 'enabled')
        && \App\Support\VisitorSupportChat::enabled()
        && view()->exists('partials.visitor-support-chat');
    $tawkSrc = null;
    $tawkVisitor = null;
    $tawkAttributes = [];
    $tawkTags = [];
    $tawkQuestions = [];
    $tawkCompany = 'SEOLinkBuildings';
    $tawkWelcome = 'Hi! How can we help with guest posts, wallet, or your sites?';
    $supportRole = 'guest';
    if (class_exists(\App\Support\VisitorSupportChat::class)
        && method_exists(\App\Support\VisitorSupportChat::class, 'supportRole')) {
        $supportRole = \App\Support\VisitorSupportChat::supportRole();
    } elseif (auth()->check()) {
        $roleUser = auth()->user();
        $roleName = '';
        if (is_object($roleUser) && method_exists($roleUser, 'activeRoleModel')) {
            $activeRole = $roleUser->activeRoleModel();
            $roleName = strtolower(trim((string) ($activeRole?->name ?? '')));
        }
        if (in_array($roleName, ['advertiser', 'publisher'], true)) {
            $supportRole = $roleName;
        }
    }
    if (! $useFirstParty
        && class_exists(\App\Support\TawkChat::class)
        && method_exists(\App\Support\TawkChat::class, 'embedSrc')) {
        $tawkSrc = \App\Support\TawkChat::embedSrc();
        if ($tawkSrc) {
            $page = '/'.ltrim((string) request()->path(), '/');
            if ($page === '/') {
                $page = '/';
            }
            $tawkAttributes = [
                'role' => $supportRole,
                'page' => $page,
            ];
            $tawkTags = [$supportRole];
            $tawkQuestions = class_exists(\App\Support\TawkChat::class)
                && method_exists(\App\Support\TawkChat::class, 'predefinedMessages')
                ? \App\Support\TawkChat::predefinedMessages($supportRole)
                : [];
            foreach ($tawkQuestions as $i => $question) {
                $tawkAttributes['predefined_'.($i + 1)] = $question;
            }
            $tawkCompany = class_exists(\App\Support\VisitorSupportChat::class)
                && method_exists(\App\Support\VisitorSupportChat::class, 'companyName')
                ? \App\Support\VisitorSupportChat::companyName()
                : (string) config('app.name', 'SEOLinkBuildings');
            $tawkWelcome = class_exists(\App\Support\VisitorSupportChat::class)
                && method_exists(\App\Support\VisitorSupportChat::class, 'welcomeMessage')
                ? \App\Support\VisitorSupportChat::welcomeMessage()
                : 'Hi! How can we help with guest posts, wallet, or your sites?';
            if (auth()->check()) {
                $user = auth()->user();
                $name = trim((string) ($user->name ?? ''));
                $email = trim((string) ($user->email ?? ''));
                $tawkAttributes['user_id'] = (string) ($user->id ?? '');
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $tawkVisitor = [
                        'name' => $name !== '' ? $name : $email,
                        'email' => $email,
                    ];
                }
            }
        }
    }
@endphp
@if ($useFirstParty)
    @include('partials.visitor-support-chat')
@elseif ($tawkSrc)
<script src="{{ asset('assets/vendor/lottie-web/lottie_light.min.js') }}?v={{ @filemtime(public_path('assets/vendor/lottie-web/lottie_light.min.js')) ?: '1' }}" defer></script>
<style>
.slb-chat-mark__unread {
  position: absolute;
  top: 14px;
  right: 14px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 999px;
  background: #dc2626;
  color: #fff;
  font: 600 11px/18px var(--font-sans, system-ui, sans-serif);
  display: none;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.slb-chat-mark__unread.is-on { display: inline-flex; }
.slb-tawk-theme-header {
  display: none;
  position: fixed;
  z-index: 1000004;
  align-items: center;
  gap: 8px;
  padding: 0 10px;
  border-radius: 16px 16px 0 0;
  background: #1a585e;
  color: #fff;
  font: 700 14px/1.2 var(--font-sans, system-ui, sans-serif);
  letter-spacing: 0.01em;
  pointer-events: none;
  box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.08);
}
.slb-tawk-theme-header__icon {
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
}
.slb-tawk-theme-header__icon svg {
  display: block;
  width: 22px;
  height: 22px;
}
.slb-tawk-theme-header__title {
  flex: 1 1 auto;
  min-width: 0;
  color: #fff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
<script>
window.Tawk_API = window.Tawk_API || {};
window.Tawk_LoadStart = new Date();
(function () {
var Tawk_API = window.Tawk_API;
Tawk_API.customStyle = {
  zIndex: 1080,
  visibility: {
    desktop: { position: 'br', xOffset: 16, yOffset: 20 },
    mobile: { position: 'br', xOffset: 10, yOffset: 16 }
  }
};
@if ($tawkVisitor)
Tawk_API.visitor = {!! json_encode($tawkVisitor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
@endif
var tawkAttributes = {!! json_encode($tawkAttributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
var tawkTags = {!! json_encode($tawkTags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
var tawkQuestions = {!! json_encode($tawkQuestions ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
var tawkCompany = {!! json_encode($tawkCompany ?? config('app.name', 'SEOLinkBuildings'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
var tawkWelcome = {!! json_encode($tawkWelcome ?? 'Hi! How can we help with guest posts, wallet, or your sites?', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
var tawkTheme = {
  header: {
    background: '#1a585e',
    text: '#ffffff',
    color: '#ffffff',
    icon: '#ffffff',
    icons: '#ffffff',
    button: '#ffffff',
    action: '#ffffff'
  },
  agent: { messageBackground: '#e6f5f5', messageText: '#1a585e' },
  visitor: { messageBackground: '#1a585e', messageText: '#ffffff' }
};

function tawkSettingsUrl(url) {
  return /va\.tawk\.to\/v1\/(widget-settings)?/.test(String(url || ''));
}

function brandTawkColor(value) {
  if (typeof value !== 'string') return value;
  var hex = value.trim().toLowerCase();
  if (/^#(54c76e|55c87a|55cd82|00ce7d|00d67e|2ecc71|4caf50|3fcf4e|70c656|00b955|1ecd67|25d366)$/.test(hex)) {
    return '#1a585e';
  }
  return value;
}

function brandTawkLine(value) {
  value = brandTawkColor(value);
  var trimmed = value.replace(/<[^>]+>/g, '').replace(/^[\s\u{1F300}-\u{1FAFF}]+/u, '').trim();
  if (/^customer support$/i.test(trimmed)) return tawkCompany;
  if (/^i have a question[.!?]?$/i.test(trimmed)) return tawkQuestions[0] || value;
  if (/^tell me more[.!?]?$/i.test(trimmed)) return tawkQuestions[1] || value;
  if (/^just browsing[.!?]?$/i.test(trimmed)) return tawkQuestions[2] || tawkQuestions[0] || value;
  if (/^hi!? how can we help\??$/i.test(trimmed)) return tawkWelcome;
  if (/^we typically reply in a few minutes\.?$/i.test(trimmed)) return tawkWelcome;
  return value;
}

function brandTawkCopy(value) {
  if (typeof value !== 'string') return value;
  if (value.indexOf('[option]') !== -1) {
    var kept = [];
    var replaced = false;
    value.split('\n').forEach(function (line) {
      if (/^\s*\[option\]/i.test(line)) {
        replaced = true;
        return;
      }
      kept.push(brandTawkLine(line));
    });
    if (replaced && tawkQuestions.length) {
      tawkQuestions.forEach(function (question) {
        kept.push('[option]' + question);
      });
    }
    return kept.join('\n');
  }
  return brandTawkLine(value);
}

function brandTawkNode(node) {
  if (!node || typeof node !== 'object') return;
  if (node.theme && typeof node.theme === 'object') {
    node.theme.header = Object.assign({}, node.theme.header, tawkTheme.header);
    node.theme.agent = Object.assign({}, node.theme.agent, tawkTheme.agent);
    node.theme.visitor = Object.assign({}, node.theme.visitor, tawkTheme.visitor);
  }
  if (node.type === 'suggested-messages' && node.content && Array.isArray(node.content.options) && tawkQuestions.length) {
    node.content.options = tawkQuestions.map(function (question) {
      return { text: question };
    });
  }
  if (Array.isArray(node.options) && node.options.length && node.options.every(function (item) {
    return item && typeof item === 'object' && typeof item.text === 'string';
  }) && node.options.some(function (item) {
    return /i have a question|tell me more|just browsing/i.test(item.text);
  }) && tawkQuestions.length) {
    node.options = tawkQuestions.map(function (question) {
      return Object.assign({}, node.options[0], { text: question });
    });
  }
  Object.keys(node).forEach(function (key) {
    var value = node[key];
    if (typeof value === 'string') node[key] = brandTawkCopy(value);
    else brandTawkNode(value);
  });
}

function brandTawkSettings(payload) {
  if (!payload || typeof payload !== 'object') return payload;
  brandTawkNode(payload);
  return payload;
}

function brandTawkSocketData(data) {
  if (typeof data !== 'string' || data.indexOf('{') !== 0) return data;
  if (data.indexOf('[option]') === -1 && !/i have a question|tell me more|customer support|hi!? how can we help/i.test(data) && data.indexOf('"theme"') === -1) {
    return data;
  }
  try {
    return JSON.stringify(brandTawkSettings(JSON.parse(data)));
  } catch (e) {
    return brandTawkCopy(data);
  }
}

var NativeWebSocket = window.WebSocket;
if (typeof NativeWebSocket === 'function') {
  window.WebSocket = function (url, protocols) {
    var socket = protocols === undefined ? new NativeWebSocket(url) : new NativeWebSocket(url, protocols);
    if (!/tawk\.to/i.test(String(url || ''))) return socket;
    var add = socket.addEventListener.bind(socket);
    socket.addEventListener = function (type, listener, options) {
      if (type !== 'message' || typeof listener !== 'function') {
        return add(type, listener, options);
      }
      return add('message', function (event) {
        var next = brandTawkSocketData(event.data);
        if (next === event.data) return listener.call(this, event);
        return listener.call(this, new MessageEvent('message', {
          data: next,
          origin: event.origin,
          lastEventId: event.lastEventId,
          source: event.source,
          ports: event.ports
        }));
      }, options);
    };
    return socket;
  };
  window.WebSocket.prototype = NativeWebSocket.prototype;
  window.WebSocket.CONNECTING = NativeWebSocket.CONNECTING;
  window.WebSocket.OPEN = NativeWebSocket.OPEN;
  window.WebSocket.CLOSING = NativeWebSocket.CLOSING;
  window.WebSocket.CLOSED = NativeWebSocket.CLOSED;
}

var nativeFetch = window.fetch;
if (typeof nativeFetch === 'function') {
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url);
    return nativeFetch.apply(this, arguments).then(function (response) {
      if (!tawkSettingsUrl(url)) return response;
      return response.json().then(function (body) {
        brandTawkSettings(body);
        return new Response(JSON.stringify(body), {
          status: response.status,
          statusText: response.statusText,
          headers: { 'Content-Type': 'application/json' }
        });
      });
    });
  };
}

var xhrOpen = XMLHttpRequest.prototype.open;
var xhrSend = XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.open = function (method, url) {
  this.__slbTawkUrl = String(url || '');
  return xhrOpen.apply(this, arguments);
};
function brandTawkXhr(xhr) {
  if (xhr.readyState !== 4 || xhr.status < 200 || xhr.status >= 300) return;
  try {
    var branded = JSON.stringify(brandTawkSettings(JSON.parse(xhr.responseText)));
    Object.defineProperty(xhr, 'responseText', { configurable: true, get: function () { return branded; } });
    Object.defineProperty(xhr, 'response', { configurable: true, get: function () { return branded; } });
  } catch (e) {}
}

XMLHttpRequest.prototype.send = function () {
  if (tawkSettingsUrl(this.__slbTawkUrl)) {
    var xhr = this;
    var previousOnload = xhr.onload;
    xhr.addEventListener('readystatechange', function () { brandTawkXhr(xhr); }, true);
    xhr.addEventListener('load', function () { brandTawkXhr(xhr); }, true);
    xhr.onload = function () {
      brandTawkXhr(xhr);
      if (typeof previousOnload === 'function') return previousOnload.apply(this, arguments);
    };
  }
  return xhrSend.apply(this, arguments);
};
var launcher = document.createElement('button');
launcher.type = 'button';
launcher.className = 'slb-chat-mark';
launcher.setAttribute('aria-label', 'Open chat');
launcher.style.cssText = 'position:fixed;right:16px;bottom:20px;z-index:1081;width:84px;height:84px;padding:0;border:0;background:transparent;cursor:pointer;line-height:0;overflow:visible';
var mark = document.createElement('span');
mark.style.cssText = 'display:block;width:84px;height:84px;pointer-events:none';
var unreadBadge = document.createElement('span');
unreadBadge.className = 'slb-chat-mark__unread';
unreadBadge.setAttribute('hidden', '');
unreadBadge.setAttribute('aria-hidden', 'true');
launcher.appendChild(mark);
launcher.appendChild(unreadBadge);

var tawkLottieSrc = {!! json_encode(asset('assets/vendor/lottie-web/lottie_light.min.js').'?v='.(@filemtime(public_path('assets/vendor/lottie-web/lottie_light.min.js')) ?: '1'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
var chatBoxData = {!! file_get_contents(public_path('assets/vendor/lottie/chat-box.json')) ?: '{}' !!};
function slbTawkWhenLottie(fn) {
  if (window.lottie && typeof window.lottie.loadAnimation === 'function') {
    fn();
    return;
  }
  if (typeof window.slbWhenLottie === 'function') {
    window.slbWhenLottie(fn);
    return;
  }
  var existing = document.querySelector('script[src*="lottie_light"], script[data-slb-tawk-lottie]');
  if (existing) {
    existing.addEventListener('load', fn);
    return;
  }
  var script = document.createElement('script');
  script.src = tawkLottieSrc;
  script.async = true;
  script.setAttribute('data-slb-tawk-lottie', '1');
  script.addEventListener('load', fn);
  (document.head || document.body).appendChild(script);
}

function paintMark() {
  if (launcher.dataset.ready === '1') return;
  if (!window.lottie || typeof window.lottie.loadAnimation !== 'function') {
    slbTawkWhenLottie(paintMark);
    return;
  }
  launcher.dataset.ready = '1';
  mark.replaceChildren();
  var chatBoxSrc = {!! json_encode(asset('assets/vendor/lottie/chat-box.json').'?v='.(@filemtime(public_path('assets/vendor/lottie/chat-box.json')) ?: '1'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
  var animOpts = {
    container: mark,
    renderer: 'svg',
    loop: false,
    autoplay: false
  };
  if (chatBoxData && chatBoxData.layers) {
    animOpts.animationData = JSON.parse(JSON.stringify(chatBoxData));
  } else {
    animOpts.path = chatBoxSrc;
  }
  var anim = window.lottie.loadAnimation(animOpts);
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hovering = false;
  function restMark() {
    hovering = false;
    anim.loop = false;
    anim.goToAndStop(40, true);
  }
  function playMark() {
    if (reduceMotion || !anim.isLoaded) return;
    hovering = true;
    anim.loop = false;
    anim.goToAndPlay(1, true);
  }
  anim.addEventListener('complete', function () {
    if (hovering) anim.goToAndPlay(1, true);
  });
  anim.addEventListener('DOMLoaded', function () {
    restMark();
    var svg = mark.querySelector('svg');
    if (svg) {
      svg.style.width = '84px';
      svg.style.height = '84px';
    }
  });
  launcher.addEventListener('mouseenter', playMark);
  launcher.addEventListener('mouseleave', restMark);
  launcher.addEventListener('pointerenter', playMark);
  launcher.addEventListener('pointerleave', restMark);
}

function tawkPanel() {
  return Array.prototype.find.call(document.querySelectorAll('iframe[title="chat widget"], iframe[title="Chat widget"]'), function (iframe) {
    return iframe.offsetWidth > 200 && iframe.offsetHeight > 200 && getComputedStyle(iframe).visibility !== 'hidden';
  }) || null;
}

function syncTawkChrome() {
  var panel = tawkPanel();
  var open = document.documentElement.classList.contains('slb-tawk-open') && panel;
  if (!open) {
    themeBar.style.display = 'none';
    return;
  }
  var box = panel.getBoundingClientRect();
  themeBar.style.display = 'flex';
  themeBar.style.left = box.left + 'px';
  themeBar.style.top = box.top + 'px';
  themeBar.style.width = box.width + 'px';
  themeBar.style.height = '52px';
}

function lockPage(open) {
  document.documentElement.classList.toggle('slb-tawk-open', !!open);
  launcher.classList.remove('is-hidden');
  launcher.setAttribute('aria-label', open ? 'Close chat' : 'Open chat');
  launcher.style.zIndex = open ? '1000003' : '1081';
  syncTawkChrome();
  if (open) {
    [50, 200, 500, 1000].forEach(function (ms) {
      window.setTimeout(syncTawkChrome, ms);
    });
  }
}

var hidingBubble = false;
function hideBubble() {
  if (hidingBubble) return;
  hidingBubble = true;
  try {
    document.querySelectorAll('iframe[title="chat widget"], iframe[title="Chat widget"]').forEach(function (iframe) {
      if (iframe.offsetWidth > 0 && iframe.offsetWidth < 160 && iframe.offsetHeight < 160 && iframe.style.visibility !== 'hidden') {
        iframe.style.setProperty('visibility', 'hidden', 'important');
        iframe.style.setProperty('pointer-events', 'none', 'important');
      }
    });
    if (!document.documentElement.classList.contains('slb-tawk-open') && window.Tawk_API && typeof window.Tawk_API.hideWidget === 'function') {
      window.Tawk_API.hideWidget();
    }
  } finally {
    hidingBubble = false;
  }
}
var themeBar = document.createElement('div');
themeBar.className = 'slb-tawk-theme-header';
themeBar.setAttribute('aria-hidden', 'true');
themeBar.innerHTML = '<span class="slb-tawk-theme-header__icon slb-tawk-theme-header__back" aria-hidden="true">'
  + '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
  + '<path d="M15 5L8 12l7 7" stroke="#ffffff" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>'
  + '</svg></span>'
  + '<span class="slb-tawk-theme-header__title"></span>'
  + '<span class="slb-tawk-theme-header__icon slb-tawk-theme-header__menu" aria-hidden="true">'
  + '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">'
  + '<circle cx="12" cy="6" r="1.7" fill="#ffffff"/>'
  + '<circle cx="12" cy="12" r="1.7" fill="#ffffff"/>'
  + '<circle cx="12" cy="18" r="1.7" fill="#ffffff"/>'
  + '</svg></span>';
themeBar.querySelector('.slb-tawk-theme-header__title').textContent = {!! json_encode(config('app.name', 'SEOLinkBuildings'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};

function mountLauncher() {
  if (!document.body) {
    document.addEventListener('DOMContentLoaded', mountLauncher);
    return;
  }
  if (!launcher.isConnected) document.body.appendChild(launcher);
  if (!themeBar.isConnected) document.body.appendChild(themeBar);
  paintMark();
  new MutationObserver(function () { hideBubble(); syncTawkChrome(); }).observe(document.body, { childList: true, subtree: true });
}
mountLauncher();

var unread = 0;
function paintUnread() {
  if (unread > 0 && !document.documentElement.classList.contains('slb-tawk-open')) {
    unreadBadge.textContent = unread > 9 ? '9+' : String(unread);
    unreadBadge.classList.add('is-on');
    unreadBadge.removeAttribute('hidden');
    unreadBadge.setAttribute('aria-hidden', 'false');
    launcher.setAttribute('aria-label', unread === 1 ? 'Open chat, 1 unread message' : 'Open chat, ' + unread + ' unread messages');
  } else {
    unreadBadge.textContent = '';
    unreadBadge.classList.remove('is-on');
    unreadBadge.setAttribute('hidden', '');
    unreadBadge.setAttribute('aria-hidden', 'true');
    if (!document.documentElement.classList.contains('slb-tawk-open')) {
      launcher.setAttribute('aria-label', 'Open chat');
    }
  }
}

function closeChat() {
  window.slbTawkKeepOpen = false;
  lockPage(false);
  if (window.Tawk_API && typeof window.Tawk_API.minimize === 'function') {
    window.Tawk_API.minimize();
  }
  hideBubble();
  paintUnread();
}

function openChat() {
  if (!(window.Tawk_API && typeof window.Tawk_API.maximize === 'function')) return false;
  window.slbTawkKeepOpen = true;
  unread = 0;
  paintUnread();
  if (typeof window.Tawk_API.showWidget === 'function') window.Tawk_API.showWidget();
  window.Tawk_API.maximize();
  lockPage(true);
  return true;
}

launcher.addEventListener('click', function (event) {
  event.preventDefault();
  event.stopPropagation();
  if (document.documentElement.classList.contains('slb-tawk-open')) {
    closeChat();
    return;
  }
  if (typeof window.slbOpenSupport === 'function') {
    window.slbOpenSupport();
    return;
  }
  openChat();
});

document.addEventListener('pointerdown', function (event) {
  if (!document.documentElement.classList.contains('slb-tawk-open')) return;
  if (launcher.contains(event.target)) return;
  if (event.target && event.target.closest && event.target.closest('iframe[src*="tawk.to"], iframe[title="chat widget"], iframe[title="Chat widget"]')) return;
  closeChat();
});

function applyVisitorContext() {
  if (!window.Tawk_API) return;
  if (tawkAttributes && Object.keys(tawkAttributes).length && typeof Tawk_API.setAttributes === 'function') {
    Tawk_API.setAttributes(tawkAttributes, function () {});
  }
  if (tawkTags && tawkTags.length && typeof Tawk_API.addTags === 'function') {
    Tawk_API.addTags(tawkTags, function () {});
  }
}

Tawk_API.onLoad = function () {
  applyVisitorContext();
  if (window.slbTawkKeepOpen) return;
  if (typeof Tawk_API.minimize === 'function') Tawk_API.minimize();
  hideBubble();
  lockPage(false);
};
Tawk_API.onChatMinimized = function () {
  window.slbTawkKeepOpen = false;
  hideBubble();
  lockPage(false);
  paintUnread();
};
Tawk_API.onChatMaximized = function () {
  unread = 0;
  paintUnread();
  lockPage(true);
};
window.addEventListener('resize', syncTawkChrome);
Tawk_API.onChatMessageAgent = function () {
  if (document.documentElement.classList.contains('slb-tawk-open')) return;
  unread += 1;
  paintUnread();
};

window.slbOpenSupport = function () {
  window.slbTawkKeepOpen = true;
  if (typeof window.slbStartTawkEmbed === 'function') window.slbStartTawkEmbed();
  if (openChat()) return;
  var tries = 0;
  var timer = setInterval(function () {
    tries += 1;
    if (openChat() || tries > 25) {
      clearInterval(timer);
      if (!(window.Tawk_API && window.Tawk_API.isChatMaximized && window.Tawk_API.isChatMaximized())) {
        window.slbTawkKeepOpen = false;
      }
    }
  }, 200);
};
})();
(function(){
var tawkSrc={!! json_encode($tawkSrc, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
var started=false;
function startTawkEmbed() {
  if (started) return;
  started=true;
  var s1=document.createElement('script'),s0=document.getElementsByTagName('script')[0];
  s1.async=true;
  s1.src=tawkSrc;
  s1.charset='UTF-8';
  s1.setAttribute('crossorigin','*');
  if (s0 && s0.parentNode) {
    s0.parentNode.insertBefore(s1, s0);
  } else {
    (document.head || document.body).appendChild(s1);
  }
}
window.slbStartTawkEmbed = startTawkEmbed;
})();
</script>
@else
<script>
window.slbOpenSupport = function () {
  var toggle = document.getElementById('helpFeedbackToggle');
  if (toggle) toggle.click();
};
</script>
@endif
