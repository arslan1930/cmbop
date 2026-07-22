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
    <h3 class="text-center mb-2">{{ __('messages.testimonial_title') }}</h3>
    <p class="text-center mb-5">{{ __('messages.testimonial_subtitle') }}</p>

    <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-wrap="true">

      <!-- Indicators -->
      <div class="carousel-indicators">
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="3" aria-label="Slide 4"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="4" aria-label="Slide 5"></button>
      </div>

      <!-- Carousel Inner -->
      <div class="carousel-inner text-center">

        <div class="carousel-item active">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">{{ __('messages.testimonial_1_text') }}</p>
            <h6 class="mb-0">{{ __('messages.testimonial_1_name') }}</h6>
            <small class="text-muted">{{ __('messages.testimonial_1_title') }}</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">{{ __('messages.testimonial_2_text') }}</p>
            <h6 class="mb-0">{{ __('messages.testimonial_2_name') }}</h6>
            <small class="text-muted">{{ __('messages.testimonial_2_title') }}</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">{{ __('messages.testimonial_3_text') }}</p>
            <h6 class="mb-0">{{ __('messages.testimonial_3_name') }}</h6>
            <small class="text-muted">{{ __('messages.testimonial_3_title') }}</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">{{ __('messages.testimonial_4_text') }}</p>
            <h6 class="mb-0">{{ __('messages.testimonial_4_name') }}</h6>
            <small class="text-muted">{{ __('messages.testimonial_4_title') }}</small>
          </div>
        </div>

        <div class="carousel-item">
          <div class="p-4 p-md-5 rounded mx-auto" style="max-width:700px;">
            <p class="lead fst-italic mb-3">{{ __('messages.testimonial_5_text') }}</p>
            <h6 class="mb-0">{{ __('messages.testimonial_5_name') }}</h6>
            <small class="text-muted">{{ __('messages.testimonial_5_title') }}</small>
          </div>
        </div>

      </div>

      <!-- Controls -->
      <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span>
        <span class="visually-hidden">{{ __('messages.previous') }}</span>
      </button>

      <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span>
        <span class="visually-hidden">{{ __('messages.next') }}</span>
      </button>

    </div>
  </div>
</section>