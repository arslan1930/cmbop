{{-- Visitor chat (public + advertiser/publisher). Order chat is separate. --}}
@php
    $useFirstParty = class_exists(\App\Support\VisitorSupportChat::class)
        && method_exists(\App\Support\VisitorSupportChat::class, 'enabled')
        && \App\Support\VisitorSupportChat::enabled()
        && view()->exists('partials.visitor-support-chat');
    $tawkSrc = null;
    $tawkVisitor = null;
    if (class_exists(\App\Support\TawkChat::class)
        && method_exists(\App\Support\TawkChat::class, 'embedSrc')) {
        $tawkSrc = \App\Support\TawkChat::embedSrc();
        if ($tawkSrc && auth()->check()) {
            $user = auth()->user();
            $name = trim((string) ($user->name ?? ''));
            $email = trim((string) ($user->email ?? ''));
            if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $tawkVisitor = ['name' => $name, 'email' => $email];
            }
        }
    }
@endphp
@if ($tawkSrc)
<script src="{{ asset('assets/vendor/lottie-web/lottie_light.min.js') }}?v={{ @filemtime(public_path('assets/vendor/lottie-web/lottie_light.min.js')) ?: '1' }}" defer></script>
<script>
(function () {
  if (!window.fetch || window.fetch.__slbTheme) return;
  var nativeFetch = window.fetch.bind(window);
  function paintTheme(data) {
    var theme = data && data.data && data.data.widget && data.data.widget.theme;
    if (!theme) return;
    theme.header = theme.header || {};
    theme.header.background = '#1a585e';
    theme.header.text = '#ffffff';
    theme.agent = theme.agent || {};
    theme.agent.messageBackground = '#1a585e';
    theme.agent.messageText = '#ffffff';
  }
  var wrapped = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var pending = nativeFetch(input, init);
    if (url.indexOf('va.tawk.to/v1/widget-settings') === -1) return pending;
    return pending.then(function (response) {
      return response.clone().json().then(function (data) {
        paintTheme(data);
        var headers = new Headers(response.headers);
        headers.delete('content-length');
        return new Response(JSON.stringify(data), {
          status: response.status,
          statusText: response.statusText,
          headers: headers
        });
      }).catch(function () { return response; });
    });
  };
  wrapped.__slbTheme = true;
  window.fetch = wrapped;
})();
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
Tawk_API.customStyle = {
  zIndex: 1080,
  visibility: {
    desktop: { position: 'br', xOffset: 16, yOffset: 20 },
    mobile: { position: 'br', xOffset: 10, yOffset: 16 }
  }
};
@if ($tawkVisitor)
Tawk_API.visitor = {!! json_encode($tawkVisitor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
@endif
(function () {
  var launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'slb-chat-mark';
  launcher.setAttribute('aria-label', 'Open chat');
  launcher.style.cssText = 'position:fixed;right:16px;bottom:20px;z-index:1081;width:84px;height:84px;padding:0;border:0;background:transparent;cursor:pointer;line-height:0;overflow:hidden';
  document.body.appendChild(launcher);

  function paintMark() {
    if (!window.lottie || launcher.dataset.ready === '1') return;
    launcher.dataset.ready = '1';
    var anim = window.lottie.loadAnimation({
      container: launcher,
      renderer: 'svg',
      loop: false,
      autoplay: false,
      path: @json(asset('assets/vendor/lottie/chat-box.json').'?v='.(@filemtime(public_path('assets/vendor/lottie/chat-box.json')) ?: '1'))
    });
    anim.addEventListener('DOMLoaded', function () {
      anim.goToAndStop(40, true);
      var svg = launcher.querySelector('svg');
      if (svg) {
        svg.style.width = '84px';
        svg.style.height = '84px';
      }
    });
  }
  if (document.readyState === 'complete') paintMark();
  window.addEventListener('load', paintMark);
  var paintTimer = setInterval(function () {
    if (window.lottie) { clearInterval(paintTimer); paintMark(); }
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
  new MutationObserver(function () { hideBubble(); }).observe(document.body, { childList: true, subtree: true });

  function closeChat() {
    window.slbTawkKeepOpen = false;
    lockPage(false);
    if (window.Tawk_API && typeof window.Tawk_API.minimize === 'function') {
      window.Tawk_API.minimize();
    }
    hideBubble();
  }

  function openChat() {
    if (!(window.Tawk_API && typeof window.Tawk_API.maximize === 'function')) return false;
    window.slbTawkKeepOpen = true;
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

  Tawk_API.onLoad = function () {
    if (window.slbTawkKeepOpen) return;
    if (typeof Tawk_API.minimize === 'function') Tawk_API.minimize();
    hideBubble();
    lockPage(false);
  };
  Tawk_API.onChatMinimized = function () {
    window.slbTawkKeepOpen = false;
    hideBubble();
    lockPage(false);
  };
  Tawk_API.onChatMaximized = function () {
    lockPage(true);
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
s1.src={!! json_encode($tawkSrc, JSON_UNESCAPED_SLASHES) !!};
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
@elseif ($useFirstParty)
    @include('partials.visitor-support-chat')
@else
<script>
window.slbOpenSupport = function () {
  var toggle = document.getElementById('helpFeedbackToggle');
  if (toggle) toggle.click();
};
</script>
@endif
