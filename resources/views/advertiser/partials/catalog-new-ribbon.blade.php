@if(! empty($isNew))
    <button type="button"
            class="site-badge-new catalog-new-ribbon"
            data-glass-tip
            data-glass-tip-title="New Listing"
            data-glass-tip-body="Added in the last 30 days — fresh inventory worth reviewing early."
            data-glass-tip-placement="top"
            aria-label="New listing">
        <img src="{{ asset('assets/img/catalog-new-ribbon.svg') }}"
             alt=""
             width="32"
             height="32"
             decoding="async">
        <span class="visually-hidden">NEW</span>
    </button>
@endif
