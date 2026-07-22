@php
    $fromPrice = '50';
    try {
        $minPublisherPrice = \App\Models\Site::query()
            ->where('active', 1)
            ->where(function ($q) {
                $q->where('verified', 1)->orWhere('verified', true);
            })
            ->min('price');
        $fromPrice = $minPublisherPrice
            ? number_format(app(\App\Services\PlatformFeeService::class)->advertiserBase((float) $minPublisherPrice), 0)
            : '50';
    } catch (\Throwable $e) {
        $fromPrice = '50';
    }

    $t = function (string $key, string $fallback): string {
        return trans()->has('messages.'.$key) ? (string) __('messages.'.$key) : $fallback;
    };

    $card1Title = $t('pricing_card_1_title', 'Starter Package');
    $card2Title = $t('pricing_card_2_title', 'Growth Package');
    $card3Title = $t('pricing_card_3_title', 'Authority Package');
    $contactUrl = localized_url('contact');
@endphp

<section class="slb-section slb-pricing">
  <div class="container" style="max-width:1100px;">
    <div class="text-center mb-5">
      <div class="slb-section-kicker">{{ $t('pricing_kicker', 'Pricing') }}</div>
      <h2 class="slb-section-title">{{ $t('pricing_hero_title', 'Buy placements that match your market') }}</h2>
      <p class="slb-section-lead mb-2">{{ $t('pricing_hero_lead', 'Browse verified publisher sites, pick a price that fits, and checkout from your wallet.') }}</p>
      <p class="text-muted mb-4">{{ $t('pricing_hero_from', 'Marketplace placements start from') }} <strong class="slb-price-from">€{{ $fromPrice }}</strong>.</p>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ url('/register') }}" class="btn btn-primary btn-lg px-4">
          {{ $t('pricing_cta_create', 'Create free account') }}
        </a>
        <a href="{{ url('/login') }}" class="btn btn-cta-secondary btn-lg px-4">
          {{ $t('pricing_cta_browse', 'Browse after login') }}
        </a>
      </div>
      <p class="small text-muted mt-3 mb-0">{{ $t('pricing_bonus_note', 'New advertisers get €20 free credit for first orders (not withdrawable).') }}</p>
    </div>

    <div class="pricing-managed mx-auto mb-4">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div>
          <h3 class="h5 mb-1">{{ $t('pricing_managed_title', 'Prefer a managed Digital PR package?') }}</h3>
          <p class="text-muted small mb-0">{{ $t('pricing_managed_body', 'Our team can run outreach campaigns if you want hands-off delivery.') }}</p>
        </div>
        <a href="{{ $contactUrl }}" class="btn btn-outline-secondary">{{ $t('pricing_talk_sales', 'Talk to sales') }}</a>
      </div>
    </div>

    <div class="row g-4 justify-content-center pricing-packages">
      <div class="col-md-4">
        <div class="pricing-card h-100">
          <div class="pricing-card-body">
            <h5 class="mb-2">{{ $card1Title }}</h5>
            <p class="text-muted small mb-3">{{ $t('pricing_card_1_description', 'Ideal for small businesses and startups beginning their Digital PR journey.') }}</p>
            <div class="pricing-card-price mb-3">€499<span>/mo</span></div>
            <ul class="list-unstyled small mb-4 flex-grow-1">
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_1_item_1', '2+ editorial backlinks from relevant industry publications') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_1_item_2', 'Average Domain Rating (DR) 30–40') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_1_item_3', 'Websites with verified organic traffic and real audiences') }}</li>
            </ul>
            <a href="{{ $contactUrl }}" class="btn btn-cta-secondary w-100">{{ $t('pricing_talk_sales', 'Talk to sales') }}</a>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="pricing-card pricing-card--featured h-100">
          <div class="pricing-card-badge">{{ $t('pricing_most_popular', 'Most popular') }}</div>
          <div class="pricing-card-body">
            <h5 class="mb-2">{{ $card2Title }}</h5>
            <p class="text-muted small mb-3">{{ $t('pricing_card_2_description', 'Designed for growing brands seeking consistent expert-led media placements.') }}</p>
            <div class="pricing-card-price mb-3">€1,499<span>/mo</span></div>
            <ul class="list-unstyled small mb-4 flex-grow-1">
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_2_item_1', '5+ editorial backlinks from relevant industry publications') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_2_item_2', 'Average Domain Rating (DR) 40–50') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_2_item_3', 'Websites with verified organic traffic and real audiences') }}</li>
            </ul>
            <a href="{{ $contactUrl }}" class="btn btn-primary w-100">{{ $t('pricing_talk_sales', 'Talk to sales') }}</a>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="pricing-card h-100">
          <div class="pricing-card-body">
            <h5 class="mb-2">{{ $card3Title }}</h5>
            <p class="text-muted small mb-3">{{ $t('pricing_card_3_description', 'Perfect for established businesses aiming to solidify their authority.') }}</p>
            <div class="pricing-card-price mb-3">€2,799<span>/mo</span></div>
            <ul class="list-unstyled small mb-4 flex-grow-1">
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_3_item_1', '10+ editorial backlinks from relevant industry publications') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_3_item_2', 'Average Domain Rating (DR) 50+') }}</li>
              <li class="mb-2"><i class="fa-solid fa-check text-primary me-2" aria-hidden="true"></i>{{ $t('pricing_card_3_item_3', 'Websites with verified organic traffic and real audiences') }}</li>
            </ul>
            <a href="{{ $contactUrl }}" class="btn btn-cta-secondary w-100">{{ $t('pricing_talk_sales', 'Talk to sales') }}</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
