@php
    $profiles = $profiles ?? config('social.profiles', []);
@endphp
<div class="slb-social-icons {{ $class ?? '' }}">
    @foreach($profiles as $profile)
        @continue(empty($profile['url']))
        <a href="{{ $profile['url'] }}"
           target="_blank"
           rel="noopener noreferrer"
           class="slb-social-icons__link text-dark text-decoration-none"
           aria-label="SEOLinkBuildings on {{ $profile['label'] }}">
            <i class="{{ $profile['icon'] }} fa-lg" aria-hidden="true"></i>
        </a>
    @endforeach
</div>
