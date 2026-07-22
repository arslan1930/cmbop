<section class="slb-bottom-cta py-5 text-center">
    <div class="container">
        <h3 class="slb-bottom-cta-title">{{ __('messages.cta_title') }}</h3>
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4 slb-bottom-cta-btn">{{ __('messages.cta_button') }}</a>
        <p class="small mb-0 mt-3 text-muted" style="max-width: 36rem; margin-left: auto; margin-right: auto;">
            {{ __('messages.cta_guarantee') }}
            <a href="{{ localized_url('refund-policy') }}" class="fw-semibold text-decoration-underline" style="color: var(--brand-primary, #185054);">{{ __('messages.refund_policy') }}</a>
        </p>
    </div>
</section>
