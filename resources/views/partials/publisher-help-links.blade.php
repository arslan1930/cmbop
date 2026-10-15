@php
    $helpLinks = $helpLinks ?? [
        [
            'label' => 'Pricing tips',
            'url' => url('/blog/'.\App\Support\HowToPriceYourSiteBlogPost::SLUG),
        ],
        [
            'label' => 'Content guidelines',
            'url' => url('/blog/'.\App\Support\WhySitesGetRejectedBlogPost::SLUG),
        ],
        [
            'label' => 'Payout FAQ',
            'url' => url('/blog/'.\App\Support\FasterPublisherPayoutsBlogPost::SLUG),
        ],
        [
            'label' => 'Publisher guide',
            'url' => url('/blog/'.\App\Support\PublisherPlatformGuideBlogPost::SLUG),
        ],
    ];
@endphp
<nav class="publisher-help-links d-flex flex-wrap gap-2" aria-label="Publisher help">
    @foreach($helpLinks as $link)
        <a href="{{ $link['url'] }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">{{ $link['label'] }}</a>
    @endforeach
</nav>
