<section class="py-20 bg-light">
  <div class="container py-5">
    <div class="text-center mb-16">
      <h2 class="h2 mb-3"><?= __('messages.pricing_title') ?></h2>
      <p class="lead text-muted"><?= __('messages.pricing_description') ?></p>
      <p class="text-muted small mb-0">Or skip packages and <a href="{{ route('register') }}" class="fw-semibold" style="color:#0b6266;">browse marketplace placements</a> after signup.</p>
    </div>

    <div class="row g-4 justify-content-center">

      <!-- Starter Package -->
      <div class="col-md-4">
        <div class="card h-100 border rounded-3 shadow-sm pricing-card" style="min-height: 520px;">
          <div class="card-body d-flex flex-column justify-content-between text-start p-5">
            <div>
              <h5 class="card-title mb-4"><?= __('messages.pricing_card_1_title') ?></h5>
              <p class="card-text mb-4"><?= __('messages.pricing_card_1_description') ?></p>
              <div class="h3 mb-4">€499<span class="text-muted fs-6">/month</span></div>
              <p class="fw-semibold mb-3"><?= __('messages.pricing_card_1_item_title') ?></p>
              <ul class="list-unstyled mb-4 ps-3">
                <li>✔ <?= __('messages.pricing_card_1_item_1') ?></li>
                <li>✔ <?= __('messages.pricing_card_1_item_2') ?></li>
                <li>✔ <?= __('messages.pricing_card_1_item_3') ?></li>
              </ul>
            </div>
            <a href="{{ route('register') }}" class="btn btn-cta-secondary w-100 mt-4 py-2"><?= __('messages.pricing_card_1_price_button') ?></a>
          </div>
        </div>
      </div>

      <!-- Growth Package (Most Popular) -->
      <div class="col-md-4">
        <div class="card h-100 border pricing-card position-relative" style="border-color:#4ECDCB; border-radius:1rem; box-shadow:0 0.5rem 1rem rgba(0,0,0,0.1); min-height: 520px;">
          <div class="position-absolute top-0 end-0 px-3 py-1 text-white" style="background-color:#4ECDCB; border-bottom-left-radius:0.5rem; border-top-right-radius:0.5rem;">Most Popular</div>

          <div class="card-body d-flex flex-column justify-content-between text-start mt-4 p-5">
            <div>
              <h5 class="card-title mb-4"><?= __('messages.pricing_card_2_title') ?></h5>
              <p class="card-text mb-4"><?= __('messages.pricing_card_2_description') ?></p>
              <div class="h3 mb-4">€1,499<span class="text-muted fs-6">/month</span></div>
              <p class="fw-semibold mb-3"><?= __('messages.pricing_card_2_item_title') ?></p>
              <ul class="list-unstyled mb-4 ps-3">
                <li>✔ <?= __('messages.pricing_card_2_item_1') ?></li>
                <li>✔ <?= __('messages.pricing_card_2_item_2') ?></li>
                <li>✔ <?= __('messages.pricing_card_2_item_3') ?></li>
              </ul>
            </div>
            <a href="{{ route('register') }}" class="btn btn-primary w-100 mt-4 py-2"><?= __('messages.pricing_card_2_price_button') ?></a>
          </div>
        </div>
      </div>

      <!-- Premium Package -->
      <div class="col-md-4">
        <div class="card h-100 border rounded-3 shadow-sm pricing-card" style="min-height: 520px;">
          <div class="card-body d-flex flex-column justify-content-between text-start p-5">
            <div>
              <h5 class="card-title mb-4"><?= __('messages.pricing_card_3_title') ?></h5>
              <p class="card-text mb-4"><?= __('messages.pricing_card_3_description') ?></p>
              <div class="h3 mb-4">€2,799<span class="text-muted fs-6">/month</span></div>
              <p class="fw-semibold mb-3"><?= __('messages.pricing_card_3_item_title') ?></p>
              <ul class="list-unstyled mb-4 ps-3">
                <li>✔ <?= __('messages.pricing_card_3_item_1') ?></li>
                <li>✔ <?= __('messages.pricing_card_3_item_2') ?></li>
                <li>✔ <?= __('messages.pricing_card_3_item_3') ?></li>
              </ul>
            </div>
            <a href="{{ url('/contact') }}" class="btn btn-cta-secondary w-100 mt-4 py-2">Contact sales</a>
          </div>
        </div>
      </div>

    </div>

    <div class="text-center mt-5">
      <p class="text-muted mb-0">{{ __('messages.tagline') }} <a href="{{ url('/contact') }}" class="fw-semibold" style="color:#0b6266;"><?= __('messages.contact_us') ?></a></p>
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
    @media (prefers-reduced-motion: reduce) {
      .pricing-card { transition: none; }
      .pricing-card:hover { transform: none; }
    }
  </style>
</section>
