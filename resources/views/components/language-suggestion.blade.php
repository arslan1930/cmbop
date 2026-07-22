@php
  use App\Support\PublicI18n;

  $show = false;
  $suggested = null;
  $suggestedName = '';
  $switchUrl = '#';

  if (show_public_language_switcher()) {
      $suggested = PublicI18n::preferredFromBrowser(request());
      $current = public_locale();
      $dismissed = request()->cookie(config('i18n.suggestion_dismiss_cookie', 'locale_suggest_dismissed'));

      if (
          $suggested
          && $suggested !== $current
          && $dismissed !== '1'
          && ! request()->cookie(config('i18n.cookie', 'public_locale'))
      ) {
          $show = true;
          $suggestedName = get_available_locales()[$suggested]['name'] ?? strtoupper($suggested);
          $switchUrl = get_language_switcher_url($suggested);
      }
  }
@endphp

@if($show)
<div id="localeSuggestBanner" class="locale-suggest-banner" role="region" aria-label="{{ __('messages.language_suggestion_aria') }}">
  <div class="container d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 py-2">
    <p class="mb-0 small">
      {{ __('messages.language_suggestion', ['language' => $suggestedName]) }}
    </p>
    <div class="d-flex gap-2">
      <a href="{{ $switchUrl }}" class="btn btn-sm btn-primary" hreflang="{{ $suggested }}">
        {{ __('messages.language_suggestion_switch', ['language' => $suggestedName]) }}
      </a>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="localeSuggestDismiss">
        {{ __('messages.language_suggestion_dismiss') }}
      </button>
    </div>
  </div>
</div>

<style>
  .locale-suggest-banner {
    position: sticky;
    top: var(--public-navbar-height, 88px);
    z-index: 1020;
    background: #e6f5f5;
    border-bottom: 1px solid rgba(24, 80, 84, 0.15);
    color: #0f172a;
  }
</style>

<script>
  (function () {
    const dismissBtn = document.getElementById('localeSuggestDismiss');
    const banner = document.getElementById('localeSuggestBanner');
    if (!dismissBtn || !banner) return;
    dismissBtn.addEventListener('click', function () {
      document.cookie = '{{ config('i18n.suggestion_dismiss_cookie', 'locale_suggest_dismissed') }}=1; path=/; max-age={{ 60 * 60 * 24 * 30 }}; SameSite=Lax';
      banner.remove();
    });
  })();
</script>
@endif
