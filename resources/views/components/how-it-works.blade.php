<section class="slb-section slb-how bg-white">
  <div class="container" style="max-width:1100px;">
    <div class="text-center mb-5 slb-reveal">
      <div class="slb-section-kicker">{{ __('messages.nav_how_it_works') }}</div>
      <h2 class="slb-section-title">{{ __('messages.how_it_works_title') }}</h2>
      <p class="slb-section-lead mb-0">{{ __('messages.how_it_works_description') }}</p>
    </div>

    <div class="row text-center g-4">
      <div class="col-md-4">
        <div class="how-step-icon mx-auto mb-3" aria-hidden="true">
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <h3 class="h5 mb-3">{{ __('messages.step_1_title') }}</h3>
        <p class="text-muted mb-0">{{ __('messages.step_1_description') }}</p>
      </div>

      <div class="col-md-4">
        <div class="how-step-icon mx-auto mb-3" aria-hidden="true">
          <i class="fa-solid fa-wallet"></i>
        </div>
        <h3 class="h5 mb-3">{{ __('messages.step_2_title') }}</h3>
        <p class="text-muted mb-0">{{ __('messages.step_2_description') }}</p>
      </div>

      <div class="col-md-4">
        <div class="how-step-icon mx-auto mb-3" aria-hidden="true">
          <i class="fa-solid fa-link"></i>
        </div>
        <h3 class="h5 mb-3">{{ __('messages.step_3_title') }}</h3>
        <p class="text-muted mb-0">{{ __('messages.step_3_description') }}</p>
      </div>
    </div>
  </div>
</section>

<style>
.slb-how .how-step-icon {
  width: 72px;
  height: 72px;
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(180deg, #e8f8f7 0%, #d4f1f0 100%);
  color: #0b6266;
  border: 1px solid rgba(11, 98, 102, 0.12);
  font-size: 1.35rem;
}
</style>
