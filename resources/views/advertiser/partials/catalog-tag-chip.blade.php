{{-- Exclusive listing disclosure chip. Expects $site.
     $showNone: paint No tags (Details only). Closed rows stay empty when untagged.
     $showDefinition: visible glossary line under the chip (expand / mobile Details). --}}
@php
    $showNone = $showNone ?? false;
    $showDefinition = $showDefinition ?? false;
    $tagValue = $site->tagValue();
    $tagLabel = $site->tagLabel();
    $isNone = $tagValue === null;
    $tagTitle = $isNone
        ? \App\Support\SiteTag::NONE_CHIP_TITLE
        : \App\Support\SiteTag::catalogChipTitle($tagValue);
    $tagIcon = match ($tagValue) {
        \App\Support\SiteTag::SPONSORED => 'fa-star',
        \App\Support\SiteTag::PARTNER => 'fa-handshake',
        \App\Support\SiteTag::AS_YOU_PREFER => 'fa-sliders-h',
        default => $isNone && $showNone ? 'fa-tag' : null,
    };
    $tagModifier = match ($tagValue) {
        \App\Support\SiteTag::SPONSORED => 'sponsored',
        \App\Support\SiteTag::PARTNER => 'partner',
        \App\Support\SiteTag::AS_YOU_PREFER => 'prefer',
        default => $isNone && $showNone ? 'none' : null,
    };
    $chipLabel = $isNone ? \App\Support\SiteTag::NONE_LABEL : $tagLabel;
@endphp
@if($chipLabel && $tagModifier)
    <span class="site-chip site-chip--{{ $tagModifier }}"
          @if($tagTitle) title="{{ $tagTitle }}" @endif>
        @if($tagIcon)
            <i class="fa-solid {{ $tagIcon }}" aria-hidden="true"></i>
        @endif
        <span>{{ $chipLabel }}</span>
    </span>
    @if($showDefinition && $tagTitle)
        <p class="small text-muted mb-0 mt-1 catalog-tag-definition">{{ $tagTitle }}</p>
    @endif
@endif
