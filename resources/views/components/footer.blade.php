@php
  $blogIndexUrl = localized_url('blog');
@endphp

<footer class="bg-light text-dark pt-5 pb-4">
    <div class="container">
        <div class="row gy-4">

            <div class="col-lg-3 col-md-6">
                <a href="{{ localized_url('/') }}">
                    <img src="{{ asset('assets/img/logo1.png') }}"
                         alt="SEOLinkBuildings"
                         style="max-width: 200px;">
                </a>
                <p class="mt-3 small">
                    {{ __('messages.professional_services') }}
                </p>
                <div class="mt-3">
                    <a href="https://www.linkedin.com/company/seolinkbuildings" target="_blank" rel="noopener" class="text-dark me-3">
                        <i class="fab fa-linkedin fa-lg"></i>
                    </a>
                </div>
            </div>

            <div class="col-lg-2 col-md-6">
                <h5 class="mb-3">{{ __('messages.company') }}</h5>
                <ul class="list-unstyled small">
                    <li><a href="{{ localized_url('about') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_about') }}</a></li>
                    <li><a href="{{ localized_url('marketplace') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_marketplace') }}</a></li>
                    <li><a href="{{ localized_url('pricing') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_pricing') }}</a></li>
                    <li><a href="{{ localized_url('how-it-works') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_how_it_works') }}</a></li>
                    <li><a href="{{ localized_url('become-a-publisher') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_become_publisher') }}</a></li>
                    <li><a href="{{ localized_url('faq') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.nav_faq') }}</a></li>
                    <li><a href="{{ localized_url('contact') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.contact') }}</a></li>
                    <li><a href="{{ $blogIndexUrl }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.blog') }}</a></li>
                </ul>
            </div>

            <div class="col-lg-2 col-md-6">
                <h5 class="mb-3">{{ __('messages.legal') }}</h5>
                <ul class="list-unstyled small">
                    <li><a href="{{ localized_url('privacy-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.privacy_policy') }}</a></li>
                    <li><a href="{{ localized_url('terms-of-services') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.terms_of_service') }}</a></li>
                    <li><a href="{{ localized_url('cookie-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.cookie_policy') }}</a></li>
                    <li><a href="{{ localized_url('refund-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.refund_policy') }}</a></li>
                    <li><button type="button" class="btn btn-link text-dark text-decoration-none d-block mb-2 p-0 small" onclick="document.getElementById('helpFeedbackToggle')?.click()">{{ __('messages.report_problem') }}</button></li>
                    <li><button type="button" class="btn btn-link text-dark text-decoration-none d-block mb-2 p-0 small" onclick="document.getElementById('helpFeedbackToggle')?.click()">{{ __('messages.suggestion_box') }}</button></li>
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
                <a href="{{ $blogIndexUrl }}" class="small fw-semibold text-decoration-none" style="color:#0b6266;">
                    {{ __('messages.view_all_posts') }} →
                </a>
            </div>

            <div class="col-lg-2 col-md-6">
                <h5 class="mb-3">{{ __('messages.address') }}</h5>
                <p class="small mb-0">{{ __('messages.address_description') }}</p>
                <p class="small mt-2 mb-0">{{ __('messages.registered_address') }}</p>
                <p class="small mt-2">{{ __('messages.company_number') }}: 16607074</p>
            </div>

        </div>

        <hr class="my-4">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
            <p class="small mb-3 mb-md-0">
                &copy; {{ date('Y') }} SEOLinkBuildings. {{ __('messages.all_rights_reserved') }}
            </p>
            <div class="d-flex align-items-center gap-3">
                <img src="{{ asset('assets/img/pay-pal-logo.png') }}" height="24" alt="PayPal">
                <img src="{{ asset('assets/img/wise.png') }}" height="30" alt="Wise">
                <img src="{{ asset('assets/img/bank.png') }}" height="24" alt="Bank">
                <img src="{{ asset('assets/img/crypto_currency.png') }}" height="32" alt="Crypto">
            </div>
        </div>
    </div>
</footer>
