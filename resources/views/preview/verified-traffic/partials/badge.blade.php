@php
    $via = $via ?? 'GA4';
    $showVia = $showVia ?? true;
@endphp
<span class="vt-badge"
      title="Monthly visits confirmed from this publisher’s Google account. SEOLinkBuildings does not show page-level reports to advertisers.">
    <svg class="vt-badge__mark" viewBox="0 0 16 16" aria-hidden="true">
        <circle cx="8" cy="8" r="8" fill="#0f766e"/>
        <path d="M4.6 8.2 6.7 10.3 11.4 5.6" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    Verified traffic
    @if($showVia)
        <span class="vt-badge__via">{{ $via === 'gsc' ? 'via GSC' : 'via GA4' }}</span>
    @endif
</span>
