@if($sites->count() > 0)
<style>
    .modern-table {
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid #eee;
        text-align: center;
    }

    .modern-table th, .modern-table td {
        vertical-align: middle !important;
    }

    .modern-table thead {
        background: #343a40;
        color: #fff;
        text-align: center;
    }

    .modern-table tbody tr {
        cursor: default;
        transition: background 0.2s ease;
    }

    .modern-table tbody tr:hover {
        background: #f7fbff;
    }

    .expand-row {
        background: #fafafa;
        transition: all 0.3s ease-in-out;
    }

    .expand-row td {
        padding: 0 !important;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
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
    
    .country-flag {
        font-size: 24px;
        display: block;
        margin-bottom: 5px;
    }
    
    .language-name {
        font-size: 12px;
        color: #666;
    }
    
    /* Turnaround Time Badge Styles */
    .turnaround-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 600;
    background-color: #f1f1f1; /* light gray */
    color: #282828;
}
    
    .turnaround-24h,
.turnaround-48h,
.turnaround-3days,
.turnaround-5days,
.turnaround-7days {
    background-color: #f1f1f1;
    color: #282828;
}

/* Edit form styles */
#turnaroundTime {
    display: inline-block;
    width: auto;
    margin-top: 5px;
}
</style>

@php
    if (!function_exists('getCountryFlag')) {
        function getCountryFlag($countryCode) {
            $code = strtoupper(trim((string) $countryCode));
            if (strlen($code) !== 2) return '';
            if ($code === 'UK') $code = 'GB';
            $flag = mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
            return $flag;
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

<table class="table table-striped modern-table sites-responsive-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Site Name</th>
            <th>URL</th>
            <th>Category</th>
            <th>DA</th>
            <th>DR</th>
            <th>Traffic</th>
            <th>Country / Language</th>
            <th>Status</th>
            <th>Price (€)</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sites as $index => $site)
        <tr class="main-row" data-id="{{ $site->id }}">
            <td data-label="#">{{ $sites->firstItem() + $index }}</td>
            <td data-label="Site">{{ $site->site_name }}</td>
            <td data-label="URL">{{ $site->site_url }}</td>
            <td data-label="Category">{{ ucfirst($site->category) }}</td>
            <td data-label="DA">{{ $site->da }}</td>
            <td data-label="DR">{{ $site->dr }}</td>
            <td data-label="Traffic">{{ number_format($site->traffic, 0, '.', ',') }}</td>

            <!-- Country Flag + Language Combined Column -->
            <td data-label="Market">
                <div class="d-flex flex-column align-items-md-center gap-1">
                    <span class="country-flag" aria-hidden="true">
                        @php
                            $siteCountries = is_array($site->countries) && count($site->countries)
                                ? $site->countries
                                : array_filter([$site->country]);
                        @endphp
                        @foreach($siteCountries as $code)
                            {!! getCountryFlag($code) !!}
                        @endforeach
                    </span>
                    <span class="language-name">
                        @php
                            $siteLanguages = is_array($site->languages) && count($site->languages)
                                ? $site->languages
                                : array_filter([$site->language]);
                        @endphp
                        {{ collect($siteLanguages)->map(fn ($c) => getLanguageName($c))->implode(', ') }}
                    </span>
                </div>
            </td>
            
            <!-- Status Column — icon + text (A3) -->
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

            <!-- Price Column -->
            <td data-label="Price">
                €{{ number_format($site->price, 2) }}
                @if($site->isFeatured())
                    <div><span class="badge bg-warning text-dark mt-1">Featured</span></div>
                @endif
                @if($site->hasActiveCustomDiscount())
                    <div><span class="badge bg-danger mt-1">−{{ rtrim(rtrim(number_format((float)$site->custom_discount_percent,1),'0'),'.') }}% offer</span></div>
                @endif
                @if($site->joinsBulkDiscount())
                    <div><span class="badge bg-success mt-1">Bulk −{{ rtrim(rtrim(number_format((float)$site->bulk_discount_percent,1),'0'),'.') }}%</span></div>
                @endif
            </td>
            
            <!-- Actions Column -->
            <td data-label="Actions">
                <div class="d-flex flex-wrap gap-1 justify-content-center">
                <!-- View button -->
                <button class="btn btn-sm btn-outline-primary action-view" data-id="{{ $site->id }}" aria-label="View {{ $site->site_name }}">
                    <i class="fa fa-eye me-1" aria-hidden="true"></i><span class="btn-text">View</span>
                </button>

                <!-- Edit button -->
                <button class="btn btn-sm btn-primary btn-edit" data-site='@json($site)' aria-label="Edit {{ $site->site_name }}">
                    Edit
                </button>

                @if($site->active || $site->verified)
                <button type="button" class="btn btn-sm btn-warning btn-feature-site"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        title="Feature this site for 7 days (€10)">
                    <i class="fa fa-bolt"></i> Feature
                </button>
                <button type="button" class="btn btn-sm btn-outline-success btn-discount-site"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}"
                        data-percent="{{ $site->custom_discount_percent }}"
                        data-ends="{{ optional($site->custom_discount_ends_at)?->toIso8601String() }}"
                        title="Set a timed discount">
                    <i class="fa fa-percent"></i> Discount
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
                        data-id="{{ $site->id }}">Leave bulk</button>
                @else
                <button type="button" class="btn btn-sm btn-outline-success btn-bulk-join"
                        data-id="{{ $site->id }}"
                        data-name="{{ $site->site_name }}">Join bulk</button>
                @endif
                @endif

                <!-- Delete button (only if pending) -->    
                @if(!$site->verified && !$site->active)
                <form action="{{ route('publisher.sites.destroy', $site->id) }}" method="POST" style="display:inline-block;" class="delete-form">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="btn btn-sm btn-danger btn-delete" aria-label="Delete {{ $site->site_name }}">
                        Delete
                    </button>
                </form>
                @endif
                </div>
            </td>
        </tr>

        <!-- Expand Row -->
        <tr class="expand-row" id="expand-{{ $site->id }}">
            <td colspan="12">
                <div class="expand-box">
                    <div class="detail-line">
                        <strong>Example URL:</strong>
                        <a href="{{ $site->example_url }}" target="_blank">{{ $site->example_url }}</a>
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

