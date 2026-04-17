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
</style>

@php
    function countryName($code) {
        return [
            'us' => 'United States',
            'uk' => 'United Kingdom',
            'de' => 'Germany',
            'pk' => 'Pakistan'
        ][$code] ?? strtoupper($code);
    }

    function languageName($code) {
        return [
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'ur' => 'Urdu'
        ][$code] ?? strtoupper($code);
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
            <th>Country</th>
            <th>Language</th>
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
            <td>{{ number_format($site->price, 2) }}</td>
            <td>{{ countryName($site->country) }}</td>
            <td>{{ languageName($site->language) }}</td>
            <td>
    @if($site->verified)
        <span class="badge bg-success" data-bs-toggle="tooltip" title="Site is active and listed">
            Active
        </span>
    @elseif($site->active)
        <span class="badge bg-info" data-bs-toggle="tooltip" title="Site is active but not listed">
            Active
        </span>
    @else
        <span class="badge bg-secondary" data-bs-toggle="tooltip" title="Site is pending review">
            Pending
        </span>
    @endif
</td>
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
            <td colspan="12">
                <div class="expand-box">
                    <div class="detail-line">
                        <strong>Example URL:</strong>
                        <a href="{{ $site->example_url }}" target="_blank">{{ $site->example_url }}</a>
                    </div>

                    <div class="detail-line">
                        <strong>Country:</strong> {{ countryName($site->country) }}
                    </div>

                    <div class="detail-line">
                        <strong>Language:</strong> {{ languageName($site->language) }}
                    </div>

                    <div class="detail-line">
                        <strong>Publication Duration:</strong> {{ ucfirst($site->publication_time) }}
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
                    </div>

                    @if($site->sensitive_prices)
                        @php $prices = json_decode($site->sensitive_prices, true); @endphp
                        <div class="detail-line">
                            <strong>Sensitive Topics:</strong>
                            @foreach($prices as $key => $value)
                                <span class="sensitive-badge">{{ ucfirst($key) }}: €{{ $value }}</span>
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
<ul class="pagination">
    @foreach($sites->links()->elements[0] as $page => $url)
        <li class="{{ $sites->currentPage() == $page ? 'active' : '' }}" data-page="{{ $page }}">
            {{ $page }}
        </li>
    @endforeach
</ul>

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

    // ✅ EDIT BUTTON FIXED
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

        // ✅ Add readonly message (only once)
        if (!siteNameInput.next('.readonly-note').length) {
            siteNameInput.after('<small class="text-muted readonly-note">Due to security reasons, this field is readonly</small>');
        }
        if (!siteUrlInput.next('.readonly-note').length) {
            siteUrlInput.after('<small class="text-muted readonly-note">Due to security reasons, this field is readonly</small>');
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
        $('input[name="sponsored"]').prop('checked', site.sponsored);
        $('input[name="partner_material"]').prop('checked', site.partner_material);
        $('input[name="as_you_prefer"]').prop('checked', site.as_you_prefer);

        // Sensitive topics
        if(site.sensitive_prices){
            const prices = JSON.parse(site.sensitive_prices);
            for(const key in prices){
                $('input[name="sensitive['+key+']"]').prop('checked', true);
                $('input[name="price_sensitive['+key+']"]').val(prices[key]);
            }
        }

        // Description
        quill.root.innerHTML = site.description;

        $('#submitBtn').prop('disabled', false).text('Update');

        $('html, body').animate({
            scrollTop: $("#formCard").offset().top - 100
        }, 500);
    });

    // ✅ CLOSE RESET FIXED
    $('#closeBtn').click(function(){
        $('#formCard').addClass('d-none');
        $('#showFormBtn').removeClass('d-none');
        $('#closeBtn').addClass('d-none');
        $('#formHeader').text('Add New Website');

        $('#addSiteForm')[0].reset();
        $('#methodField').remove();

        quill.root.innerHTML = '';
        $('.tag-checkbox').prop('checked', false);

        $('#submitBtn').text('Submit');

        // ✅ Re-enable fields
        $('input[name="siteName"], input[name="siteUrl"]').prop('disabled', false);

        // ✅ Remove readonly notes
        $('.readonly-note').remove();
    });

});
</script>

@else
<p>No sites found.</p>
@endif