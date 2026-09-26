@php
    $formatStaffList = function (array $items, bool $upper = false): string {
        $clean = array_values(array_filter(array_map(
            static fn ($value) => trim(scalar_text($value)),
            $items
        ), static fn ($value) => $value !== ''));
        $shown = array_slice($clean, 0, 3);
        if ($upper) {
            $shown = array_map(static fn ($value) => strtoupper($value), $shown);
        }
        $text = implode(', ', $shown);
        $extra = count($clean) - count($shown);
        if ($extra > 0) {
            $text .= ' +'.$extra.' more';
        }

        return $text !== '' ? $text : '—';
    };
    $countryList = [];
    $languageList = [];
    $categoryList = [];
    try {
        $countryList = $site->countryCodesForDisplay();
    } catch (\Throwable $e) {
        $countryList = [];
    }
    try {
        $languageList = $site->languageCodes();
    } catch (\Throwable $e) {
        $languageList = [];
    }
    try {
        $categoryList = array_values((array) $site->categories_array);
    } catch (\Throwable $e) {
        $categoryList = [];
    }
    $linkLabel = null;
    try {
        $linkLabel = $site->linkTypeLabel();
    } catch (\Throwable $e) {
        $linkLabel = null;
    }
    $metricsLabel = null;
    try {
        $fetched = $site->metrics_fetched_at;
        if ($fetched instanceof \DateTimeInterface) {
            $metricsLabel = \Illuminate\Support\Carbon::parse($fetched)->timezone(config('app.timezone'))->format('M j, Y');
        }
    } catch (\Throwable $e) {
        $metricsLabel = null;
    }
    $ordersCount = (int) $site->orderItemsCount();
    $ordersSearch = trim((string) ($site->domain ?: $site->site_name ?: ''));
    $ordersUrl = ($ordersSearch !== '' && auth()->user()?->isAdmin())
        ? route('admin.orders.index', ['search' => $ordersSearch])
        : null;
@endphp
<div class="small text-muted">
    {{ $formatStaffList($countryList, true) }}
    · {{ $formatStaffList($languageList, true) }}
    · {{ $formatStaffList($categoryList) }}
    @if($linkLabel)
        · {{ $linkLabel }}
    @endif
    @if($site->sponsored)
        · Sponsored
    @endif
    @if($metricsLabel)
        · Metrics {{ $metricsLabel }}
    @endif
</div>
<div class="small mt-1">
    @if($ordersUrl)
        <a href="{{ $ordersUrl }}">{{ $ordersCount }} order{{ $ordersCount === 1 ? '' : 's' }}</a>
    @else
        {{ $ordersCount }} order{{ $ordersCount === 1 ? '' : 's' }}
    @endif
</div>
@if((string) $site->enrichment_status === 'failed')
    <div class="d-flex flex-wrap gap-1 mt-1">
        <span class="badge text-bg-danger">Scan failed</span>
    </div>
@endif
