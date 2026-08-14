{{-- Empty-state recovery actions for catalog filters. --}}
@php
    $recovery = $catalogEmptyRecovery ?? null;
@endphp
@if($recovery)
    <p class="text-muted mb-3">{{ $recovery['body'] }}</p>
    <div class="d-flex flex-wrap justify-content-center gap-2 mb-3">
        @if(!empty($recovery['clear_category_url']))
            <a href="{{ $recovery['clear_category_url'] }}"
               class="btn btn-primary btn-sm catalog-clear-category"
               data-catalog-filter-action="clear-category">Clear category</a>
        @endif
        @if(!empty($recovery['clear_country_url']))
            <a href="{{ $recovery['clear_country_url'] }}"
               class="btn {{ empty($recovery['clear_category_url']) ? 'btn-primary' : 'btn-outline-secondary' }} btn-sm catalog-clear-country"
               data-catalog-filter-action="clear-country">Clear country</a>
        @endif
        <a href="{{ $recovery['clear_all_url'] }}"
           class="btn btn-outline-secondary btn-sm catalog-clear-all">Clear all filters</a>
        @if(!empty($recovery['try_language']))
            <a href="{{ $recovery['try_language']['url'] }}"
               class="btn btn-outline-secondary btn-sm catalog-try-language"
               data-catalog-filter-action="try-language"
               data-language="{{ $recovery['try_language']['code'] }}">
                Try Language: {{ $recovery['try_language']['name'] }}
            </a>
        @endif
        <button type="button" class="btn btn-outline-success btn-sm btn-suggest-website"
                data-search="{{ scalar_text(request('search')) }}">
            <i class="fa-solid fa-lightbulb me-1" aria-hidden="true"></i> Suggest a website
        </button>
    </div>
    @if(!empty($recovery['related_niches']))
        <p class="small text-muted mb-2">Related niches:</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-2">
            @foreach($recovery['related_niches'] as $related)
                <a href="{{ $related['url'] }}"
                   class="btn btn-sm btn-outline-primary catalog-related-niche"
                   data-catalog-filter-action="related-niche"
                   data-category="{{ $related['name'] }}">
                    {{ $related['name'] }} ({{ number_format($related['count']) }})
                </a>
            @endforeach
        </div>
    @endif
    @if(!empty($recovery['neighbors']))
        <p class="small text-muted mb-2">Also try:</p>
        <div class="d-flex flex-wrap justify-content-center gap-2 mb-2">
            @foreach($recovery['neighbors'] as $neighbor)
                <a href="{{ $neighbor['url'] }}"
                   class="btn btn-sm btn-outline-primary catalog-neighbor-market"
                   data-catalog-filter-action="neighbor"
                   data-country="{{ $neighbor['code'] }}">
                    {{ $neighbor['name'] }} ({{ number_format($neighbor['count']) }})
                </a>
            @endforeach
        </div>
    @endif
@endif