<!-- Pagination -->
@if($sites->hasPages())
<ul class="pagination">
    {{ $sites->links() }}
</ul>
@endif

<!-- SweetAlert2 for delete and edit -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function(){

    // Expand row toggle
    $(document).on('click', '.action-view', function(e) {
        e.stopPropagation();
        let id = $(this).data('id');
        let expandRow = $('#expand-' + id);
        expandRow.toggleClass('expanded');

        let icon = $(this).find('i');
        let text = $(this).find('.btn-text');
        if (expandRow.hasClass('expanded')) {
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
            text.text('Hide');
        } else {
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
            text.text('View');
        }
    });

    // Delete confirmation
    $(document).on('click', '.btn-delete', function(e) {
        let form = $(this).closest('form');
        Swal.fire({
            title: 'Are you sure?',
            text: "This site will be deleted permanently!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });

    // EDIT BUTTON with Turnaround Time
    $(document).on('click', '.btn-edit', function() {
        const site = $(this).data('site');

        $('#formCard').removeClass('d-none');
        $('#showFormBtn').addClass('d-none');
        $('#closeBtn').removeClass('d-none');
        $('#formHeader').text('Edit Site: ' + site.site_name);

        // Fix duplicate method field
        $('#methodField').remove();
        $('#addSiteForm')
            .attr('action', '/publisher/sites/' + site.id)
            .append('<input type="hidden" name="_method" value="PUT" id="methodField">');

        // Prefill fields
        const siteNameInput = $('input[name="siteName"]');
        const siteUrlInput = $('input[name="siteUrl"]');

        siteNameInput.val(site.site_name).prop('disabled', true);
        siteUrlInput.val(site.site_url).prop('disabled', true);

        // Add readonly message (only once)
        if (!siteNameInput.next('.readonly-note').length) {
            siteNameInput.after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
        }
        if (!siteUrlInput.next('.readonly-note').length) {
            siteUrlInput.after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
        }

        $('input[name="exampleUrl"]').val(site.example_url);
        $('input[name="da"]').val(site.da);
        $('input[name="dr"]').val(site.dr);
        $('input[name="traffic"]').val(site.traffic);
        $('input[name="price"]').val(site.price);
        
        // Set Turnaround Time
        $('#turnaroundTime').val(site.turnaround_time || '3days');
        
        $('select[name="country"]').val(site.country);
        $('select[name="language"]').val(site.language);
        $('select[name="category"]').val(site.category);
        $('select[name="publicationTime"]').val(site.publication_time);
        $('input[name="link_type"][value="' + site.link_type + '"]').prop('checked', true);

        // Tags (single radio on add/edit form)
        let siteTag = '';
        if (site.sponsored == 1) siteTag = 'sponsored';
        else if (site.partner_material == 1) siteTag = 'partner_material';
        else if (site.as_you_prefer == 1) siteTag = 'as_you_prefer';
        $(`input[name="site_tag"][value="${siteTag}"]`).prop('checked', true);
        if (!siteTag) $('#tagNone').prop('checked', true);

        // Sensitive topics
        if(site.sensitive_prices){
            let prices = site.sensitive_prices;
            // Handle both array and string JSON
            if (typeof prices === 'string') {
                prices = JSON.parse(prices);
            }
            for(const key in prices){
                $('input[name="sensitive['+key+']"]').prop('checked', true);
                $('input[name="price_sensitive['+key+']"]').val(prices[key]);
            }
        } else {
            $('.sensitive-checkbox').prop('checked', false);
            $('.sensitive-price').val('');
        }

        // Description
        if (typeof quill !== 'undefined') {
            quill.root.innerHTML = site.description || '';
        }

        $('#submitBtn').prop('disabled', false).text('Update');

        $('html, body').animate({
            scrollTop: $("#formCard").offset().top - 100
        }, 500);
    });

    // CLOSE RESET
    $('#closeBtn').click(function(){
        $('#formCard').addClass('d-none');
        $('#showFormBtn').removeClass('d-none');
        $('#closeBtn').addClass('d-none');
        $('#formHeader').text('Add New Website');

        $('#addSiteForm')[0].reset();
        $('#methodField').remove();

        if (typeof quill !== 'undefined') {
            quill.root.innerHTML = '';
        }
        $('.tag-checkbox').prop('checked', false);
        $('.sensitive-checkbox').prop('checked', false);
        $('.sensitive-price').val('');
        
        // Reset turnaround time to default
        $('#turnaroundTime').val('3days');

        $('#submitBtn').text('Submit').prop('disabled', false);

        // Re-enable fields
        $('input[name="siteName"], input[name="siteUrl"]').prop('disabled', false);

        // Remove readonly notes
        $('.readonly-note').remove();
    });

});
</script>

@else
<div class="alert alert-info text-center">
    <i class="fa fa-info-circle me-2"></i> No sites found. Click "Add New Website" to get started.
</div>
@endif