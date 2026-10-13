{{--
    Open Graph + X card tags. Private shells keep their own noindex robots
    tag; this partial does not change indexing.
--}}
@php
    $socialTitle = trim((string) ($title ?? ''));
    if ($socialTitle === '') {
        $socialTitle = 'SEOLinkBuildings';
    }
    $socialDescription = trim((string) ($description ?? ''));
    if ($socialDescription === '') {
        $socialDescription = 'Guest post and backlink marketplace.';
    }
    $socialUrl = trim((string) ($url ?? url()->current()));
    if ($socialUrl === '') {
        $socialUrl = url()->current();
    }
    $socialImage = trim((string) ($image ?? ''));
    if ($socialImage === '') {
        $socialImage = asset('assets/brand/web/og-share-1200x630.png');
    }
    $socialImageAlt = trim((string) ($imageAlt ?? 'SEOLinkBuildings — Guest post & backlink marketplace'));
@endphp
<meta property="og:title" content="{{ $socialTitle }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ $socialUrl }}">
<meta property="og:image" content="{{ $socialImage }}">
<meta property="og:site_name" content="SEOLinkBuildings">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@seolinkbuildings">
<meta name="twitter:title" content="{{ $socialTitle }}">
<meta name="twitter:description" content="{{ $socialDescription }}">
<meta name="twitter:image" content="{{ $socialImage }}">
<meta name="twitter:image:alt" content="{{ $socialImageAlt }}">
