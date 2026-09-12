@props([
    'title' => 'Guest posts by market',
    'landers' => [],
    'label' => 'Country landers',
])

@if(!empty($landers))
<nav {{ $attributes->class('country-lander-nav') }} aria-label="{{ $label }}">
    <h2 class="country-lander-nav__title">{{ $title }}</h2>
    <ul class="country-lander-nav__row">
        @foreach($landers as $landerLink)
            <li>
                <a
                    class="country-lander-nav__card"
                    href="{{ $landerLink['url'] }}"
                    @if(!empty($landerLink['kicker'])) title="{{ $landerLink['kicker'] }}" @endif
                >{{ $landerLink['market'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>

<style>
  .country-lander-nav {
    margin-top: 2rem;
    padding-top: 1.15rem;
    border-top: 1px solid rgba(26, 88, 94, 0.12);
  }
  .country-lander-nav__title {
    margin: 0 0 0.65rem;
    text-align: center;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1a585e;
  }
  .country-lander-nav__row {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.45rem;
  }
  .country-lander-nav__card {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.8rem;
    border: 1px solid rgba(26, 88, 94, 0.16);
    border-radius: 999px;
    background: #fff;
    text-decoration: none;
    color: #1a585e;
    font-size: 0.88rem;
    font-weight: 600;
    line-height: 1.3;
    white-space: nowrap;
  }
  .country-lander-nav__card:hover,
  .country-lander-nav__card:focus-visible {
    color: #1a585e;
    text-decoration: none;
    border-color: #1a585e;
    background: #f4fbfb;
  }
</style>
@endif
