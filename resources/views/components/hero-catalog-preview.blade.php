@php
    $marketplaceHref = $marketplaceHref ?? localized_url('marketplace');

    $rows = [
        [
            'domain' => 'berlin**.de',
            'niches' => ['Entertainment & Media', 'Sports & Politics (regional)'],
            'more' => 2,
            'dr' => 89,
            'da' => 57,
            'traffic' => '427K',
            'backlinks' => '1.1M',
            'price' => '€400',
        ],
        [
            'domain' => 'munich**.net',
            'niches' => ['Automotive Sites', 'Ranking'],
            'more' => 1,
            'dr' => 8,
            'da' => 20,
            'traffic' => '1.3K',
            'backlinks' => '17K',
            'price' => '€350',
        ],
        [
            'domain' => 'hamburg**.de',
            'niches' => ['Technology & Computers', 'Telecommunications & Internet Providers'],
            'more' => 1,
            'dr' => 51,
            'da' => 56,
            'traffic' => '18K',
            'backlinks' => '76K',
            'price' => '€150',
        ],
        [
            'domain' => 'cologne**.de',
            'niches' => ['Entertainment & Media'],
            'more' => 1,
            'dr' => 54,
            'da' => 35,
            'traffic' => '6.8K',
            'backlinks' => '19K',
            'price' => '€270',
        ],
        [
            'domain' => 'frankfurt**.de',
            'niches' => ['Fashion & Luxury'],
            'more' => 1,
            'dr' => 26,
            'da' => 40,
            'traffic' => '751',
            'backlinks' => '2.3K',
            'price' => '€124',
        ],
        [
            'domain' => 'stuttgart**.com',
            'niches' => ['Travel & Tourism (EU destinations)', 'Entertainment & Media'],
            'more' => 1,
            'dr' => 32,
            'da' => 43,
            'traffic' => '772',
            'backlinks' => '266',
            'price' => '€280',
        ],
        [
            'domain' => 'dresden**.de',
            'niches' => ['Fitness & Sports', 'Entertainment & Media'],
            'more' => 1,
            'dr' => 23,
            'da' => 45,
            'traffic' => '209K',
            'backlinks' => '18K',
            'price' => '€109',
        ],
    ];
@endphp

<div class="slb-hero-visual">
  <div class="slb-hero-shot slb-hero-catalog-clone" aria-label="Publisher catalog preview">
    <table class="slb-hero-shot-table">
      <caption class="visually-hidden">Publisher catalog preview</caption>
      <thead>
        <tr>
          <th scope="col" class="is-site">Site</th>
          <th scope="col">Niche</th>
          <th scope="col">Country</th>
          <th scope="col">Language</th>
          <th scope="col">DR</th>
          <th scope="col">DA</th>
          <th scope="col">Traffic</th>
          <th scope="col">Backlinks</th>
          <th scope="col">Sponsored</th>
          <th scope="col">Price</th>
          <th scope="col">Action</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($rows as $site)
          <tr>
            <td class="is-site">
              <span class="slb-hero-shot-domain slb-hero-live-catalog__url-blur">{{ $site['domain'] }}</span>
              <span class="slb-hero-shot-icons" aria-hidden="true">
                <i class="fa-regular fa-heart"></i>
                <i class="fa-regular fa-clock"></i>
              </span>
            </td>
            <td class="is-niche">
              @foreach ($site['niches'] as $niche)
                <span>{{ $niche }}</span>
              @endforeach
              @if (($site['more'] ?? 0) > 0)
                <span class="slb-hero-shot-more">+more</span>
              @endif
            </td>
            <td class="is-country">
              <span class="slb-hero-shot-flag" aria-hidden="true">{!! getCountryFlag('de') !!}</span>
              <span>Germany</span>
            </td>
            <td>German</td>
            <td class="is-metric"><span class="slb-hero-shot-dr">{{ $site['dr'] }}</span></td>
            <td class="is-metric">{{ $site['da'] }}</td>
            <td class="is-metric"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i> {{ $site['traffic'] }}</td>
            <td class="is-metric">{{ $site['backlinks'] }}</td>
            <td><span class="slb-hero-shot-sponsored">Non sponsored</span></td>
            <td class="is-price">{{ $site['price'] }}</td>
            <td class="is-action">
              <span class="slb-hero-shot-buy">Buy Now</span>
              <span class="slb-hero-shot-fav" aria-hidden="true"><i class="fa-regular fa-heart"></i></span>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <a href="{{ $marketplaceHref }}" class="slb-hero-catalog-hit" aria-label="{{ __('messages.nav_marketplace') }}"></a>
</div>
