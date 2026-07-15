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
            @csrf

            <h3 class="mb-3">
              {{ __('messages.newsletter_title') }}
            </h3>

            <div id="newsletterAlert" class="alert d-none mb-3" role="alert"></div>

            <!-- Email + Button -->
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
              <input type="email"
                     name="email"
                     placeholder="{{ __('messages.newsletter_email_placeholder') }}"
                     class="form-control me-sm-2"
                     required>

              <button type="submit" id="newsletterSubmitBtn" class="btn" style="background-color: #4ECDCB; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem;">
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
                <span class="form-check-sign text-danger">*</span>
                {!! str_replace(
                    e(__('messages.privacy_policy')),
                    '<a href="'.e(route('privacy-policy')).'" target="_blank" rel="noopener">'.e(__('messages.privacy_policy')).'</a>',
                    e(__('messages.newsletter_consent_text'))
                ) !!}
              </label>
            </div>

            <!-- GDPR / Info -->
            <div class="text-muted small">
              <p>{{ __('messages.newsletter_gdpr_text') }}</p>
              <p>{{ __('messages.newsletter_agreement_text') }}</p>
            </div>

            <input type="hidden" name="int_com_lang" value="{{ $currentLocale ?? app()->getLocale() }}">

          </form>
        </div>

      </div>

    </div>
  </div>
</section>

<script>
document.getElementById('newsletterForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const form = this;
    const alertEl = document.getElementById('newsletterAlert');
    const submitBtn = document.getElementById('newsletterSubmitBtn');
    const consent = form.querySelector('input[name="newsletter_opt_in"]').checked;

    alertEl.classList.add('d-none');
    alertEl.classList.remove('alert-success', 'alert-danger', 'alert-warning');

    if (!consent) {
        alertEl.textContent = @json(__('messages.newsletter_consent_required'));
        alertEl.classList.remove('d-none');
        alertEl.classList.add('alert-danger');
        return;
    }

    submitBtn.disabled = true;
    const originalLabel = submitBtn.textContent;
    submitBtn.textContent = '...';

    try {
        const response = await fetch(@json(route('newsletter.subscribe')), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
            credentials: 'same-origin',
        });

        const data = await response.json();
        alertEl.textContent = data.message || @json(__('messages.newsletter_error_message'));
        alertEl.classList.remove('d-none');
        alertEl.classList.add(data.success ? 'alert-success' : 'alert-danger');

        if (data.success) {
            form.reset();
        }
    } catch (err) {
        alertEl.textContent = @json(__('messages.newsletter_error_message'));
        alertEl.classList.remove('d-none');
        alertEl.classList.add('alert-danger');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
    }
});
</script>
