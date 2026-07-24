<div id="sitesStatusMeta"
     data-pending="{{ (int) ($pendingCount ?? 0) }}"
     data-active="{{ (int) ($activeCount ?? 0) }}"
     data-status="{{ $status ?? 'active' }}"
     class="d-none"
     aria-hidden="true"></div>
@if($sites->count() > 0)
<style>
    .modern-table {
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        text-align: left;
        margin-bottom: 0;
        background: #fff;
        min-width: 980px;
    }

    .modern-table th, .modern-table td {
        vertical-align: middle !important;
        white-space: nowrap;
    }

    .sites-table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .modern-table thead {
        background: #185054;
        color: #fff;
        text-align: left;
    }

    .modern-table thead th {
        font-size: 12px;
        font-weight: 650;
        letter-spacing: .02em;
        padding: 12px 10px;
        border: 0;
    }

    .modern-table tbody tr.main-row {
        cursor: default;
        transition: background 0.15s ease;
    }

    .modern-table tbody tr.main-row:hover {
        background: #f7fafb;
    }

    .modern-table tbody tr.main-row td {
        padding: 10px;
        border-color: #eef2f5;
    }

    .site-row-preview {
        width: 120px;
        height: 80px;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        background: linear-gradient(145deg, #f8fafb 0%, #eef2f5 100%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    a.site-row-preview:hover {
        border-color: #185054;
        box-shadow: 0 0 0 1px #185054;
    }

    .site-row-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .site-row-preview.is-empty {
        color: #94a3b8;
        font-size: 18px;
    }

    .site-row-identity {
        min-width: 12rem;
        max-width: 18rem;
        white-space: normal;
    }

    .site-row-name {
        font-weight: 650;
        color: #185054;
        margin: 0 0 2px;
        line-height: 1.25;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .site-row-url {
        font-size: 12px;
        color: var(--brand-ink-muted, #75787B);
        margin: 0;
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-all;
    }

    .site-row-category {
        display: inline-block;
        margin-top: 3px;
        max-width: 100%;
        font-size: 11px;
        font-weight: 600;
        color: #475569;
        background: #f1f5f9;
        border-radius: 4px;
        padding: 1px 6px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: middle;
    }

    .site-row-metrics {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: #475569;
    }

    .site-row-metrics strong {
        color: #185054;
        font-weight: 700;
    }

    .site-row-market {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: var(--brand-ink-muted, #75787B);
    }

    .site-row-market .country-flag {
        font-size: 16px;
        line-height: 1;
    }

    .site-row-actions {
        display: inline-flex;
        flex-wrap: nowrap;
        align-items: center;
        gap: 2px;
        justify-content: flex-end;
    }

    .site-row-actions .btn-edit {
        margin-left: 4px;
        margin-right: 2px;
        padding: 0.25rem 0.85rem;
        font-size: 12.5px;
        line-height: 1.2;
        border-radius: 999px;
    }

    .site-row-actions .btn-icon-quiet {
        width: 34px;
        height: 34px;
    }

    .site-row-actions .btn-icon-quiet.is-on {
        color: #185054;
        background: #e6f5f5;
    }

    .site-row-actions .btn-text-quiet {
        border: 0;
        background: transparent;
        color: var(--brand-ink-muted, #75787B);
        font-size: 12px;
        font-weight: 600;
        padding: 0.25rem 0.5rem;
        border-radius: 999px;
        transition: background-color 150ms ease, color 150ms ease;
    }

    .site-row-actions .btn-text-quiet:hover {
        background: rgba(15, 23, 42, 0.06);
        color: #334155;
    }

    .site-row-actions .btn-text-quiet.is-danger:hover {
        background: #fef2f2;
        color: #dc2626;
    }

    .expand-row {
        background: #fafafa;
        transition: all 0.3s ease-in-out;
    }

    .expand-row td {
        padding: 0 !important;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
        white-space: normal !important;
    }

    .expand-box {
        padding: 0 18px;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
    }

    .expand-row.expanded .expand-box {
        padding: 18px;
        max-height: 800px;
        opacity: 1;
    }

    .detail-line {
        margin-bottom: 8px;
        font-size: 14px;
    }

    .detail-line strong {
        color: #555;
        margin-right: 5px;
    }

    .tag-badge {
        background: #eef6ff;
        color: #185054;
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 12px;
        margin-right: 6px;
        display: inline-block;
    }

    .sensitive-badge {
        background: #fff3cd;
        color: #856404;
        padding: 5px 10px;
        border-radius: 6px;
        font-size: 12px;
        margin-right: 6px;
        display: inline-block;
    }

    .desc-box {
        margin-top: 10px;
        padding: 10px;
        background: #fff;
        border: 1px solid #eee;
        border-radius: 8px;
    }

    .turnaround-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        background-color: #f1f1f1;
        color: #282828;
    }

    .status-badge {
        font-size: 11px;
        font-weight: 650;
    }

    /* Readable status chips (avoid Bootstrap bg-info white-on-white) */
    .site-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        font-weight: 650;
        letter-spacing: .01em;
        border-radius: 999px;
        padding: 4px 10px;
        border: 1px solid transparent;
        line-height: 1.2;
        white-space: nowrap;
    }
    .site-status--verified {
        background: #ecfdf5;
        color: #065f46;
        border-color: #a7f3d0;
    }
    .site-status--active {
        background: #e6f5f5;
        color: #123f42;
        border-color: #b8e4e4;
    }
    .site-status--pending {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }

    .site-row-price {
        font-weight: 700;
        color: #185054;
        white-space: nowrap;
    }

    .site-row-price-meta {
        display: inline-flex;
        gap: 4px;
        margin-left: 4px;
        vertical-align: middle;
    }
</style>

{{-- YouTube-like dark tooltips for this table --}}
<style>
    .tooltip.yt-tooltip .tooltip-inner {
        background-color: #0f0f0f;
        color: #fff;
        border-radius: 8px;
        padding: 8px 12px;
        font-size: 12px;
        font-weight: 500;
        max-width: 220px;
    }
    .tooltip.yt-tooltip.bs-tooltip-top .tooltip-arrow::before,
    .tooltip.yt-tooltip.bs-tooltip-auto[data-popper-placement^=top] .tooltip-arrow::before {
        border-top-color: #0f0f0f;
    }
    .tooltip.yt-tooltip.bs-tooltip-bottom .tooltip-arrow::before,
    .tooltip.yt-tooltip.bs-tooltip-auto[data-popper-placement^=bottom] .tooltip-arrow::before {
        border-bottom-color: #0f0f0f;
    }
    .tooltip.yt-tooltip.bs-tooltip-start .tooltip-arrow::before,
    .tooltip.yt-tooltip.bs-tooltip-auto[data-popper-placement^=left] .tooltip-arrow::before {
        border-left-color: #0f0f0f;
    }
    .tooltip.yt-tooltip.bs-tooltip-end .tooltip-arrow::before,
    .tooltip.yt-tooltip.bs-tooltip-auto[data-popper-placement^=right] .tooltip-arrow::before {
        border-right-color: #0f0f0f;
    }
</style>

@php
    if (!function_exists('getCountryFlag')) {
        function getCountryFlag($countryCode) {
            $code = strtoupper(trim((string) $countryCode));
            if (strlen($code) !== 2) {
                return '';
            }
            if ($code === 'UK') {
                $code = 'GB';
            }

            return mb_chr(127397 + ord($code[0]), 'UTF-8').mb_chr(127397 + ord($code[1]), 'UTF-8');
        }
    }

    if (!function_exists('getLanguageName')) {
        function getLanguageName($code) {
            return fullLanguage($code);
        }
    }

    if (!function_exists('getPublicationDuration')) {
        function getPublicationDuration($value) {
            $durations = [
                '6months' => '6 Months',
                '1year' => '1 Year',
                'permanent' => 'Permanent'
            ];
            return $durations[$value] ?? ucfirst($value);
        }
    }

    if (!function_exists('getTurnaroundLabel')) {
        function getTurnaroundLabel($value) {
            $labels = [
                '24h' => '24 Hours',
                '48h' => '48 Hours',
                '3days' => '3 Days',
                '5days' => '5 Days',
                '7days' => '7 Days'
            ];
            return $labels[$value] ?? '3 Days';
        }
    }

    if (!function_exists('getTurnaroundClass')) {
        function getTurnaroundClass($value) {
            $classes = [
                '24h' => 'turnaround-24h',
                '48h' => 'turnaround-48h',
                '3days' => 'turnaround-3days',
                '5days' => 'turnaround-5days',
                '7days' => 'turnaround-7days'
            ];
            return $classes[$value] ?? 'turnaround-3days';
        }
    }
@endphp

<div class="table-responsive sites-table-scroll">
<table class="table modern-table sites-responsive-table align-middle mb-0">
    <thead>
        <tr>
            <th style="width:132px;">Preview</th>
            <th>Site</th>
            <th>Metrics</th>
            <th>Market</th>
            <th>Status</th>
            <th>Price</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sites as $index => $site)
        @php
            $thumbUrl = $site->screenshot_thumb_url;
            $fullPreviewUrl = $site->screenshot_url ?: $site->image_url;
            $previewUrl = $thumbUrl ?: $fullPreviewUrl;
            $siteCountries = is_array($site->countries) && count($site->countries)
                ? $site->countries
                : array_filter([$site->country]);
            $siteLanguages = is_array($site->languages) && count($site->languages)
                ? $site->languages
                : array_filter([$site->language]);
            $categoryLabel = is_array($site->categories) && count($site->categories)
                ? implode(', ', array_slice($site->categories, 0, 2))
                : (string) $site->category;
        @endphp
        <tr class="main-row" data-id="{{ $site->id }}">
            <td data-label="Preview">
                @if($previewUrl)
                    <a href="{{ $fullPreviewUrl ?: $previewUrl }}" target="_blank" rel="noopener noreferrer"
                       class="site-row-preview"
                       data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Preview"
                       onclick="event.stopPropagation();">
                        <img src="{{ $previewUrl }}"
                             alt="{{ $site->site_name }} preview"
                             loading="lazy"
                             onerror="this.onerror=null; this.parentElement.classList.add('is-empty'); this.parentElement.innerHTML='<i class=\'fa fa-image\' aria-hidden=\'true\'></i>';">
                    </a>
                @else
                    <span class="site-row-preview is-empty"
                          data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="No preview"
                          aria-label="No preview">
                        <i class="fa fa-image" aria-hidden="true"></i>
                    </span>
                @endif
            </td>

            <td data-label="Site">
                <div class="site-row-identity">
                    <p class="site-row-name" title="{{ $site->site_name }}">{{ $site->site_name }}</p>
                    <p class="site-row-url" title="{{ $site->site_url }}">{{ $site->domain ?: $site->site_url }}</p>
                    @if($categoryLabel !== '')
                        <span class="site-row-category" title="{{ $categoryLabel }}">{{ $categoryLabel }}</span>
                    @endif
                </div>
            </td>

            <td data-label="Metrics">
                <div class="site-row-metrics" title="DA / DR / Traffic">
                    <span>DA <strong>{{ $site->da }}</strong></span>
                    <span>DR <strong>{{ $site->dr }}</strong></span>
                    <span>Tr <strong>{{ number_format((int) $site->traffic) }}</strong></span>
                </div>
            </td>

            <td data-label="Market">
                <div class="site-row-market">
                    <span class="country-flag" aria-hidden="true">
                        @foreach(array_slice($siteCountries, 0, 2) as $code)
                            {!! getCountryFlag($code) !!}
                        @endforeach
                    </span>
                    <span>{{ collect(array_slice($siteLanguages, 0, 2))->map(fn ($c) => getLanguageName($c))->implode(', ') }}</span>
                </div>
            </td>

            <td data-label="Status">
                @if($site->verified)
                    <span class="site-status site-status--verified"
                          data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Verified">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>Verified
                    </span>
                @elseif($site->active)
                    <span class="site-status site-status--active"
                          data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Active">
                        <i class="fa-solid fa-circle-play" aria-hidden="true"></i>Active
                    </span>
                @else
                    <span class="site-status site-status--pending"
                          data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Pending">
                        <i class="fa-regular fa-clock" aria-hidden="true"></i>Pending
                    </span>
                @endif
            </td>

            <td data-label="Price">
                <span class="site-row-price">€{{ number_format((float) $site->price, 2) }}</span>
                <span class="site-row-price-meta">
                    @if($site->isFeatured())
                        <span class="badge bg-warning text-dark"
                              data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Featured">★</span>
                    @endif
                    @if($site->hasActiveCustomDiscount())
                        <span class="badge bg-danger"
                              data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Discount">−{{ rtrim(rtrim(number_format((float)$site->custom_discount_percent,1),'0'),'.') }}%</span>
                    @endif
                    @if($site->joinsBulkDiscount())
                        <span class="badge bg-success"
                              data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Bulk">Bulk</span>
                    @endif
                </span>
            </td>

            <td data-label="Actions" class="text-end">
                <div class="site-row-actions">
                <button type="button" class="btn-icon-quiet action-view" data-id="{{ $site->id }}"
                        aria-label="View"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="View">
                    <i class="fa fa-eye" aria-hidden="true"></i>
                </button>

                @php
                    $editPayload = $site->only([
                        'id', 'site_name', 'site_url', 'example_url', 'da', 'dr', 'traffic', 'price',
                        'turnaround_time', 'publication_time', 'link_type', 'sponsored', 'partner_material',
                        'as_you_prefer', 'sensitive_prices', 'language', 'languages', 'country', 'countries',
                        'categories', 'category', 'description',
                    ]);
                @endphp
                <button type="button" class="btn btn-sm btn-primary btn-edit" data-site='@json($editPayload)'
                        aria-label="Edit"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Edit">
                    Edit
                </button>

                @if($site->active || $site->verified)
                <button type="button" class="btn-icon-quiet btn-feature-site {{ $site->isFeatured() ? 'is-on' : '' }}"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        aria-label="{{ $site->isFeatured() ? 'Featured' : 'Feature' }}"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip"
                        data-bs-title="{{ $site->isFeatured() ? 'Featured' : 'Feature' }}">
                    <i class="fa fa-bolt" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn-icon-quiet btn-discount-site {{ $site->hasActiveCustomDiscount() ? 'is-on' : '' }}"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        data-percent="{{ $site->custom_discount_percent }}"
                        data-ends="{{ optional($site->custom_discount_ends_at)?->toIso8601String() }}"
                        aria-label="{{ $site->hasActiveCustomDiscount() ? 'Timed discount active' : 'Set timed discount' }}"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip"
                        data-bs-title="{{ $site->hasActiveCustomDiscount() ? 'Timed discount active' : 'Set timed discount' }}">
                    <i class="fa fa-percent" aria-hidden="true"></i>
                </button>
                @if($site->hasActiveCustomDiscount())
                <button type="button" class="btn-text-quiet is-danger btn-discount-clear"
                        data-id="{{ $site->id }}"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Clear discount">
                    Clear
                </button>
                @endif
                @if($site->joinsBulkDiscount())
                <button type="button" class="btn-icon-quiet is-on btn-bulk-leave"
                        data-id="{{ $site->id }}"
                        aria-label="Leave bulk"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Leave bulk">
                    <i class="fa fa-layer-group" aria-hidden="true"></i>
                </button>
                @else
                <button type="button" class="btn-icon-quiet btn-bulk-join"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        aria-label="Bulk"
                        data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Bulk">
                    <i class="fa fa-layer-group" aria-hidden="true"></i>
                </button>
                @endif
                @endif

                @if(!$site->verified && !$site->active)
                <form action="{{ route('publisher.sites.destroy', $site->id) }}" method="POST" class="d-inline delete-form">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="btn-icon-quiet btn-delete"
                            aria-label="Delete"
                            data-bs-toggle="tooltip" data-bs-custom-class="yt-tooltip" data-bs-title="Delete">
                        <i class="fa fa-trash" aria-hidden="true"></i>
                    </button>
                </form>
                @endif
                </div>
            </td>
        </tr>

        <tr class="expand-row" id="expand-{{ $site->id }}">
            <td colspan="7">
                <div class="expand-box">
                    <div class="detail-line">
                        <strong>Example URL:</strong>
                        <a href="{{ $site->example_url }}" target="_blank" rel="noopener noreferrer">{{ $site->example_url }}</a>
                    </div>

                    <div class="detail-line">
                        <strong>Publication Duration:</strong> {{ getPublicationDuration($site->publication_time) }}
                    </div>

                    <div class="detail-line">
                        <strong>Link Type:</strong> {{ ucfirst($site->link_type) }}
                    </div>

                    <div class="detail-line">
                        <strong>Turnaround Time:</strong>
                        <span class="turnaround-badge {{ getTurnaroundClass($site->turnaround_time ?? '3days') }}">
                            {{ getTurnaroundLabel($site->turnaround_time ?? '3days') }}
                        </span>
                    </div>

                    <div class="detail-line">
                        <strong>Tags:</strong>
                        @if($site->sponsored)
                            <span class="tag-badge">Sponsored</span>
                        @endif
                        @if($site->partner_material)
                            <span class="tag-badge">Partner Material</span>
                        @endif
                        @if($site->as_you_prefer)
                            <span class="tag-badge">As You Prefer</span>
                        @endif
                        @if(!$site->sponsored && !$site->partner_material && !$site->as_you_prefer)
                            <span class="text-muted">No tags</span>
                        @endif
                    </div>

                    @if($site->sensitive_prices)
                        <div class="detail-line">
                            <strong>Sensitive Topics:</strong>
                            @php
                                $prices = is_array($site->sensitive_prices)
                                    ? $site->sensitive_prices
                                    : (is_string($site->sensitive_prices) ? json_decode($site->sensitive_prices, true) : []);
                            @endphp
                            @foreach($prices as $key => $value)
                                <span class="sensitive-badge">{{ ucfirst($key) }}: €{{ number_format($value, 2) }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="desc-box">
                        <strong>Description:</strong>
                        <div>{!! $site->safeDescriptionHtml() !!}</div>
                    </div>
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>

@if($sites->hasPages())
<div class="d-flex justify-content-center mt-3">
    {{ $sites->links() }}
</div>
@endif

@else
<div class="alert alert-light border text-center mb-0">
    @if(($status ?? 'active') === 'active')
        <i class="fa fa-circle-check me-2 text-success"></i> No active sites yet. Approved sites will show here.
    @else
        <i class="fa fa-clock me-2 text-muted"></i> No pending sites waiting for admin approval.
    @endif
</div>
@endif
