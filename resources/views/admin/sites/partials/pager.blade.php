@php
    $pageSize = (int) $paginator->perPage();
    if (! in_array($pageSize, [20, 50, 100], true)) {
        $pageSize = 20;
    }
    $keptQuery = array_filter(
        array_merge($modeQuery ?? [], request()->except(['page', 'per_page', 'publisher', 'site', 'sites_page'])),
        static function ($value) {
            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null && $value !== '';
        }
    );
    $total = (int) $paginator->total();
    $showPager = $total > 0;
    $from = (int) ($paginator->firstItem() ?? 0);
    $to = (int) ($paginator->lastItem() ?? 0);
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
        <span class="small text-muted mb-0">Showing {{ number_format($from) }}–{{ number_format($to) }} of {{ number_format($total) }}</span>
        <label class="small text-muted mb-0" for="{{ $selectId }}">Rows</label>
        <select id="{{ $selectId }}" name="per_page" class="form-select form-select-sm" aria-label="Rows per page" onchange="this.form.submit()">
            @foreach([20, 50, 100] as $size)
                <option value="{{ $size }}" @selected($pageSize === $size)>{{ $size }}</option>
            @endforeach
        </select>
    </form>
    <div class="admin-sites-pager__links">
        @if($paginator->lastPage() > 1)
            {{ $paginator->links() }}
        @endif
    </div>
    <div class="admin-sites-pager__end" aria-hidden="true"></div>
</div>
@endif
