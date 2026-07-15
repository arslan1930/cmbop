@php
  // Get locale from URL segment for footer links
  $segments = request()->segments();
  $availableLocales = ['de', 'fr', 'nl'];
  $currentLocale = 'en';
  
  if (!empty($segments) && in_array($segments[0], $availableLocales)) {
    $currentLocale = $segments[0];
  }
@endphp

<footer class="bg-light text-dark pt-5 pb-4">
    <div class="container">
        <div class="row gy-4">

            <!-- Brand -->
            <div class="col-md-3">
                <a href="{{ $currentLocale == 'en' ? url('/') : url('/' . $currentLocale) }}">
                    <img src="{{ asset('assets/img/logo1.png') }}" 
                         alt="SEO Link Buildings Logo" 
                         style="max-width: 200px;">
                </a>
                <p class="mt-3 small">
                    {{ __('messages.professional_services') }}
                </p>
                <!-- Add Social Media Links -->
                <div class="mt-3">
                    <!-- Linkedin Company -->
                    <a href="https://www.linkedin.com/company/seolinkbuildings" target="_blank" class="text-dark me-3">
                        <i class="fab fa-linkedin fa-lg"></i>
                    </a>
                </div>

            </div>

            <!-- Services -->
            <div class="col-md-3">
                <h5 class="mb-3">{{ __('messages.services') }}</h5>
                <ul class="list-unstyled small">
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.article_publication') }}</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.copywriting') }}</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.link_insertions') }}</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.digital_pr') }}</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.free_seo_audit') }}</a></li>
                </ul>
            </div>

            <!-- Company -->
            <div class="col-md-3">
                <h5 class="mb-3">{{ __('messages.company') }}</h5>
                <ul class="list-unstyled small">
                    <li><a href="{{ $currentLocale == 'en' ? url('contact') : url('/' . $currentLocale . '/contact') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.contact') }}</a></li>
                    <!-- <li><a href="{{ $currentLocale == 'en' ? url('blog') : url('/' . $currentLocale . '/blog') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.blog') }}</a></li> -->
                    <li><a href="{{ $currentLocale == 'en' ? url('privacy-policy') : url('/' . $currentLocale . '/privacy-policy') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.privacy_policy') }}</a></li>
                    <li><a href="{{ $currentLocale == 'en' ? url('terms-of-services') : url('/' . $currentLocale . '/terms-of-services') }}" class="text-dark text-decoration-none d-block mb-2">{{ __('messages.terms_of_service') }}</a></li>
                </ul>
            </div>

            <!-- Address -->
            <div class="col-md-3">
                <h5 class="mb-3">{{ __('messages.address') }}</h5>
                <p class="small mb-0">
                    {{ __('messages.address_description') }}
                </p>
                <p class="small mt-2 mb-0">
                    {{ __('messages.registered_address') }}
                </p>
                <p class="small mt-2">
                    {{ __('messages.company_number') }}: 16607074
                </p>
            </div>

        </div>

        <!-- Bottom Bar -->
        <hr class="my-4">

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">

            <!-- Copyright -->
            <p class="small mb-3 mb-md-0">
                &copy; {{ date('Y') }} SEOLinkBuildings. All rights reserved.
            </p>

            <!-- Payment Icons -->
            <div class="d-flex align-items-center gap-3">
                <img src="{{ asset('assets/img/pay-pal-logo.png') }}" height="24" alt="PayPal">
                <img src="{{ asset('assets/img/wise.png') }}" height="30" alt="Wise">
                <img src="{{ asset('assets/img/bank.png') }}" height="24" alt="Bank">
                <img src="{{ asset('assets/img/crypto_currency.png') }}" height="32" alt="Crypto">
            </div>

        </div>
    </div>
</footer>