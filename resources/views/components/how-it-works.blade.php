<section class="py-20 bg-white how-it-works">
  <div class="container py-5">
    <div class="text-center mb-5">
      <h2 class="h2 mb-3">{{ __('messages.how_it_works_title') }}</h2>
      <p class="lead text-muted mb-0 mx-auto" style="max-width: 640px;">{{ __('messages.how_it_works_description') }}</p>
    </div>

    <div class="row text-center g-4">

      <!-- Step 1 -->
      <div class="col-md-4">
        <div class="how-step-icon mx-auto mb-3" aria-hidden="true">
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <h3 class="h5 mb-3">{{ __('messages.step_1_title') }}</h3>
        <p class="text-muted mb-0">{{ __('messages.step_1_description') }}</p>
      </div>

      <!-- Step 2 -->
      <div class="col-md-4">
        <div class="how-step-icon mx-auto mb-3" aria-hidden="true">
          <i class="fa-solid fa-wallet"></i>
        </div>
        <h3 class="h5 mb-3">{{ __('messages.step_2_title') }}</h3>
        <p class="text-muted mb-0">{{ __('messages.step_2_description') }}</p>
      </div>

      <!-- Step 3 -->
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
.how-it-works .how-step-icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(180deg, #e8f8f7 0%, #d4f1f0 100%);
  color: var(--brand-primary, #0b6266);
  border: 1px solid rgba(11, 98, 102, 0.12);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
  font-size: 1.5rem;
}
</style>
