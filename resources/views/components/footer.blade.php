<footer class="bg-light text-dark pt-5 pb-4">
    <div class="container">
        <div class="row gy-4">

            <!-- Brand -->
            <div class="col-md-3">
                <a href="{{ url('/') }}">
                    <img src="{{ asset('assets/img/logo1.png') }}" 
                         alt="SEO Link Buildings Logo" 
                         style="max-width: 200px;">
                </a>
                <p class="mt-3 small">
                    Professional link building services that deliver measurable results for your business.
                </p>
                <!-- Add Social Media Links -->
                <div class="mt-3">
                    <a href="#" class="text-dark text-decoration-none me-3"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" class="text-dark text-decoration-none me-3"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="text-dark text-decoration-none me-3"><i class="fab fa-linkedin-in"></i></a>
                    <a href="#" class="text-dark text-decoration-none"><i class="fab fa-instagram"></i></a>
                </div>

            </div>

            <!-- Services -->
            <div class="col-md-3">
                <h5 class="mb-3">Services</h5>
                <ul class="list-unstyled small">
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">Article Publication</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">Copywriting</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">Link Insertion</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">Digital PR</a></li>
                    <li><a href="#" class="text-dark text-decoration-none d-block mb-2">Free SEO Audit</a></li>
                </ul>
            </div>

            <!-- Company -->
            <div class="col-md-3">
                <h5 class="mb-3">Company</h5>
                <ul class="list-unstyled small">
                    <li><a href="{{ url('contact-us') }}" class="text-dark text-decoration-none d-block mb-2">Contact</a></li>
                    <li><a href="{{ url('privacy-policy') }}" class="text-dark text-decoration-none d-block mb-2">Privacy Policy</a></li>
                    <li><a href="{{ url('terms-of-service') }}" class="text-dark text-decoration-none d-block mb-2">Terms of Service</a></li>
                </ul>
            </div>

            <!-- Address -->
            <div class="col-md-3">
                <h5 class="mb-3">Address</h5>
                <p class="small mb-0">
                    SEOLinkBuildings is a partner brand of TopURLz Ltd, registered in the United Kingdom.
                </p>
                <p class="small mt-2 mb-0">
                    Registered Address: 20 Wenlock Road, London, England, N1 7GU
                </p>
                <p class="small mt-2">
                    Company Number: 16607074
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