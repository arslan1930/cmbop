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
        color: #0d6efd;
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
</style>

@php
    function getCountryFlag($countryCode) {
        $code = strtoupper($countryCode);
        if ($code === 'UK') $code = 'GB';
        $flag = mb_convert_encoding('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', 'UTF-8', 'HTML-ENTITIES');
        return $flag;
    }
    
    function getLanguageName($code) {
        $languages = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ru' => 'Russian',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic',
            'hi' => 'Hindi', 'tr' => 'Turkish', 'pl' => 'Polish', 'uk' => 'Ukrainian',
            'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
            'el' => 'Greek', 'cs' => 'Czech', 'hu' => 'Hungarian', 'ro' => 'Romanian',
            'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sk' => 'Slovak', 'sl' => 'Slovenian',
            'lt' => 'Lithuanian', 'lv' => 'Latvian', 'et' => 'Estonian', 'he' => 'Hebrew',
            'th' => 'Thai', 'vi' => 'Vietnamese', 'id' => 'Indonesian', 'ms' => 'Malay',
        ];
        return $languages[strtolower($code)] ?? strtoupper($code);
    }
    
    function getPublicationDuration($value) {
        $durations = [
            '6months' => '6 Months',
            '1year' => '1 Year',
            'permanent' => 'Permanent'
        ];
        return $durations[$value] ?? ucfirst($value);
    }
@endphp

<table class="table table-striped modern-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Site Name</th>
            <th>URL</th>
            <th>Category</th>
            <th>DA</th>
            <th>DR</th>
            <th>Traffic</th>
            <th>Price (€)</th>
            <th>Country / Language</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sites as $index => $site)
        <tr class="main-row" data-id="{{ $site->id }}">
            <td>{{ $sites->firstItem() + $index }}</td>
            <td>{{ $site->site_name }}</td>
            <td>{{ $site->site_url }}</td>
            <td>{{ ucfirst($site->category) }}</td>
            <td>{{ $site->da }}</td>
            <td>{{ $site->dr }}</td>
            <td>{{ number_format($site->traffic, 0, '.', ',') }}</td>
            <td>€{{ number_format($site->price, 2) }}</td>
            
            <!-- Country Flag + Language Combined Column -->
            <td>
                <div class="d-flex flex-column align-items-center">
                    <span class="country-flag">{!! getCountryFlag($site->country) !!}</span>
                    <span class="language-name">{{ getLanguageName($site->language) }}</span>
                </div>
            </td>
            
            <!-- Status Column -->
            <td>
                @if($site->verified)
                    <span class="badge bg-success" data-bs-toggle="tooltip" title="Site is verified and active">
                        Verified
                    </span>
                @elseif($site->active)
                    <span class="badge bg-info" data-bs-toggle="tooltip" title="Site is active but not verified">
                        Active
                    </span>
                @else
                    <span class="badge bg-secondary" data-bs-toggle="tooltip" title="Site is pending review">
                        Pending
                    </span>
                @endif
            </td>
            
            <!-- Actions Column -->
            <td>
                <!-- View button -->
                <button class="btn btn-sm btn-outline-primary action-view" data-id="{{ $site->id }}">
                    <i class="fa fa-eye me-1"></i><span class="btn-text">View</span>
                </button>

                <!-- Edit button -->
                <button class="btn btn-sm btn-primary btn-edit" data-site='@json($site)'>
                    Edit
                </button>

                <!-- Delete button (only if pending) -->    
                @if(!$site->verified && !$site->active)
                <form action="{{ route('publisher.sites.destroy', $site->id) }}" method="POST" style="display:inline-block;" class="delete-form">
                    @csrf
                    @method('DELETE')
                    <button type="button" class="btn btn-sm btn-danger btn-delete">
                        Delete
                    </button>
                </form>
                @endif
            </td>
        </tr>

        <!-- Expand Row -->
        <tr class="expand-row" id="expand-{{ $site->id }}">
            <td colspan="11">
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
                        <div>{!! $site->description !!}</div>
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

    // EDIT BUTTON
    $(document).on('click', '.btn-edit', function() {
        const site = $(this).data('site');

        $('#formCard').removeClass('d-none');
        $('#showFormBtn').addClass('d-none');
        $('#closeBtn').removeClass('d-none');
        $('#formHeader').text('Edit Site: ' + site.domain);

        // Fix duplicate method field
        $('#methodField').remove();
        $('#addSiteForm')
            .attr('action', '/publisher/sites/' + site.id)
            .append('<input type="hidden" name="_method" value="PUT" id="methodField">');

        // Prefill
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
        $('select[name="country"]').val(site.country);
        $('select[name="language"]').val(site.language);
        $('select[name="category"]').val(site.category);
        $('select[name="publicationTime"]').val(site.publication_time);
        $('input[name="link_type"][value="' + site.link_type + '"]').prop('checked', true);

        // Tags
        $('input[name="sponsored"]').prop('checked', site.sponsored == 1);
        $('input[name="partner_material"]').prop('checked', site.partner_material == 1);
        $('input[name="as_you_prefer"]').prop('checked', site.as_you_prefer == 1);

        // Sensitive topics - FIXED
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
        }

        // Description
        if (typeof quill !== 'undefined') {
            quill.root.innerHTML = site.description;
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

        $('#submitBtn').text('Submit');

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