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

function paintMark() {
  if (!window.lottie || launcher.dataset.ready === '1') return;
  launcher.dataset.ready = '1';
  var anim = window.lottie.loadAnimation({
    container: mark,
    renderer: 'svg',
    loop: false,
    autoplay: false,
    path: {!! json_encode(asset('assets/vendor/lottie/chat-box.json').'?v='.(@filemtime(public_path('assets/vendor/lottie/chat-box.json')) ?: '1'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
  });
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
}
if (document.readyState === 'complete') paintMark();
window.addEventListener('load', paintMark);
var paintTries = 0;
var paintTimer = setInterval(function () {
  paintTries += 1;
  if (window.lottie || paintTries > 100) {
    clearInterval(paintTimer);
    paintMark();
  }
}, 50);

function lockPage(open) {
  document.documentElement.classList.toggle('slb-tawk-open', !!open);
  launcher.classList.remove('is-hidden');
  launcher.setAttribute('aria-label', open ? 'Close chat' : 'Open chat');
  launcher.style.zIndex = open ? '1000003' : '1081';
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
function mountLauncher() {
  if (!document.body) {
    document.addEventListener('DOMContentLoaded', mountLauncher);
    return;
  }
  if (!launcher.isConnected) document.body.appendChild(launcher);
  new MutationObserver(function () { hideBubble(); }).observe(document.body, { childList: true, subtree: true });
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
  if (document.documentElement.classList.contains('slb-tawk-open')) closeChat();
  else openChat();
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
Tawk_API.onChatMessageAgent = function () {
  if (document.documentElement.classList.contains('slb-tawk-open')) return;
  unread += 1;
  paintUnread();
};

window.slbOpenSupport = function () {
  window.slbTawkKeepOpen = true;
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
var s1=document.createElement('script'),s0=document.getElementsByTagName('script')[0];
s1.async=true;
s1.src={!! json_encode($tawkSrc, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
if (s0 && s0.parentNode) {
  s0.parentNode.insertBefore(s1, s0);
} else {
  (document.head || document.body).appendChild(s1);
}
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
