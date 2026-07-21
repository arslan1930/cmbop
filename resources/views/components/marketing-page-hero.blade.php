@props([
    'kicker' => null,
    'title',
    'subtitle' => null,
])

<section class="marketing-hero">
  <div class="container marketing-hero-inner">
    @include('components.marketing-brand-line')
    @if($kicker)
      <div class="mb-3">
        <span class="marketing-kicker">{{ $kicker }}</span>
      </div>
    @endif
    <h1 class="marketing-title">{{ $title }}</h1>
    @if($subtitle)
      <p class="marketing-subtitle">{{ $subtitle }}</p>
    @endif
  </div>
</section>

<style>
  .marketing-hero {
    position: relative;
    width: 100%;
    padding: 48px 0 40px;
    overflow: hidden;
    background: linear-gradient(180deg, #f0fbfb 0%, #f8fafc 100%);
  }
  .marketing-hero-inner {
    position: relative;
    z-index: 2;
    max-width: 860px;
    text-align: center;
  }
  .marketing-kicker {
    display: inline-block;
    background: rgba(78, 205, 203, 0.15);
    color: #0b6266;
    padding: 6px 16px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 600;
  }
  .marketing-title {
    font-size: clamp(1.75rem, 3.5vw, 2.75rem);
    font-weight: 800;
    color: #0b6266;
    letter-spacing: -0.02em;
    margin: 0 0 0.75rem;
    line-height: 1.15;
  }
  .marketing-subtitle {
    font-size: 1.05rem;
    color: #64748b;
    margin: 0 auto;
    max-width: 40rem;
    line-height: 1.55;
  }
</style>
