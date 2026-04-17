<style>
  /* Carousel indicators */
  #testimonialCarousel .carousel-indicators [data-bs-target] {
    background-color: #ccc;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    transition: background-color 0.3s ease;
  }

  #testimonialCarousel .carousel-indicators .active {
    background-color: #4ECDCB !important;
  }

  /* Custom round arrow buttons */
  .carousel-control-prev-icon,
  .carousel-control-next-icon {
    background-image: none;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 50px;
    height: 50px;
    background-color: #6c757d;
    border-radius: 50%;
  }

  .carousel-control-prev-icon::after {
    content: "‹";
    font-size: 28px;
    color: #fff;
    font-weight: bold;
  }

  .carousel-control-next-icon::after {
    content: "›";
    font-size: 28px;
    color: #fff;
    font-weight: bold;
  }
</style>

<section class="py-5 bg-white">
  <div class="container">
    <h3 class="text-center mb-2">Trusted by Growth-Oriented Teams</h3>
    <p class="text-center mb-5">Real results from companies that take SEO seriously</p>

    <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-wrap="true">

      <!-- Indicators -->
      <div class="carousel-indicators">
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="0" class="active" aria-current="true"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="1"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="2"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="3"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="4"></button>
      </div>

      <!-- Carousel Inner -->
      <div class="carousel-inner text-center">

        <div class="carousel-item active">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">“LinkBuilder Pro increased our organic traffic by 300% in just three months. Their strategic approach is unmatched.”</p>
            <h6 class="mb-0">Sarah Johnson</h6>
            <small class="text-muted">Marketing Director, TechStart Inc</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">“Every link placement was relevant, authoritative, and effective. We now dominate search results in our niche.”</p>
            <h6 class="mb-0">Michael Chen</h6>
            <small class="text-muted">CEO, EcomGrowth</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">“Professional, reliable, and transparent. Our clients consistently praise the results achieved.”</p>
            <h6 class="mb-0">Emily Rodriguez</h6>
            <small class="text-muted">SEO Manager, DigitalAgency</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">“The team at LinkBuilder Pro truly understands SEO. They delivered beyond our expectations.”</p>
            <h6 class="mb-0">David Kim</h6>
            <small class="text-muted">Founder, StartupHub</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">“LinkBuilder Pro has been instrumental in our SEO strategy. The results speak for themselves.”</p>
            <h6 class="mb-0">Lisa Thompson</h6>
            <small class="text-muted">Marketing Director, GrowthCo</small>
          </div>
        </div>

      </div>

      <!-- Controls -->
      <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span>
        <span class="visually-hidden">Previous</span>
      </button>

      <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span>
        <span class="visually-hidden">Next</span>
      </button>

    </div>
  </div>
</section>