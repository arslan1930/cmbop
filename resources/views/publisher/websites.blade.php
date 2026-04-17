@extends('publisher.layouts.app')

@section('content')
<style>
    body {
        font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
        background-color: #f6f9fc;
        color: #32325d;
    }

    .card {
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(50, 50, 93, 0.1), 0 2px 6px rgba(0, 0, 0, 0.08);
        background-color: #ffffff;
        border: none;
    }

    .card-body {
        padding: 30px;
    }

    .form-section {
        margin-bottom: 28px;
        padding-bottom: 18px;
        border-bottom: 1px solid #e6ebf1;
    }

    .form-section:last-child {
        border-bottom: none;
    }

    .form-label {
        font-weight: 600;
        font-size: 13px;
        margin-bottom: 6px;
        color: #32325d;
    }

    .form-control, .form-select {
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 14px;
        border: 1px solid #dfe3e8;
        transition: all 0.2s ease;
        background-color: #f6f9fc;
    }

    .form-control:focus, .form-select:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 2px rgba(84, 105, 212, 0.15);
        background-color: #fff;
    }

    #showFormBtn {
        border-radius: 8px;
        padding: 10px 16px;
        font-weight: 500;
    }

    .btn-success, .btn-close-form {
        border-radius: 8px;
        padding: 10px 18px;
        font-weight: 500;
    }

    .bg-light {
        background-color: #f6f9fc !important;
        border: 1px solid #e6ebf1;
    }

    .form-check-label {
        font-size: 14px;
        color: #525f7f;
    }

    #quillEditor {
        border-radius: 8px;
        border: 1px solid #dfe3e8;
    }

    .text-danger {
        font-size: 14px;
    }

    .site-not-editable {
        font-size: 12px;
        color: #8898aa;
    }

    .table-search {
        margin-bottom: 15px;
        max-width: 300px;
        border-radius: 8px;
        border: 1px solid #dfe3e8;
        padding: 8px 12px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background-color: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(50,50,93,0.05);
    }

    th, td {
        padding: 12px 15px;
        border-bottom: 1px solid #e6ebf1;
        text-align: left;
    }

    th {
        background-color: #f6f9fc;
        font-weight: 600;
        color: #525f7f;
    }

    .pagination {
        list-style: none;
        display: flex;
        gap: 5px;
        padding: 0;
        margin-top: 10px;
    }

    .pagination li {
        cursor: pointer;
        padding: 6px 12px;
        border: 1px solid #dfe3e8;
        border-radius: 4px;
        color: #525f7f;
        transition: all 0.2s ease;
    }

    .pagination li.active {
        background-color: #007bff;
        color: white;
        border-color: #007bff;
    }

    #formCard {
        transition: all 0.2s ease;
    }

    #sitesTableWrapper {
        min-height: 80px;
    }

    .btn-primary {
        background-color: #007bff;
        border-color: #007bff;
    }

    .btn-primary:hover {
        background-color: #4353b3;
        border-color: #4353b3;
    }

    .btn-success {
        background-color: #00b87c;
        border-color: #00b87c;
    }

    .btn-success:hover {
        background-color: #009e66;
        border-color: #009e66;
    }
    
    .loading-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-right: 5px;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    @media (max-width: 768px) {
        #sitesTableWrapper {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 70vh;
            -webkit-overflow-scrolling: touch;
        }

        #sitesTableWrapper table {
            min-width: 900px;
        }
    }
</style>

