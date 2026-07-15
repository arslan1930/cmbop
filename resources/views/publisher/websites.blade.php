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
    
    /* Turnaround Time Badge Styles */
    .turnaround-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .turnaround-24h {
        background-color: #d4edda;
        color: #155724;
    }
    
    .turnaround-48h {
        background-color: #d1ecf1;
        color: #0c5460;
    }
    
    .turnaround-3days {
        background-color: #fff3cd;
        color: #856404;
    }
    
    .turnaround-5days {
        background-color: #f8d7da;
        color: #721c24;
    }
    
    .turnaround-7days {
        background-color: #e2d5f0;
        color: #4a148c;
    }
    
    .help-text {
        font-size: 11px;
        color: #6c757d;
        margin-top: 4px;
    }

    /* Multi-select styles for Categories */
    .multi-select-wrapper {
        position: relative;
        width: 100%;
    }
    
    .multi-select-input {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        min-height: 42px;
        padding: 8px 12px;
        border: 1px solid #dfe3e8;
        border-radius: 8px;
        background-color: #f6f9fc;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .multi-select-input:hover {
        border-color: #007bff;
    }
    
    .multi-select-tag {
        display: inline-flex;
        align-items: center;
        background-color: #e9ecef;
        border-radius: 20px;
        padding: 4px 10px;
        font-size: 12px;
        font-weight: 500;
        color: #32325d;
    }
    
    .multi-select-tag .remove-tag {
        margin-left: 8px;
        cursor: pointer;
        font-weight: bold;
        font-size: 16px;
        color: #6c757d;
        line-height: 1;
    }
    
    .multi-select-tag .remove-tag:hover {
        color: #dc3545;
    }
    
    .multi-select-placeholder {
        color: #adb5bd;
        font-size: 14px;
    }
    
    .multi-select-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #dfe3e8;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        z-index: 1000;
        max-height: 280px;
        overflow-y: auto;
        display: none;
        margin-top: 4px;
    }
    
    .multi-select-dropdown.show {
        display: block;
    }
    
    .multi-select-search {
        padding: 10px;
        border-bottom: 1px solid #e6ebf1;
        position: sticky;
        top: 0;
        background: white;
    }
    
    .multi-select-search input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #dfe3e8;
        border-radius: 6px;
        font-size: 13px;
    }
    
    .multi-select-search input:focus {
        outline: none;
        border-color: #007bff;
    }
    
    .multi-select-option {
        padding: 10px 12px;
        cursor: pointer;
        transition: background 0.15s ease;
        font-size: 14px;
    }
    
    .multi-select-option:hover {
        background-color: #f6f9fc;
    }
    
    .multi-select-option.selected {
        background-color: #e3f2fd;
        color: #007bff;
    }
    
    .multi-select-option.hidden {
        display: none;
    }
    
    /* Single select styles for Country and Language */
    .single-select-wrapper {
        position: relative;
        width: 100%;
    }
    
    .single-select-input {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 42px;
        padding: 8px 12px;
        border: 1px solid #dfe3e8;
        border-radius: 8px;
        background-color: #f6f9fc;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .single-select-input:hover {
        border-color: #007bff;
    }
    
    .single-select-value {
        color: #32325d;
    }
    
    .single-select-placeholder {
        color: #adb5bd;
        font-size: 14px;
    }
    
    .single-select-arrow {
        color: #6c757d;
        font-size: 12px;
    }
    
    .single-select-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #dfe3e8;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        z-index: 1000;
        max-height: 280px;
        overflow-y: auto;
        display: none;
        margin-top: 4px;
    }
    
    .single-select-dropdown.show {
        display: block;
    }
    
    .single-select-search {
        padding: 10px;
        border-bottom: 1px solid #e6ebf1;
        position: sticky;
        top: 0;
        background: white;
    }
    
    .single-select-search input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #dfe3e8;
        border-radius: 6px;
        font-size: 13px;
    }
    
    .single-select-option {
        padding: 10px 12px;
        cursor: pointer;
        transition: background 0.15s ease;
        font-size: 14px;
    }
    
    .single-select-option:hover {
        background-color: #f6f9fc;
    }
    
    .single-select-option.selected {
        background-color: #e3f2fd;
        color: #007bff;
    }
    
    .single-select-option.hidden {
        display: none;
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

    <button id="showFormBtn" class="btn mb-3 shadow-sm" style="background-color: #3aaeb2; color: white;">
        <i class="fa fa-plus"></i> Add New Website
    </button>

    <button id="showBulkBtn" type="button" class="btn mb-3 shadow-sm btn-outline-primary ms-1">
        <i class="fa fa-file-csv"></i> Bulk Import (Agency)
    </button>

    @if(session('error') && !session('bulk_import_failures'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('bulk_import_failures'))
        <div class="alert alert-warning">
            <strong>Bulk import finished.</strong>
            {{ session('bulk_import_created', 0) }} site(s) submitted.
            {{ count(session('bulk_import_failures')) }} row(s) failed:
            <div class="table-responsive mt-2" style="max-height: 260px; overflow:auto;">
                <table class="table table-sm table-bordered bg-white mb-0">
                    <thead><tr><th>Row</th><th>Site</th><th>Errors</th></tr></thead>
                    <tbody>
                        @foreach(session('bulk_import_failures') as $fail)
                            <tr>
                                <td>{{ $fail['row'] }}</td>
                                <td class="small">{{ $fail['site'] }}</td>
                                <td class="small text-danger">{{ implode(' · ', $fail['errors']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card shadow-sm border-0 d-none mb-3" id="bulkCard">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Bulk Import for Agencies</h5>
                    <p class="text-muted mb-0 small">
                        Own 150+ websites? Upload a CSV to submit many sites at once (max 200 per upload).
                        Each site still needs admin approval before it goes live.
                    </p>
                </div>
                <a href="{{ route('publisher.sites.bulk-template') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-download me-1"></i> Download CSV template
                </a>
            </div>

            <div class="bg-light rounded p-3 mb-3 small">
                <strong>CSV tips:</strong>
                <ul class="mb-0 mt-1">
                    <li><code>country</code> / <code>language</code> = 2-letter codes (e.g. <code>US</code>, <code>en</code>)</li>
                    <li><code>categories</code> = exact category names, separated by <code>|</code> (max 7)</li>
                    <li><code>turnaround_time</code> = <code>24h</code>, <code>48h</code>, <code>3days</code>, <code>5days</code>, or <code>7days</code></li>
                    <li><code>publication_time</code> = <code>6months</code>, <code>1year</code>, or <code>permanent</code></li>
                    <li><code>link_type</code> = <code>dofollow</code> or <code>nofollow</code></li>
                    <li><code>description</code> must be at least 50 characters</li>
                    <li>Flags (<code>sponsored</code>, etc.) use <code>1</code> / <code>0</code></li>
                </ul>
            </div>

            <form method="POST" action="{{ route('publisher.sites.bulk-import') }}" enctype="multipart/form-data">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">CSV file</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="fa fa-upload me-1"></i> Upload &amp; Import
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="closeBulkBtn">Close</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

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
                        <div class="col-md-3">
                            <label class="form-label">Turnaround Time <span class="text-danger">*</span></label>
                            <select name="turnaround_time" id="turnaroundTime" class="form-select" required>
                                <option value="">Select Turnaround Time</option>
                                <option value="24h" {{ old('turnaround_time') == '24h' ? 'selected' : '' }}>24 Hours</option>
                                <option value="48h" {{ old('turnaround_time') == '48h' ? 'selected' : '' }}>48 Hours</option>
                                <option value="3days" {{ old('turnaround_time') == '3days' ? 'selected' : '' }}>3 Days</option>
                                <option value="5days" {{ old('turnaround_time') == '5days' ? 'selected' : '' }}>5 Days</option>
                                <option value="7days" {{ old('turnaround_time') == '7days' ? 'selected' : '' }}>7 Days</option>
                            </select>
                            <div class="help-text">Estimated time to publish after order confirmation</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Price (€) <span class="text-danger">*</span></label>
                            <input type="number" name="price" id="price" class="form-control" placeholder="Enter price" min="0" step="0.01" value="{{ old('price') }}" required>
                        </div>
                    </div>
                </div>

                <!-- Row 3 with Single-select for Country, Language and Multi-select for Category -->
                <div class="form-section">
                    <div class="row bg-light p-3 rounded">
                        <div class="col-md-4">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <input type="hidden" name="country" id="selectedCountry" value="{{ old('country') }}">
                            <div class="single-select-wrapper" id="countryWrapper">
                                <div class="single-select-input" id="countryInput">
                                    <span class="single-select-value" id="countryValue">@if(old('country')) {{ old('country') }} @else <span class="single-select-placeholder">Select country...</span> @endif</span>
                                    <span class="single-select-arrow">▼</span>
                                </div>
                                <div class="single-select-dropdown" id="countryDropdown">
                                    <div class="single-select-search">
                                        <input type="text" placeholder="Search countries..." id="countrySearch">
                                    </div>
                                    <div class="single-select-options" id="countryOptions">
                                        @foreach($countries as $country)
                                            <div class="single-select-option" data-value="{{ $country->code }}" data-label="{{ $country->name }}">{{ $country->name }}</div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Language <span class="text-danger">*</span></label>
                            <input type="hidden" name="language" id="selectedLanguage" value="{{ old('language') }}">
                            <div class="single-select-wrapper" id="languageWrapper">
                                <div class="single-select-input" id="languageInput">
                                    <span class="single-select-value" id="languageValue">@if(old('language')) {{ old('language') }} @else <span class="single-select-placeholder">Select language...</span> @endif</span>
                                    <span class="single-select-arrow">▼</span>
                                </div>
                                <div class="single-select-dropdown" id="languageDropdown">
                                    <div class="single-select-search">
                                        <input type="text" placeholder="Search languages..." id="languageSearch">
                                    </div>
                                    <div class="single-select-options" id="languageOptions">
                                        @foreach($languages as $language)
                                            <div class="single-select-option" data-value="{{ $language->code }}" data-label="{{ $language->name }}">{{ $language->name }}</div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Categories <span class="text-danger">*</span></label>
                            <input type="hidden" name="categories[]" id="selectedCategories" value="">
                            <div class="multi-select-wrapper" id="categoryWrapper">
                                <div class="multi-select-input" id="categoryInput">
                                    <span class="multi-select-placeholder">Select categories (max 7)...</span>
                                </div>
                                <div class="multi-select-dropdown" id="categoryDropdown">
                                    <div class="multi-select-search">
                                        <input type="text" placeholder="Search categories..." id="categorySearch">
                                    </div>
                                    <div class="multi-select-options" id="categoryOptions">
                                        @foreach($categories as $category)
                                            <div class="multi-select-option" data-value="{{ $category->name }}" data-label="{{ $category->name }}">{{ $category->name }}</div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Publication Duration and Link Type Row -->
                <div class="form-section">
                    <div class="row bg-light p-3 rounded">
                        <div class="col-md-3">
                            <label class="form-label">Publication Duration <span class="text-danger">*</span></label>
                            <select name="publicationTime" id="publicationTime" class="form-select" required>
                                <option value="">Select Duration</option>
                                <option value="6months" {{ old('publicationTime') == '6months' ? 'selected' : '' }}>6 Months</option>
                                <option value="1year" {{ old('publicationTime') == '1year' ? 'selected' : '' }}>1 Year</option>
                                <option value="permanent" {{ old('publicationTime') == 'permanent' ? 'selected' : '' }}>Permanent</option>
                            </select>
                        </div>
                        <div class="col-md-3">
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
                                    <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 sensitive-price" placeholder="Extra Price" value="{{ old("price_sensitive.$topic") }}">
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
const bulkBtn = $('#showBulkBtn');
const bulkCard = $('#bulkCard');
const closeBulkBtn = $('#closeBulkBtn');
const formCard = $('#formCard');
const submitBtn = $('#submitBtn');
const closeBtn = $('#closeBtn');
const formHeaderSpan = $('#formHeader');

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

// ==================== Single Select Component for Country & Language ====================
function initSingleSelect(wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId, valueDisplayId) {
    let selectedValue = '';
    let selectedLabel = '';
    const wrapper = $(`#${wrapperId}`);
    const input = $(`#${inputId}`);
    const dropdown = $(`#${dropdownId}`);
    const optionsContainer = $(`#${optionsId}`);
    const hiddenInput = $(`#${hiddenInputId}`);
    const searchInput = $(`#${searchId}`);
    const valueDisplay = $(`#${valueDisplayId}`);
    
    // Function to update display
    function updateDisplay() {
        if (selectedValue && selectedLabel) {
            valueDisplay.html(selectedLabel);
        } else {
            valueDisplay.html('<span class="single-select-placeholder">Select option...</span>');
        }
        hiddenInput.val(selectedValue);
        updateOptionsHighlight();
    }
    
    // Function to select an option
    function selectOption(value, label) {
        selectedValue = value;
        selectedLabel = label;
        updateDisplay();
        dropdown.removeClass('show');
    }
    
    // Function to highlight selected option
    function updateOptionsHighlight() {
        optionsContainer.find('.single-select-option').each(function() {
            const $this = $(this);
            const value = $this.data('value');
            if (selectedValue === value) {
                $this.addClass('selected');
            } else {
                $this.removeClass('selected');
            }
        });
    }
    
    // Function to filter options
    function filterOptions(searchTerm) {
        const term = searchTerm.toLowerCase();
        optionsContainer.find('.single-select-option').each(function() {
            const $this = $(this);
            const text = $this.text().toLowerCase();
            if (term === '' || text.includes(term)) {
                $this.removeClass('hidden');
            } else {
                $this.addClass('hidden');
            }
        });
    }
    
    // Toggle dropdown
    input.on('click', function(e) {
        e.stopPropagation();
        $('.single-select-dropdown').not(dropdown).removeClass('show');
        $('.multi-select-dropdown').removeClass('show');
        dropdown.toggleClass('show');
        if (dropdown.hasClass('show')) {
            searchInput.focus();
            filterOptions('');
        }
    });
    
    // Close dropdown when clicking outside
    $(document).on('click', function() {
        $('.single-select-dropdown').removeClass('show');
    });
    
    dropdown.on('click', function(e) {
        e.stopPropagation();
    });
    
    // Search functionality
    searchInput.on('keyup', function() {
        filterOptions($(this).val());
    });
    
    // Option click
    optionsContainer.on('click', '.single-select-option', function(e) {
        const $option = $(this);
        if ($option.hasClass('hidden')) return;
        
        const value = $option.data('value');
        const label = $option.data('label');
        selectOption(value, label);
    });
    
    // Function to set selected value (for edit mode)
    function setSelectedValue(value, label) {
        selectedValue = value;
        selectedLabel = label;
        updateDisplay();
    }
    
    // Function to get selected value
    function getSelectedValue() {
        return selectedValue;
    }
    
    // Clear selection
    function clearSelection() {
        selectedValue = '';
        selectedLabel = '';
        updateDisplay();
        searchInput.val('');
        filterOptions('');
    }
    
    return {
        selectOption,
        setSelectedValue,
        getSelectedValue,
        clearSelection
    };
}

// ==================== Multi-Select Component for Categories ====================
function initMultiSelect(wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId, maxSelections = null) {
    let selectedItems = [];
    const wrapper = $(`#${wrapperId}`);
    const input = $(`#${inputId}`);
    const dropdown = $(`#${dropdownId}`);
    const optionsContainer = $(`#${optionsId}`);
    const hiddenInput = $(`#${hiddenInputId}`);
    const searchInput = $(`#${searchId}`);
    
    // Function to update the display
    function updateDisplay() {
        input.empty();
        if (selectedItems.length === 0) {
            input.html('<span class="multi-select-placeholder">Select categories (max 7)...</span>');
        } else {
            selectedItems.forEach(item => {
                const tag = $(`
                    <span class="multi-select-tag">
                        ${item.label}
                        <span class="remove-tag" data-value="${item.value}">&times;</span>
                    </span>
                `);
                tag.find('.remove-tag').on('click', function(e) {
                    e.stopPropagation();
                    removeItem(item.value);
                });
                input.append(tag);
            });
        }
        
        // Update hidden input
        hiddenInput.val(selectedItems.map(item => item.value).join(','));
    }
    
    // Function to add an item
    function addItem(value, label) {
        if (maxSelections && selectedItems.length >= maxSelections) {
            Swal.fire({
                icon: 'warning',
                title: `Maximum ${maxSelections} categories allowed`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000
            });
            return false;
        }
        
        if (!selectedItems.some(item => item.value === value)) {
            selectedItems.push({ value, label });
            updateDisplay();
            updateOptionsHighlight();
            return true;
        }
        return false;
    }
    
    // Function to remove an item
    function removeItem(value) {
        selectedItems = selectedItems.filter(item => item.value !== value);
        updateDisplay();
        updateOptionsHighlight();
    }
    
    // Function to highlight selected options
    function updateOptionsHighlight() {
        optionsContainer.find('.multi-select-option').each(function() {
            const $this = $(this);
            const value = $this.data('value');
            if (selectedItems.some(item => item.value === value)) {
                $this.addClass('selected');
            } else {
                $this.removeClass('selected');
            }
        });
    }
    
    // Function to filter options
    function filterOptions(searchTerm) {
        const term = searchTerm.toLowerCase();
        optionsContainer.find('.multi-select-option').each(function() {
            const $this = $(this);
            const text = $this.text().toLowerCase();
            if (term === '' || text.includes(term)) {
                $this.removeClass('hidden');
            } else {
                $this.addClass('hidden');
            }
        });
    }
    
    // Toggle dropdown
    input.on('click', function(e) {
        e.stopPropagation();
        $('.multi-select-dropdown').not(dropdown).removeClass('show');
        $('.single-select-dropdown').removeClass('show');
        dropdown.toggleClass('show');
        if (dropdown.hasClass('show')) {
            searchInput.focus();
            filterOptions('');
        }
    });
    
    // Close dropdown when clicking outside
    $(document).on('click', function() {
        $('.multi-select-dropdown').removeClass('show');
    });
    
    dropdown.on('click', function(e) {
        e.stopPropagation();
    });
    
    // Search functionality
    searchInput.on('keyup', function() {
        filterOptions($(this).val());
    });
    
    // Option click
    optionsContainer.on('click', '.multi-select-option', function(e) {
        const $option = $(this);
        if ($option.hasClass('hidden')) return;
        
        const value = $option.data('value');
        const label = $option.data('label');
        
        if ($option.hasClass('selected')) {
            removeItem(value);
        } else {
            addItem(value, label);
        }
    });
    
    // Function to set selected items from existing data
    function setSelectedItems(values, labels) {
        selectedItems = [];
        for (let i = 0; i < values.length; i++) {
            if (values[i]) {
                selectedItems.push({ value: values[i], label: labels[i] || values[i] });
            }
        }
        updateDisplay();
        updateOptionsHighlight();
    }
    
    // Function to get selected items
    function getSelectedItems() {
        return selectedItems;
    }
    
    // Clear all selections
    function clearSelections() {
        selectedItems = [];
        updateDisplay();
        updateOptionsHighlight();
        searchInput.val('');
        filterOptions('');
    }
    
    return {
        addItem,
        removeItem,
        getSelectedItems,
        clearSelections,
        setSelectedItems,
        updateDisplay
    };
}

// Initialize Country Single Select
let countrySingleSelect = initSingleSelect('countryWrapper', 'countryInput', 'countryDropdown', 'countryOptions', 'selectedCountry', 'countrySearch', 'countryValue');
@if(old('country'))
    let oldCountry = '{{ old('country') }}';
    let oldCountryLabel = $('#countryOptions .single-select-option[data-value="' + oldCountry + '"]').data('label');
    if (oldCountryLabel) {
        countrySingleSelect.setSelectedValue(oldCountry, oldCountryLabel);
    }
@endif

// Initialize Language Single Select
let languageSingleSelect = initSingleSelect('languageWrapper', 'languageInput', 'languageDropdown', 'languageOptions', 'selectedLanguage', 'languageSearch', 'languageValue');
@if(old('language'))
    let oldLanguage = '{{ old('language') }}';
    let oldLanguageLabel = $('#languageOptions .single-select-option[data-value="' + oldLanguage + '"]').data('label');
    if (oldLanguageLabel) {
        languageSingleSelect.setSelectedValue(oldLanguage, oldLanguageLabel);
    }
@endif

// Initialize Category Multi Select (max 7)
let categoryMultiSelect = initMultiSelect('categoryWrapper', 'categoryInput', 'categoryDropdown', 'categoryOptions', 'selectedCategories', 'categorySearch', 7);
@if(old('categories'))
    let oldCategories = @json(old('categories', []));
    if (oldCategories && oldCategories.length) {
        $('#categoryOptions .multi-select-option').each(function() {
            let val = $(this).data('value');
            if (oldCategories.includes(val)) {
                categoryMultiSelect.addItem(val, $(this).data('label'));
            }
        });
    }
@endif

// Toggle form for CREATE
addBtn.on('click', function() {
    bulkCard.addClass('d-none');
    formCard.toggleClass('d-none');
    let isOpen = !formCard.hasClass('d-none');

    addBtn.toggleClass('d-none', isOpen);
    bulkBtn.toggleClass('d-none', isOpen);
    closeBtn.toggleClass('d-none', !isOpen);
    formHeaderSpan.text('Add New Website');

    if(isOpen){
        // Reset form for new site
        $('#addSiteForm')[0].reset();
        $('#methodField').val('POST');
        $('#addSiteForm').attr('action', '{{ route("publisher.sites.store") }}');
        quill.root.innerHTML = '';
        submitBtn.prop('disabled', false).text('Submit');
        
        // Reset single selects
        countrySingleSelect.clearSelection();
        languageSingleSelect.clearSelection();
        
        // Reset multi-select
        categoryMultiSelect.clearSelections();
        
        // Enable site name and URL for create
        $('#siteName').prop('disabled', false);
        $('#siteUrl').prop('disabled', false);
        $('.readonly-note').remove();
    }
});

bulkBtn.on('click', function() {
    formCard.addClass('d-none');
    closeBtn.addClass('d-none');
    addBtn.removeClass('d-none');
    bulkCard.toggleClass('d-none');
    bulkBtn.toggleClass('d-none', !bulkCard.hasClass('d-none'));
    formHeaderSpan.text(bulkCard.hasClass('d-none') ? 'Add New Website' : 'Bulk Import');
});

closeBulkBtn.on('click', function() {
    bulkCard.addClass('d-none');
    bulkBtn.removeClass('d-none');
    formHeaderSpan.text('Add New Website');
});

// Form validation
$('#addSiteForm').submit(function(e){
    $('#siteDescription').val(quill.root.innerHTML);
    
    // Validate single selects
    if (!countrySingleSelect.getSelectedValue()) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please select a country.' });
        return;
    }
    if (!languageSingleSelect.getSelectedValue()) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please select a language.' });
        return;
    }
    if (categoryMultiSelect.getSelectedItems().length === 0) {
        e.preventDefault();
        Swal.fire({ icon: 'error', title: 'Validation Error', text: 'Please select at least one category.' });
        return;
    }
    
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
    bulkBtn.removeClass('d-none');
    closeBtn.addClass('d-none');
    formHeaderSpan.text('Add New Website');
    $('#addSiteForm')[0].reset();
    quill.root.innerHTML = '';
    $('.tag-checkbox').prop('checked', false);
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    
    // Reset selects
    countrySingleSelect.clearSelection();
    languageSingleSelect.clearSelection();
    categoryMultiSelect.clearSelections();
    
    $('#siteName').prop('disabled', false);
    $('#siteUrl').prop('disabled', false);
    $('.readonly-note').remove();
});

// Edit functionality - Prefill all values
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
    $('#turnaroundTime').val(site.turnaround_time || '3days');
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
    
    // Set Country
    if (site.country) {
        let countryOption = $(`#countryOptions .single-select-option[data-value="${site.country}"]`);
        if (countryOption.length) {
            countrySingleSelect.setSelectedValue(site.country, countryOption.data('label'));
        }
    } else if (site.country_code) {
        let countryOption = $(`#countryOptions .single-select-option[data-value="${site.country_code}"]`);
        if (countryOption.length) {
            countrySingleSelect.setSelectedValue(site.country_code, countryOption.data('label'));
        }
    }
    
    // Set Language
    if (site.language) {
        let languageOption = $(`#languageOptions .single-select-option[data-value="${site.language}"]`);
        if (languageOption.length) {
            languageSingleSelect.setSelectedValue(site.language, languageOption.data('label'));
        }
    } else if (site.language_code) {
        let languageOption = $(`#languageOptions .single-select-option[data-value="${site.language_code}"]`);
        if (languageOption.length) {
            languageSingleSelect.setSelectedValue(site.language_code, languageOption.data('label'));
        }
    }
    
    // Set Categories
    categoryMultiSelect.clearSelections();
    if (site.categories) {
        let categoriesArray = typeof site.categories === 'string' ? JSON.parse(site.categories) : site.categories;
        categoriesArray.forEach(categoryName => {
            let option = $(`#categoryOptions .multi-select-option[data-value="${categoryName}"]`);
            if (option.length) {
                categoryMultiSelect.addItem(categoryName, option.data('label'));
            }
        });
    } else if (site.category) {
        // Fallback for single category
        let option = $(`#categoryOptions .multi-select-option[data-value="${site.category}"]`);
        if (option.length) {
            categoryMultiSelect.addItem(site.category, option.data('label'));
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