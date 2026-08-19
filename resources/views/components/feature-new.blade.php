@props(['badgeKey' => ''])

@if(\App\Support\FeatureBadge::active((string) $badgeKey))
    <span {{ $attributes->class('feature-new-badge') }}>{{ \App\Support\FeatureBadge::label((string) $badgeKey) }}</span>
@endif
