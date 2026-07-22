<section class="py-5 text-center text-white" style="background-color: #4ECDCB;">
    <div class="container">
        <h3>{{ __('messages.cta_title') }}</h3>
        <a href="{{ url('/register') }}" class="btn" style="background-color: white; color: #4ECDCB; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; transition: all 0.3s ease;" onmouseover="this.style.backgroundColor='#e6f5f5';" onmouseout="this.style.backgroundColor='white';">{{ __('messages.cta_button') }}</a>
        <p class="small mb-0 mt-3" style="opacity: 0.95; max-width: 36rem; margin-left: auto; margin-right: auto;">
            {{ __('messages.cta_guarantee') }}
            <a href="{{ localized_url('refund-policy') }}" class="text-white fw-semibold text-decoration-underline">{{ __('messages.refund_policy') }}</a>
        </p>
    </div>
</section>
