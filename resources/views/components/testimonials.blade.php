<section class="slb-section slb-testimonials bg-white">
  <div class="container" style="max-width:900px;">
    <div class="text-center mb-4">
      <div class="slb-section-kicker">{{ __('messages.nav_marketplace') }}</div>
      <h2 class="slb-section-title">{{ __('messages.testimonial_title') }}</h2>
      <p class="slb-section-lead mb-0">{{ __('messages.testimonial_subtitle') }}</p>
    </div>

    <div id="testimonialCarousel" class="carousel slide slb-testimonial-carousel" data-bs-ride="carousel" data-bs-wrap="true">
      <div class="carousel-indicators">
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="3" aria-label="Slide 4"></button>
        <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="4" aria-label="Slide 5"></button>
      </div>

      <div class="carousel-inner text-center">
        <div class="carousel-item active">
          <blockquote class="slb-quote mx-auto">
            <p>{{ __('messages.testimonial_1_text') }}</p>
            <footer>
              <cite>{{ __('messages.testimonial_1_name') }}</cite>
              <span>{{ __('messages.testimonial_1_title') }}</span>
            </footer>
          </blockquote>
        </div>
        <div class="carousel-item">
          <blockquote class="slb-quote mx-auto">
            <p>{{ __('messages.testimonial_2_text') }}</p>
            <footer>
              <cite>{{ __('messages.testimonial_2_name') }}</cite>
              <span>{{ __('messages.testimonial_2_title') }}</span>
            </footer>
          </blockquote>
        </div>
        <div class="carousel-item">
          <blockquote class="slb-quote mx-auto">
            <p>{{ __('messages.testimonial_3_text') }}</p>
            <footer>
              <cite>{{ __('messages.testimonial_3_name') }}</cite>
              <span>{{ __('messages.testimonial_3_title') }}</span>
            </footer>
          </blockquote>
        </div>
        <div class="carousel-item">
          <blockquote class="slb-quote mx-auto">
            <p>{{ __('messages.testimonial_4_text') }}</p>
            <footer>
              <cite>{{ __('messages.testimonial_4_name') }}</cite>
              <span>{{ __('messages.testimonial_4_title') }}</span>
            </footer>
          </blockquote>
        </div>
        <div class="carousel-item">
          <blockquote class="slb-quote mx-auto">
            <p>{{ __('messages.testimonial_5_text') }}</p>
            <footer>
              <cite>{{ __('messages.testimonial_5_name') }}</cite>
              <span>{{ __('messages.testimonial_5_title') }}</span>
            </footer>
          </blockquote>
        </div>
      </div>

      <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
        <span class="slb-carousel-btn" aria-hidden="true"><i class="fa fa-chevron-left"></i></span>
        <span class="visually-hidden">{{ __('messages.previous') }}</span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
        <span class="slb-carousel-btn" aria-hidden="true"><i class="fa fa-chevron-right"></i></span>
        <span class="visually-hidden">{{ __('messages.next') }}</span>
      </button>
    </div>
  </div>
</section>
