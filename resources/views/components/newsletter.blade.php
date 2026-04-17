<section class="py-5 bg-light">
  <div class="container">
    <div class="bg-white rounded shadow p-4 p-md-5">

      <div class="row align-items-center">

        <!-- Left: Image -->
        <div class="col-lg-5 mb-4 mb-lg-0">
          <img src="{{ asset('assets/img/cta-newsletter.png') }}" 
               alt="Newsletter CTA" 
               class="img-fluid rounded" 
               loading="lazy">
        </div>

        <!-- Right: Content -->
        <div class="col-lg-7">
          <form id="newsletterForm" class="w-100">

            <h3 class="mb-3">
              Subscribe and receive updates on the latest features and changes on our platform.
            </h3>

            <!-- Email + Button -->
            <div class="d-flex flex-column flex-sm-row gap-2 mb-3">
              <input type="email"
                     name="email"
                     placeholder="Your Email Address"
                     class="form-control me-sm-2"
                     required>

              <button type="submit" class="btn" style="background-color: #4ECDCB; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem;">
  Subscribe
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
                <span class="form-check-sign text-danger">*</span> I subscribe to the SEOLinkBuildings newsletter and confirm that I have read the Privacy Policy.
              </label>
            </div>

            <!-- GDPR / Info -->
            <div class="text-muted small">
              <p>The controller for personal data of individuals who use the Seolinkbuildings.com website and all subpages ...</p>
              <p>By signing up for the newsletter, you agree to receive commercial information via electronic communications ...</p>
            </div>

            <!-- Hidden metadata -->
            <input type="hidden" name="form_name" value="newsletter_add_HS_en_GB_main_page">
            <input type="hidden" name="int_com_lang" value="en">

          </form>
        </div>

      </div>

    </div>
  </div>
</section>