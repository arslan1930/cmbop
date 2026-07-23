<div id="sitesStatusMeta"
     data-pending="{{ (int) ($pendingCount ?? 0) }}"
     data-active="{{ (int) ($activeCount ?? 0) }}"
     data-status="{{ $status ?? 'pending' }}"
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
        min-width: 920px;
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
        width: 72px;
        height: 48px;
        border-radius: 8px;
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
        box-shadow: 0 0 0 2px rgba(24, 80, 84, 0.12);
    }

    .site-row-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .site-row-preview.is-empty {
        color: #94a3b8;
        font-size: 15px;
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
        color: #64748b;
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
        color: #64748b;
    }

    .site-row-market .country-flag {
        font-size: 16px;
        line-height: 1;
    }

    .site-row-actions {
        display: inline-flex;
        flex-wrap: nowrap;
        align-items: center;
        gap: 4px;
        justify-content: flex-end;
    }

    .site-row-actions .btn {
        white-space: nowrap;
    }

    .site-row-actions .btn-icon {
        width: 32px;
        height: 32px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
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
            <th style="width:72px;">Preview</th>
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
                       class="site-row-preview" title="Open screenshot"
                       onclick="event.stopPropagation();">
                        <img src="{{ $previewUrl }}"
                             alt="{{ $site->site_name }} preview"
                             loading="lazy"
                             onerror="this.onerror=null; this.parentElement.classList.add('is-empty'); this.parentElement.innerHTML='<i class=\'fa fa-image\' aria-hidden=\'true\'></i>';">
                    </a>
                @else
                    <span class="site-row-preview is-empty" title="No screenshot yet" aria-label="No screenshot">
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
                    <span class="badge bg-success status-badge" data-bs-toggle="tooltip" title="Site is verified and active">
                        <i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Verified
                    </span>
                @elseif($site->active)
                    <span class="badge bg-info status-badge" data-bs-toggle="tooltip" title="Site is active but not verified">
                        <i class="fa-solid fa-circle-play me-1" aria-hidden="true"></i>Active
                    </span>
                @else
                    <span class="badge bg-secondary status-badge" data-bs-toggle="tooltip" title="Site is pending review">
                        <i class="fa-regular fa-clock me-1" aria-hidden="true"></i>Pending
                    </span>
                @endif
            </td>

            <td data-label="Price">
                <span class="site-row-price">€{{ number_format((float) $site->price, 2) }}</span>
                <span class="site-row-price-meta">
                    @if($site->isFeatured())
                        <span class="badge bg-warning text-dark" title="Featured">★</span>
                    @endif
                    @if($site->hasActiveCustomDiscount())
                        <span class="badge bg-danger" title="Discount">−{{ rtrim(rtrim(number_format((float)$site->custom_discount_percent,1),'0'),'.') }}%</span>
                    @endif
                    @if($site->joinsBulkDiscount())
                        <span class="badge bg-success" title="Bulk discount">Bulk</span>
                    @endif
                </span>
            </td>

            <td data-label="Actions" class="text-end">
                <div class="site-row-actions">
                <button type="button" class="btn btn-sm btn-outline-primary action-view btn-icon" data-id="{{ $site->id }}" aria-label="View {{ $site->site_name }}" title="View details">
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
                <button type="button" class="btn btn-sm btn-primary btn-edit" data-site='@json($editPayload)' aria-label="Edit {{ $site->site_name }}" title="Edit">
                    Edit
                </button>

                @if($site->active || $site->verified)
                <button type="button" class="btn btn-sm btn-warning btn-feature-site btn-icon"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        title="Feature this site for 7 days (€10)"
                        aria-label="Feature {{ $site->site_name }}">
                    <i class="fa fa-bolt" aria-hidden="true"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-success btn-discount-site btn-icon"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        data-percent="{{ $site->custom_discount_percent }}"
                        data-ends="{{ optional($site->custom_discount_ends_at)?->toIso8601String() }}"
                        title="Set a timed discount"
                        aria-label="Discount {{ $site->site_name }}">
                    <i class="fa fa-percent" aria-hidden="true"></i>
                </button>
                @if($site->hasActiveCustomDiscount())
                <button type="button" class="btn btn-sm btn-outline-danger btn-discount-clear"
                        data-id="{{ $site->id }}"
                        title="End discount now">
                    Clear
                </button>
                @endif
                @if($site->joinsBulkDiscount())
                <button type="button" class="btn btn-sm btn-outline-secondary btn-bulk-leave"
                        data-id="{{ $site->id }}" title="Leave bulk program">Leave</button>
                @else
                <button type="button" class="btn btn-sm btn-outline-success btn-bulk-join"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        title="Join bulk discount">Bulk</button>
                @endif
                @endif

                @if(!$site->verified && !$site->active)
                <form action="{{ route('publisher.sites.destroy', $site->id) }}" method="POST" class="d-inline delete-form">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="btn btn-sm btn-danger btn-delete btn-icon" aria-label="Delete {{ $site->site_name }}" title="Delete">
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
                    @if($fullPreviewUrl)
                        <div class="detail-line mb-3">
                            <strong>Screenshot:</strong>
                            <div class="mt-2">
                                <img src="{{ $fullPreviewUrl }}" alt="{{ $site->site_name }} screenshot"
                                     style="max-width:min(420px,100%);border-radius:10px;border:1px solid #e2e8f0;"
                                     loading="lazy">
                            </div>
                        </div>
                    @endif

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
    @if(($status ?? 'pending') === 'active')
        <i class="fa fa-circle-check me-2 text-success"></i> No active sites yet. Approved sites will show here.
    @else
        <i class="fa fa-clock me-2 text-muted"></i> No pending sites waiting for admin approval.
    @endif
</div>
@endif
