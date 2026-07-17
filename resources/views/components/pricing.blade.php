@php
    $minPublisherPrice = \App\Models\Site::query()
        ->where('active', 1)
        ->where(function ($q) {
            $q->where('verified', 1)->orWhere('verified', true);
        })
        ->min('price');
    $fromPrice = $minPublisherPrice
        ? number_format(round((float) $minPublisherPrice * \App\Services\CartPricingService::PLATFORM_MARKUP_RATE, 2), 0)
        : '50';
@endphp

<section class="py-20 bg-light">
  <div class="container py-5">
    <div class="text-center mb-5">
      <h2 class="h2 mb-3">{{ __('messages.pricing_hero_title') }}</h2>
      <p class="lead text-muted mb-2">{{ __('messages.pricing_hero_lead') }}</p>
      <p class="text-muted mb-4">{{ __('messages.pricing_hero_from') }} <strong style="color:#0b6266;">€{{ $fromPrice }}</strong>.</p>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">
          {{ __('messages.pricing_cta_create') }}
        </a>
        <a href="{{ url('/login') }}" class="btn btn-cta-secondary btn-lg px-4">
          {{ __('messages.pricing_cta_browse') }}
        </a>
      </div>
      <p class="small text-muted mt-3 mb-0">{{ __('messages.pricing_bonus_note') }}</p>
    </div>

    <div class="pricing-managed dash-panel mx-auto mb-4" style="max-width: 920px;">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
          <h3 class="h5 mb-1">{{ __('messages.pricing_managed_title') }}</h3>
          <p class="text-muted small mb-0">{{ __('messages.pricing_managed_body') }}</p>
        </div>
        <a href="{{ localized_url('contact') }}" class="btn btn-outline-secondary">{{ __('messages.pricing_talk_sales') }}</a>
      </div>
    </div>

    <div class="row g-4 justify-content-center pricing-packages">
      <div class="col-md-4">
        <div class="card h-100 border rounded-3 shadow-sm pricing-card">
          <div class="card-body d-flex flex-column text-start p-4">
            <h5 class="card-title mb-2">{{ __('messages.pricing_card_1_title') }}</h5>
            <p class="card-text text-muted small mb-3">{{ __('messages.pricing_card_1_description') }}</p>
            <div class="h4 mb-3">€499<span class="text-muted fs-6">/mo</span></div>
            <ul class="list-unstyled small mb-4 flex-grow-1">
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_1_item_1') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_1_item_2') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_1_item_3') }}</li>
            </ul>
            <a href="{{ url('/register') }}" class="btn btn-cta-secondary w-100">{{ __('messages.pricing_start_marketplace') }}</a>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100 border pricing-card position-relative" style="border-color:#4ECDCB; border-radius:1rem; box-shadow:0 0.5rem 1rem rgba(0,0,0,0.08);">
          <div class="position-absolute top-0 end-0 px-3 py-1 text-white" style="background-color:#0b6266; border-bottom-left-radius:0.5rem; border-top-right-radius:0.5rem;">{{ __('messages.pricing_most_popular') }}</div>
          <div class="card-body d-flex flex-column text-start p-4 mt-3">
            <h5 class="card-title mb-2">{{ __('messages.pricing_card_2_title') }}</h5>
            <p class="card-text text-muted small mb-3">{{ __('messages.pricing_card_2_description') }}</p>
            <div class="h4 mb-3">€1,499<span class="text-muted fs-6">/mo</span></div>
            <ul class="list-unstyled small mb-4 flex-grow-1">
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_2_item_1') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_2_item_2') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_2_item_3') }}</li>
            </ul>
            <a href="{{ url('/register') }}" class="btn btn-primary w-100">{{ __('messages.pricing_create_account') }}</a>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card h-100 border rounded-3 shadow-sm pricing-card">
          <div class="card-body d-flex flex-column text-start p-4">
            <h5 class="card-title mb-2">{{ __('messages.pricing_card_3_title') }}</h5>
            <p class="card-text text-muted small mb-3">{{ __('messages.pricing_card_3_description') }}</p>
            <div class="h4 mb-3">€2,799<span class="text-muted fs-6">/mo</span></div>
            <ul class="list-unstyled small mb-4 flex-grow-1">
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_3_item_1') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_3_item_2') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ __('messages.pricing_card_3_item_3') }}</li>
            </ul>
            <a href="{{ localized_url('contact') }}" class="btn btn-cta-secondary w-100">{{ __('messages.pricing_talk_sales') }}</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <style>
    .pricing-card {
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border-radius: 1rem;
    }
    .pricing-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 1rem 2rem rgba(0,0,0,0.12);
      z-index: 10;
    }
    .pricing-managed {
      padding: 1.25rem 1.5rem;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
    }
    @media (prefers-reduced-motion: reduce) {
      .pricing-card { transition: none; }
      .pricing-card:hover { transform: none; }
    }
  </style>
</section>