<div class="container-fluid">
    <h3 class="mb-4"><span id="formHeader">Add New Website</span></h3>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible fade show">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <button id="showFormBtn" class="btn btn-primary mb-3 shadow-sm">
        <i class="fa fa-plus"></i> Add New Website
    </button>

    <div class="card shadow-sm border-0 d-none" id="formCard">
        <div class="card-body">
            <form id="addSiteForm" class="needs-validation" novalidate method="POST" action="{{ route('publisher.sites.store') }}">
                @csrf
                <input type="hidden" name="_method" id="methodField" value="POST">

                <!-- Row 1 -->
                <div class="form-section">
                    <div class="row">
                        <div class="col-md-4"> 
                            <label class="form-label">Site Name <span class="text-danger">*</span></label>
                            <input type="text" name="siteName" id="siteName" class="form-control" placeholder="Enter site name" value="{{ old('siteName') }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Site URL <span class="text-danger">*</span></label>
                            <input type="url" name="siteUrl" id="siteUrl" class="form-control" placeholder="eg:https://example.com" value="{{ old('siteUrl') }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Example URL <span class="text-danger">*</span></label>
                            <input type="url" name="exampleUrl" id="exampleUrl" class="form-control" placeholder="https://example.com/example" value="{{ old('exampleUrl') }}" required>
                        </div>
                    </div>
                </div>

                <!-- Row 2 -->
                <div class="form-section">
                    <div class="row bg-light p-3 rounded">
                        <div class="col-md-2">
                            <label class="form-label">DA (Domain Authority) <span class="text-danger">*</span></label>
                            <input type="number" name="da" id="da" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old('da') }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">DR (Domain Rating) <span class="text-danger">*</span></label>
                            <input type="number" name="dr" id="dr" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old('dr') }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Traffic <span class="text-danger">*</span></label>
                            <input type="number" name="traffic" id="traffic" class="form-control" placeholder="Visitors/month" value="{{ old('traffic') }}" required>
                        </div>
                    </div>
                </div>

                <!-- Row 3 -->
                <div class="form-section">
                    <div class="row bg-light p-3 rounded">
                        <div class="col-md-2">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <select name="country" id="countrySelect" class="form-select" required>
                                <option value="">Select Country</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->code }}" {{ old('country') == $country->code ? 'selected' : '' }}>
                                        {{ $country->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Language <span class="text-danger">*</span></label>
                            <select name="language" id="languageSelect" class="form-select" required>
                                <option value="">Select Language</option>
                                @foreach($languages as $language)
                                    <option value="{{ $language->code }}" {{ old('language') == $language->code ? 'selected' : '' }}>
                                        {{ $language->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" id="category" class="form-select" required>
                                <option value="">Select Category</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->name }}" {{ old('category') == $category->name ? 'selected' : '' }}>
                                        {{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Price (€) <span class="text-danger">*</span></label>
                            <input type="number" name="price" id="price" class="form-control" placeholder="Enter price" min="0" step="0.01" value="{{ old('price') }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Publication Duration <span class="text-danger">*</span></label>
                            <select name="publicationTime" id="publicationTime" class="form-select" required>
                                <option value="">Select Duration</option>
                                <option value="6months" {{ old('publicationTime') == '6months' ? 'selected' : '' }}>6 Months</option>
                                <option value="1year" {{ old('publicationTime') == '1year' ? 'selected' : '' }}>1 Year</option>
                                <option value="permanent" {{ old('publicationTime') == 'permanent' ? 'selected' : '' }}>Permanent</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Link Type <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input type="radio" name="link_type" id="linkTypeDofollow" value="dofollow" class="form-check-input" {{ old('link_type', 'dofollow') == 'dofollow' ? 'checked' : '' }}>
                                    <label class="form-check-label">DoFollow</label>
                                </div>
                                <div class="form-check">
                                    <input type="radio" name="link_type" id="linkTypeNofollow" value="nofollow" class="form-check-input" {{ old('link_type') == 'nofollow' ? 'checked' : '' }}>
                                    <label class="form-check-label">NoFollow</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Tags -->
                <div class="form-section">
                    <div class="row bg-light p-3 rounded">
                        <label class="form-label">Tags (Optional, select only one)</label>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input type="checkbox" name="sponsored" id="sponsored" class="form-check-input tag-checkbox" value="1" {{ old('sponsored') ? 'checked' : '' }}>
                                <label class="form-check-label">Sponsored</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input type="checkbox" name="partner_material" id="partnerMaterial" class="form-check-input tag-checkbox" value="1" {{ old('partner_material') ? 'checked' : '' }}>
                                <label class="form-check-label">Partner Materials</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check">
                                <input type="checkbox" name="as_you_prefer" id="asYouPrefer" class="form-check-input tag-checkbox" value="1" {{ old('as_you_prefer') ? 'checked' : '' }}>
                                <label class="form-check-label">As You Prefer</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 5: Sensitive -->
                <div class="form-section">
                    <div class="row bg-light p-3 rounded">
                        <div class="col-12">
                            <label class="form-label">Sensitive Topics (Optional)</label>
                            <div class="d-flex flex-wrap gap-3">
                                @foreach(['crypto','trading','CBD','forex'] as $topic)
                                <div class="me-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="sensitive[{{ $topic }}]" class="form-check-input sensitive-checkbox" id="sensitive{{ $topic }}" {{ old("sensitive.$topic") ? 'checked' : '' }}>
                                        <label class="form-check-label" for="sensitive{{ $topic }}">{{ ucfirst($topic) }}</label>
                                    </div>
                                    <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 sensitive-price" placeholder="Price" value="{{ old("price_sensitive.$topic") }}">
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 6: Description -->
                <div class="form-section">
                    <div class="row">
                        <div class="col-12">
                            <label class="form-label">Site Description (500 words max) <span class="text-danger">*</span></label>
                            <div id="quillEditor" class="border rounded" style="height: 200px;" placeholder="Enter site description">{!! old('siteDescription') !!}</div>
                            <input type="hidden" name="siteDescription" id="siteDescription" required>
                        </div>
                    </div>
                </div>

                <!-- Submit & Close -->
                <div class="row">
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-success shadow-sm" id="submitBtn">Submit</button>
                        <button type="button" class="btn btn-secondary shadow-sm" id="closeBtn">Close</button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <div class="mt-5">
        <h4>Your Sites</h4>
        <input type="text" id="siteSearch" class="form-control table-search" placeholder="Search sites...">
        <div id="sitesTableWrapper" class="mt-3"></div>
    </div>
</div>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const addBtn = $('#showFormBtn');
const formCard = $('#formCard');
const submitBtn = $('#submitBtn');
const closeBtn = $('#closeBtn');
const formHeaderSpan = $('#formHeader');

// Store all languages for reset (NO country filtering on create)
var allLanguages = [];
@foreach($languages as $language)
    allLanguages.push({code: '{{ $language->code }}', name: '{{ $language->name }}'});
@endforeach

// NO country-language linking on create - language dropdown shows all languages
// This is intentionally removed for create functionality

// Quill editor
var quill = new Quill('#quillEditor', {
    theme: 'snow',
    placeholder: 'Enter site description...',
    modules: {
        toolbar: [
            [{ 'header': [1, 2, 3, false] }],
            ['bold', 'italic', 'underline'],
            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
            ['link']
        ]
    }
});

// One tag selectable at a time
$(document).on('change', '.tag-checkbox', function() {
    $('.tag-checkbox').not(this).prop('checked', false);
});

// Toggle form for CREATE
addBtn.on('click', function() {
    formCard.toggleClass('d-none');
    let isOpen = !formCard.hasClass('d-none');

    addBtn.toggleClass('d-none', isOpen);
    closeBtn.toggleClass('d-none', !isOpen);
    formHeaderSpan.text('Add New Website');

    if(isOpen){
        // Reset form for new site
        $('#addSiteForm')[0].reset();
        $('#methodField').val('POST');
        $('#addSiteForm').attr('action', '{{ route("publisher.sites.store") }}');
        quill.root.innerHTML = '';
        submitBtn.prop('disabled', false).text('Submit');
        
        // Reset language dropdown to all languages
        $('#languageSelect').empty();
        $('#languageSelect').append('<option value="">Select Language</option>');
        $.each(allLanguages, function(index, lang) {
            $('#languageSelect').append('<option value="' + lang.code + '">' + lang.name + '</option>');
        });
        
        // Reset country dropdown
        $('#countrySelect').val('');
        
        // Enable site name and URL for create
        $('#siteName').prop('disabled', false);
        $('#siteUrl').prop('disabled', false);
        $('.readonly-note').remove();
    }
});

// Form validation
$('#addSiteForm').submit(function(e){
    $('#siteDescription').val(quill.root.innerHTML);
    let form = this;
    if(!form.checkValidity()){
        e.preventDefault();
        e.stopPropagation();
        $(form).addClass('was-validated');
    } else {
        submitBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Saving...');
    }
});

// Fetch sites
function fetchSites(page = 1, query = '') {
    $('#sitesTableWrapper').html('<div class="text-muted">Loading...</div>');

    $.ajax({
        url: '{{ route("publisher.sites.ajax") }}',
        method: 'GET',
        data: { page: page, query: query },
        success: function(res) {
            if(!res || res.trim() === ''){
                $('#sitesTableWrapper').html('<div class="text-muted">No sites found.</div>');
            } else {
                $('#sitesTableWrapper').html(res);
            }
        },
        error: function() {
            $('#sitesTableWrapper').html('<div class="text-danger">Failed to load sites.</div>');
        }
    });
}

// Debounced search
let delayTimer;
$(document).ready(function(){
    fetchSites();

    $('#siteSearch').on('keyup', function(){
        clearTimeout(delayTimer);
        delayTimer = setTimeout(() => {
            fetchSites(1, $(this).val());
        }, 400);
    });

    $(document).on('click', '.pagination li', function(){
        fetchSites($(this).data('page'), $('#siteSearch').val());
    });
});

// Close form
closeBtn.on('click', function(){
    formCard.addClass('d-none');
    addBtn.removeClass('d-none');
    closeBtn.addClass('d-none');
    formHeaderSpan.text('Add New Website');
    $('#addSiteForm')[0].reset();
    quill.root.innerHTML = '';
    $('.tag-checkbox').prop('checked', false);
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    $('#languageSelect').empty();
    $('#languageSelect').append('<option value="">Select Language</option>');
    $.each(allLanguages, function(index, lang) {
        $('#languageSelect').append('<option value="' + lang.code + '">' + lang.name + '</option>');
    });
    $('#siteName').prop('disabled', false);
    $('#siteUrl').prop('disabled', false);
    $('.readonly-note').remove();
});

// Edit functionality - Properly prefill all values
$(document).on('click', '.btn-edit', function() {
    const site = $(this).data('site');
    
    // Show form
    $('#formCard').removeClass('d-none');
    $('#showFormBtn').addClass('d-none');
    $('#closeBtn').removeClass('d-none');
    $('#formHeader').text('Edit Site: ' + site.site_name);
    
    // Set form action for update
    $('#methodField').remove();
    $('#addSiteForm')
        .attr('action', '/publisher/sites/' + site.id)
        .append('<input type="hidden" name="_method" value="PUT" id="methodField">');
    
    // Prefill all fields
    $('#siteName').val(site.site_name).prop('disabled', true);
    $('#siteUrl').val(site.site_url).prop('disabled', true);
    
    // Add readonly message
    if (!$('#siteName').next('.readonly-note').length) {
        $('#siteName').after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
    }
    if (!$('#siteUrl').next('.readonly-note').length) {
        $('#siteUrl').after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
    }
    
    $('#exampleUrl').val(site.example_url);
    $('#da').val(site.da);
    $('#dr').val(site.dr);
    $('#traffic').val(site.traffic);
    $('#price').val(site.price);
    $('#countrySelect').val(site.country);
    $('#languageSelect').val(site.language);
    $('#category').val(site.category);
    $('#publicationTime').val(site.publication_time);
    
    // Link type radio
    if (site.link_type === 'dofollow') {
        $('#linkTypeDofollow').prop('checked', true);
    } else {
        $('#linkTypeNofollow').prop('checked', true);
    }
    
    // Tags checkboxes
    $('#sponsored').prop('checked', site.sponsored == 1);
    $('#partnerMaterial').prop('checked', site.partner_material == 1);
    $('#asYouPrefer').prop('checked', site.as_you_prefer == 1);
    
    // Sensitive topics
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    
    if (site.sensitive_prices) {
        let prices = typeof site.sensitive_prices === 'string' ? JSON.parse(site.sensitive_prices) : site.sensitive_prices;
        for (const key in prices) {
            $(`#sensitive${key.charAt(0).toUpperCase() + key.slice(1)}`).prop('checked', true);
            $(`input[name="price_sensitive[${key}]"]`).val(prices[key]);
        }
    }
    
    // Description
    if (quill) {
        quill.root.innerHTML = site.description || '';
    }
    
    $('#submitBtn').prop('disabled', false).text('Update');
    
    // Scroll to form
    $('html, body').animate({
        scrollTop: $("#formCard").offset().top - 100
    }, 500);
});
</script>
@endsection