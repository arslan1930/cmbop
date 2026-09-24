@php
  $blogIndexUrl = localized_url('blog');
@endphp

<footer class="bg-light text-dark pt-5 pb-4 slb-footer">
    <div class="container">
        <div class="row gy-4">

            <div class="col-lg-3 col-md-6">
                <a href="{{ localized_url('/') }}" class="slb-footer-brand d-inline-block">
                    <img src="{{ asset('assets/img/logo1.png') }}?v={{ @filemtime(public_path('assets/img/logo1.png')) ?: '1' }}"
                         alt="SEOLinkBuildings"
                         width="1006"
                         height="280"
                         loading="lazy"
                         decoding="async">
                </a>
                <p class="mt-3 small">
                    {{ __('messages.professional_services') }}
                </p>
                <div class="mt-3">
                    @include('components.social-icons')
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <h5 class="mb-3">{{ __('messages.company') }}</h5>
                <ul class="list-unstyled small">
                    <li><a href="{{ localized_url('about') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_about') }}</a></li>
                    <li><a href="{{ localized_url('marketplace') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_marketplace') }}</a></li>
                    <li><a href="{{ localized_url('pricing') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_pricing') }}</a></li>
                    <li><a href="{{ localized_url('how-it-works') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_how_it_works') }}</a></li>
                    <li><a href="{{ localized_url('why-choose-us') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.why_choose_title') }}</a></li>
                    <li><a href="{{ localized_url('become-a-publisher') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_become_publisher') }}</a></li>
                    <li><a href="{{ localized_url('faq') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_faq') }}</a></li>
                    <li><a href="{{ localized_url('contact') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.contact') }}</a></li>
                    <li><a href="{{ $blogIndexUrl }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.blog') }}</a></li>
                    @if(function_exists('public_locale') && public_locale() === 'it')
                        <li><a href="{{ url('/it/comprare-guest-post') }}" class="text-dark text-decoration-none d-block mb-2">Acquistare guest post</a></li>
                        <li><a href="{{ url('/it/articoli-sponsorizzati') }}" class="text-dark text-decoration-none d-block mb-2">Articoli sponsorizzati</a></li>
                        <li><a href="{{ url('/it/comprare-backlink') }}" class="text-dark text-decoration-none d-block mb-2">Acquistare backlink</a></li>
                        <li><a href="{{ url('/it/link-building') }}" class="text-dark text-decoration-none d-block mb-2">Link building</a></li>
                        <li><a href="{{ url('/it/agenzie') }}" class="text-dark text-decoration-none d-block mb-2">Per agenzie</a></li>
                        <li><a href="{{ url('/it/digital-pr') }}" class="text-dark text-decoration-none d-block mb-2">Digital PR</a></li>
                    @endif
                    @if(function_exists('public_locale') && public_locale() === 'de')
                        <li><a href="{{ url('/de/gastbeitrag-kaufen') }}" class="text-dark text-decoration-none d-block mb-2">Gastbeitrag kaufen</a></li>
                        <li><a href="{{ url('/de/advertorial') }}" class="text-dark text-decoration-none d-block mb-2">Advertorials</a></li>
                        <li><a href="{{ url('/de/backlinks-kaufen') }}" class="text-dark text-decoration-none d-block mb-2">Backlinks kaufen</a></li>
                        <li><a href="{{ url('/de/linkbuilding') }}" class="text-dark text-decoration-none d-block mb-2">Linkbuilding</a></li>
                        <li><a href="{{ url('/de/agenturen') }}" class="text-dark text-decoration-none d-block mb-2">Für Agenturen</a></li>
                        <li><a href="{{ url('/de/digital-pr') }}" class="text-dark text-decoration-none d-block mb-2">Digital PR</a></li>
                    @endif
                    @if(function_exists('public_locale') && public_locale() === 'at')
                        <li><a href="{{ url('/at/gastbeitrag-kaufen') }}" class="text-dark text-decoration-none d-block mb-2">Gastbeitrag kaufen</a></li>
                        <li><a href="{{ url('/at/advertorial') }}" class="text-dark text-decoration-none d-block mb-2">Advertorials</a></li>
                        <li><a href="{{ url('/at/backlinks-kaufen') }}" class="text-dark text-decoration-none d-block mb-2">Backlinks kaufen</a></li>
                        <li><a href="{{ url('/at/linkbuilding') }}" class="text-dark text-decoration-none d-block mb-2">Linkbuilding</a></li>
                        <li><a href="{{ url('/at/agenturen') }}" class="text-dark text-decoration-none d-block mb-2">Für Agenturen</a></li>
                        <li><a href="{{ url('/at/digital-pr') }}" class="text-dark text-decoration-none d-block mb-2">Digital PR</a></li>
                    @endif
                    @if(function_exists('public_locale') && public_locale() === 'ch')
                        <li><a href="{{ url('/ch/gastbeitrag-kaufen') }}" class="text-dark text-decoration-none d-block mb-2">Gastbeitrag kaufen</a></li>
                        <li><a href="{{ url('/ch/advertorial') }}" class="text-dark text-decoration-none d-block mb-2">Advertorials</a></li>
                        <li><a href="{{ url('/ch/backlinks-kaufen') }}" class="text-dark text-decoration-none d-block mb-2">Backlinks kaufen</a></li>
                        <li><a href="{{ url('/ch/linkbuilding') }}" class="text-dark text-decoration-none d-block mb-2">Linkbuilding</a></li>
                        <li><a href="{{ url('/ch/agenturen') }}" class="text-dark text-decoration-none d-block mb-2">Für Agenturen</a></li>
                        <li><a href="{{ url('/ch/digital-pr') }}" class="text-dark text-decoration-none d-block mb-2">Digital PR</a></li>
                    @endif
                    @if(function_exists('public_locale') && public_locale() === 'es')
                        <li><a href="{{ url('/es/comprar-guest-post') }}" class="text-dark text-decoration-none d-block mb-2">Comprar guest post</a></li>
                        <li><a href="{{ url('/es/articulo-patrocinado') }}" class="text-dark text-decoration-none d-block mb-2">Artículo patrocinado</a></li>
                        <li><a href="{{ url('/es/comprar-backlinks') }}" class="text-dark text-decoration-none d-block mb-2">Comprar backlinks</a></li>
                        <li><a href="{{ url('/es/link-building') }}" class="text-dark text-decoration-none d-block mb-2">Link building</a></li>
                        <li><a href="{{ url('/es/agencias') }}" class="text-dark text-decoration-none d-block mb-2">Para agencias</a></li>
                        <li><a href="{{ url('/es/digital-pr') }}" class="text-dark text-decoration-none d-block mb-2">PR digital</a></li>
                    @endif
                    @if(function_exists('public_locale') && public_locale() === 'ro')
                        <li><a href="{{ url('/ro/cumpara-guest-post') }}" class="text-dark text-decoration-none d-block mb-2">Cumpără guest post</a></li>
                        <li><a href="{{ url('/ro/articol-sponsorizat') }}" class="text-dark text-decoration-none d-block mb-2">Articol sponsorizat</a></li>
                        <li><a href="{{ url('/ro/cumpara-backlink') }}" class="text-dark text-decoration-none d-block mb-2">Cumpără backlinkuri</a></li>
                        <li><a href="{{ url('/ro/link-building') }}" class="text-dark text-decoration-none d-block mb-2">Link building</a></li>
                        <li><a href="{{ url('/ro/agentii') }}" class="text-dark text-decoration-none d-block mb-2">Pentru agenții</a></li>
                        <li><a href="{{ url('/ro/digital-pr') }}" class="text-dark text-decoration-none d-block mb-2">Digital PR</a></li>
                        <li><a href="{{ url('/ro/ghid') }}" class="text-dark text-decoration-none d-block mb-2">Ghid</a></li>
                    @endif
                </ul>
            </div>

            <div class="col-lg-2 col-md-6">
                <h5 class="mb-3">{{ __('messages.legal') }}</h5>
                <ul class="list-unstyled small">
                    <li><a href="{{ localized_url('privacy-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.privacy_policy') }}</a></li>
                    <li><a href="{{ localized_url('terms-of-services') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.terms_of_service') }}</a></li>
                    <li><a href="{{ localized_url('cookie-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.cookie_policy') }}</a></li>
                    <li><a href="{{ localized_url('refund-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.refund_policy') }}</a></li>
                    <li><button type="button" class="btn btn-link text-dark text-decoration-none d-block mb-2 p-0 small" onclick="window.slbOpenSupport && window.slbOpenSupport()">{{ __('messages.report_problem') }}</button></li>
                    <li><button type="button" class="btn btn-link text-dark text-decoration-none d-block mb-2 p-0 small" onclick="window.slbOpenSupport && window.slbOpenSupport()">{{ __('messages.suggestion_box') }}</button></li>
                </ul>
            </div>

            <div class="col-lg-3 col-md-6">
                <h5 class="mb-3">{{ __('messages.latest_updates') }}</h5>
                <ul class="list-unstyled small mb-2">
                    @forelse(($footerRecentBlogs ?? collect()) as $post)
                        <li class="mb-3">
                            <a href="{{ localized_url('blog/'.$post->slug) }}" class="text-dark text-decoration-none d-block fw-semibold">
                                {{ \Illuminate\Support\Str::limit($post->title, 64) }}
                            </a>
                            <span class="text-muted" style="font-size: 0.78rem;">
                                {{ optional($post->published_at)->format('M j, Y') ?? $post->created_at->format('M j, Y') }}
                            </span>
                        </li>
                    @empty
                        <li class="text-muted mb-2">{{ __('messages.blog_empty_footer') }}</li>
                    @endforelse
                </ul>
                <a href="{{ $blogIndexUrl }}" class="small fw-semibold text-decoration-none" style="color:#1a585e;">
                    {{ __('messages.view_all_posts') }} →
                </a>
            </div>

            <div class="col-lg-2 col-md-6">
                <h5 class="mb-3">{{ __('messages.address') }}</h5>
                <p class="small mb-0">{{ __('messages.address_description') }}</p>
                <p class="small mt-2 mb-0">{{ __('messages.registered_address') }}</p>
                <p class="small mt-2">{{ __('messages.company_number') }}: {{ config('billing.company.registration_no', '16607074') }}</p>
            </div>

        </div>

        <hr class="my-4">

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <p class="small mb-0">
                    &copy; {{ date('Y') }} SEOLinkBuildings. {{ __('messages.all_rights_reserved') }}
                </p>
                @include('partials.trustpilot-trust', ['compact' => true])
            </div>
            @include('partials.payment-trust', ['compact' => true])
        </div>
    </div>
</footer>
