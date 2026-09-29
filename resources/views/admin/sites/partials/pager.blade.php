@php
    $pageSize = (int) $paginator->perPage();
    if (! in_array($pageSize, [20, 50, 100], true)) {
        $pageSize = 20;
    }
    $keptQuery = request()->except(['page', 'per_page', 'publisher']);
    $showPager = $paginator->total() > 20;
@endphp
@if($showPager)
<div class="admin-sites-pager">
    <form method="get" action="{{ url()->current() }}" class="admin-sites-pager__size admin-deposits-filters">
        @foreach($keptQuery as $key => $value)
            @if(is_array($value))
                @foreach($value as $item)
                    @if(is_scalar($item) && (string) $item !== '')
                        <input type="hidden" name="{{ $key }}[]" value="{{ $item }}">
                    @endif
                @endforeach
            @elseif(is_scalar($value) && (string) $value !== '')
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <label class="small text-muted mb-0" for="{{ $selectId }}">Rows</label>
        <select id="{{ $selectId }}" name="per_page" class="form-select form-select-sm" aria-label="Rows per page">
            @foreach([20, 50, 100] as $size)
                <option value="{{ $size }}" @selected($pageSize === $size)>{{ $size }}</option>
            @endforeach
        </select>
    </form>
    <div class="admin-sites-pager__links">
        {{ $paginator->links() }}
    </div>
    <div class="admin-sites-pager__end" aria-hidden="true"></div>
</div>
@endif
