<section class="py-5 bg-light">
  <div class="container">
    <div class="bg-white rounded shadow p-4 p-md-5">

      <div class="row align-items-center">

        <!-- Left: Image -->
        <div class="col-lg-5 mb-4 mb-lg-0">
          <img src="{{ asset('assets/img/cta-newsletter.png') }}" 
               alt="{{ __('messages.newsletter_image_alt') }}" 
               class="img-fluid rounded" 
               loading="lazy">
        </div>

        <!-- Right: Content -->
        <div class="col-lg-7">
          <form id="newsletterForm" class="w-100">

            <h3 class="mb-3">
              {{ __('messages.newsletter_title') }}
            </h3>

            <!-- Email + Button -->
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
              <input type="email"
                     name="email"
                     placeholder="{{ __('messages.newsletter_email_placeholder') }}"
                     class="form-control me-sm-2"
                     required>

              <button type="submit" class="btn" style="background-color: #4ECDCB; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem;">
                {{ __('messages.newsletter_subscribe_btn') }}
              </button>
            </div>

            <!-- Consent -->
            <div class="form-check mb-2">
              <input type="checkbox"
                     class="form-check-input"
                     id="agreement_newsletter"
                     name="newsletter_opt_in"
                     value="1"
                     required>
              <label class="form-check-label small" for="agreement_newsletter">
                <span class="form-check-sign text-danger">*</span> {{ __('messages.newsletter_consent_text') }}
              </label>
            </div>

            <!-- GDPR / Info -->
            <div class="text-muted small">
              <p>{{ __('messages.newsletter_gdpr_text') }}</p>
              <p>{{ __('messages.newsletter_agreement_text') }}</p>
            </div>

            <!-- Hidden metadata -->
            <input type="hidden" name="form_name" value="newsletter_add_HS_en_GB_main_page">
            <input type="hidden" name="int_com_lang" value="{{ $currentLocale ?? 'en' }}">

          </form>
        </div>

      </div>

    </div>
  </div>
</section>

<script>
document.getElementById('newsletterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const email = this.querySelector('input[name="email"]').value;
    const consent = this.querySelector('input[name="newsletter_opt_in"]').checked;
    
    if (!consent) {
        alert('{{ __("messages.newsletter_consent_required") }}');
        return;
    }
    
    // Add your AJAX submission logic here
    alert('{{ __("messages.newsletter_success_message") }}');
    this.reset();
});
</script>