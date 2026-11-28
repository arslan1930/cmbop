@php
    $vtSite = $site ?? null;
    $showVerifiedTraffic = \App\Support\VerifiedTraffic::showsBadge($vtSite instanceof \App\Models\Site ? $vtSite : null);
@endphp
@if($showVerifiedTraffic)
    <span class="vt-badge"
          title="{{ __('messages.verified_traffic_tooltip') }}">
        <svg class="vt-badge__mark" viewBox="0 0 16 16" aria-hidden="true">
            <circle cx="8" cy="8" r="8" fill="#0f766e"/>
            <path d="M4.6 8.2 6.7 10.3 11.4 5.6" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        {{ __('messages.verified_traffic_badge') }}
    </span>
@endif
