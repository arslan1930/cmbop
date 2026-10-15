@if(! empty($isNew))
    <button type="button"
            class="site-badge-new catalog-new-ribbon"
            data-glass-tip
            data-glass-tip-title="{{ __('messages.catalog_new_listing') }}"
            data-glass-tip-body="{{ __('messages.catalog_new_listing_body') }}"
            data-glass-tip-placement="top"
            aria-label="{{ __('messages.catalog_new_listing') }}">
        <img src="{{ asset('assets/img/catalog-new-ribbon.svg') }}"
             alt=""
             width="56"
             height="56"
             decoding="async">
        <span class="visually-hidden">NEW</span>
    </button>
@endif
