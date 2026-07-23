@extends('publisher.layouts.app')

@section('content')
<style>
    body {
        font-family: 'Poppins', system-ui, sans-serif;
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

    /* Spacing tokens come from spacing-system.css (3.6 / W2) */
    .form-label {
        font-weight: 600;
        font-size: 13px;
        margin-bottom: var(--space-2, 8px);
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
        border-color: var(--brand-primary-soft, #3faeb2);
        box-shadow: 0 0 0 0.2rem var(--focus-ring, rgba(63, 174, 178, 0.35));
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
        background-color: #185054;
        color: white;
        border-color: #185054;
    }

    #formCard {
        transition: all 0.2s ease;
    }

    .site-wizard-steps {
        display: flex;
        gap: 8px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .site-wizard-step {
        flex: 1;
        min-width: 140px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid #e6ebf1;
        background: #f6f9fc;
        color: #8898aa;
        font-size: 13px;
        font-weight: 600;
    }
    .site-wizard-step .wiz-num {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: #e6ebf1;
        color: #525f7f;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        flex-shrink: 0;
    }
    .site-wizard-step.active {
        border-color: #3faeb2;
        background: rgba(63, 174, 178, 0.08);
        color: #185054;
    }
    .site-wizard-step.active .wiz-num {
        background: #185054;
        color: #fff;
    }
    .site-wizard-step.done {
        border-color: #c8ebe9;
        color: #185054;
    }
    .site-wizard-step.done .wiz-num {
        background: #5bc4c7;
        color: #fff;
    }
    .wizard-pane { display: none; }
    .wizard-pane.active { display: block; }
    .wizard-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-top: 8px;
        padding-top: 18px;
        border-top: 1px solid #e6ebf1;
    }
    .wizard-draft-hint {
        font-size: 12px;
        color: #8898aa;
    }

    #sitesTableWrapper {
        min-height: 80px;
    }

    .btn-primary {
        background-color: #185054;
        border-color: #185054;
    }

    .btn-primary:hover {
        background-color: #123f42;
        border-color: #123f42;
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
        border-color: #185054;
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
        border-color: #185054;
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
        color: #185054;
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
        border-color: #185054;
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
        color: #185054;
    }
    
    .single-select-option.hidden {
        display: none;
    }

    .single-select-option.disabled {
        opacity: 0.4;
        color: #94a3b8 !important;
        cursor: not-allowed;
        background: #f8fafc !important;
        font-weight: 400 !important;
    }

    .single-select-option.disabled:hover {
        background: #f8fafc !important;
    }

    .single-select-option.suggested {
        font-weight: 600;
        color: #0f172a;
    }
    
    @media (max-width: 768px) {
        #sitesTableWrapper {
            overflow: visible;
            max-height: none;
        }

        #sitesTableWrapper .sites-responsive-table {
            min-width: 0 !important;
        }

        #sitesTableWrapper .sites-responsive-table thead {
            display: none;
        }

        #sitesTableWrapper .sites-responsive-table tbody,
        #sitesTableWrapper .sites-responsive-table tr.main-row {
            display: block;
            width: 100%;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 12px;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border: 0;
            padding: 6px 0;
            text-align: right;
            white-space: normal;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #64748b;
            text-align: left;
            flex-shrink: 0;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Preview"] {
            justify-content: flex-start;
            padding-bottom: 10px;
            margin-bottom: 4px;
            border-bottom: 1px solid #f1f5f9;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Preview"]::before {
            display: none;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Preview"] .site-row-preview {
            width: 100%;
            height: 140px;
            border-radius: 10px;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Site"] {
            align-items: flex-start;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Site"] .site-row-identity {
            max-width: none;
            text-align: right;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Actions"] {
            flex-wrap: wrap;
            justify-content: flex-end;
            padding-top: 10px;
            margin-top: 4px;
            border-top: 1px solid #f1f5f9;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td[data-label="Actions"]::before {
            width: 100%;
            margin-bottom: 4px;
        }

        #sitesTableWrapper .sites-responsive-table tr:not(.main-row) {
            display: block;
            margin-bottom: 12px;
        }
    }

    /* Live bulk: full editable table (all sites visible) */
    .live-bulk-table-wrap {
        overflow: auto;
        max-height: min(70vh, 720px);
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #fff;
    }
    .live-bulk-table {
        width: max-content;
        min-width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.82rem;
        margin: 0;
    }
    .live-bulk-table thead th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        border-right: 1px solid #eef2f7;
        padding: 0.55rem 0.5rem;
        font-weight: 700;
        color: #334155;
        white-space: nowrap;
        vertical-align: middle;
    }
    .live-bulk-table tbody td {
        border-bottom: 1px solid #eef2f7;
        border-right: 1px solid #eef2f7;
        padding: 0.4rem 0.45rem;
        vertical-align: top;
        background: #fff;
    }
    .live-bulk-table tbody tr:hover td {
        background: #f8fafc;
    }
    .live-bulk-table .live-bulk-sticky {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #fff;
        box-shadow: 1px 0 0 #e2e8f0;
        min-width: 3.25rem;
        text-align: center;
    }
    .live-bulk-table thead th.live-bulk-sticky {
        z-index: 4;
        background: #f8fafc;
    }
    .live-bulk-table tbody tr:hover .live-bulk-sticky {
        background: #f8fafc;
    }
    .live-bulk-table .form-control,
    .live-bulk-table .form-select {
        min-width: 6.5rem;
        font-size: 0.8rem;
    }
    .live-bulk-table .live-bulk-col-name .form-control,
    .live-bulk-table .live-bulk-col-url .form-control {
        min-width: 9.5rem;
    }
    .live-bulk-table .live-bulk-col-desc .form-control {
        min-width: 14rem;
        width: 14rem;
    }
    .live-bulk-table .live-bulk-categories {
        min-width: 11rem;
        width: 11rem;
        height: 4.75rem;
    }
    .live-bulk-num {
        display: inline-block;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 0.25rem;
    }
    .live-bulk-table .live-bulk-remove {
        padding: 0.15rem 0.4rem;
    }
    .site-status-filter {
        display: inline-flex !important;
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
        line-height: 1.2;
        padding: 0.4rem 0.9rem !important;
    }
    .site-status-filter.is-active,
    .site-status-filter.btn-primary {
        box-shadow: none;
    }
    .site-status-filter .filter-denote {
        display: block;
        font-size: 10px;
        font-weight: 500;
        opacity: .75;
        line-height: 1.2;
        margin-top: 1px;
    }
    .site-status-filter.is-active .filter-denote {
        opacity: .9;
    }
    .site-status-filter .filter-main {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    #sitesFilterHint {
        min-height: 1.25rem;
    }
</style>

<div class="container-fluid">
    <h3 class="mb-4"><span id="formHeader">Add New Website</span></h3>

    <!-- Flash Messages -->
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if(($errors ?? null)?->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <button id="showFormBtn" class="btn btn-primary mb-3 shadow-sm">
        <i class="fa fa-plus"></i> Add New Website
    </button>

    <button id="showBulkRequestBtn" type="button" class="btn mb-3 shadow-sm btn-outline-secondary ms-1"
            data-bs-toggle="modal" data-bs-target="#bulkRequestModal"
            @if(!empty($openBulkRequest)) disabled title="You already have an open bulk request" @endif>
        <i class="fa fa-layer-group"></i> I want to add many sites
    </button>

    @if(!empty($awaitingDetailsCount) && $awaitingDetailsCount > 0)
        <a href="{{ route('publisher.bulk-sites.complete') }}" class="btn mb-3 shadow-sm btn-upload ms-1">
            <i class="fa fa-pen-to-square"></i> Complete details ({{ $awaitingDetailsCount }})
        </a>
    @endif

    <button id="showClaimBtn" type="button" class="btn mb-3 shadow-sm btn-outline-warning ms-1">
        <i class="fa fa-user-check"></i> Claim a website
    </button>

    @if(!empty($openBulkRequest))
        <div class="alert alert-light border mb-3">
            <strong>Bulk request #{{ $openBulkRequest->id }}</strong>
            — status: <span class="text-capitalize">{{ str_replace('_', ' ', $openBulkRequest->status) }}</span>.
            You submitted <strong>URL + price</strong> only.
            Next: our marketer adds DA/DR/traffic/language/country → you add descriptions &amp; listing details → we approve.
            @if(($openBulkRequest->estimated_count ?? 0) > 0)
                <span class="d-block small text-muted mt-1">{{ $openBulkRequest->estimated_count }} site(s) in this request.</span>
            @endif
        </div>
    @endif

    <div class="card shadow-sm border-0 d-none mb-3" id="claimCard">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h5 class="mb-1">Claim a website</h5>
                    <p class="small text-muted mb-0">
                        If another publisher listed your site, submit a claim. We’ll verify ownership using the
                        <strong>exact website name</strong> on the listing plus your proof message.
                    </p>
                </div>
                <button type="button" class="btn-close" id="closeClaimCard" aria-label="Close"></button>
            </div>
            <form id="claimWebsiteForm" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Website URL</label>
                    <input type="url" name="website_url" class="form-control" placeholder="https://example.com" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Website name (must match listing)</label>
                    <input type="text" name="website_name" class="form-control" placeholder="Exact name as shown in catalog" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact email</label>
                    <input type="email" name="contact_email" class="form-control" value="{{ auth()->user()->email }}" placeholder="you@example.com">
                </div>
                <div class="col-12">
                    <label class="form-label">Proof of ownership</label>
                    <textarea name="proof_message" class="form-control" rows="4" minlength="20" required
                              placeholder="Explain how you own this site (e.g. domain registrar email, CMS access, who listed it incorrectly…)"></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Submit claim for review</button>
                </div>
            </form>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Guided bulk: publisher submits URL + price only --}}
    <div class="modal fade" id="bulkRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="{{ route('publisher.bulk-sites.request') }}" class="modal-content" id="bulkRequestForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add many websites</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="border rounded-3 p-3 mb-3" style="background:#f7fafb;">
                        <div class="fw-semibold mb-2">How bulk onboarding works</div>
                        <ol class="small text-muted mb-0 ps-3">
                            <li class="mb-1"><strong>You</strong> add only <strong>Website URL</strong> + <strong>Price</strong> below.</li>
                            <li class="mb-1"><strong>Our marketer</strong> adds the other stats (DA, DR, traffic, language, country).</li>
                            <li class="mb-1"><strong>You</strong> come back to finish descriptions, niches, link type, and timing.</li>
                            <li><strong>We</strong> review and approve — sites stay hidden until then.</li>
                        </ol>
                    </div>

                    @error('sites')
                        <div class="alert alert-danger py-2 small">{{ $message }}</div>
                    @enderror

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Your sites (URL + price only)</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="bulkAddRowBtn">
                            <i class="fa fa-plus"></i> Add row
                        </button>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0" id="bulkUrlPriceTable">
                            <thead>
                                <tr>
                                    <th style="min-width:14rem;">Website URL</th>
                                    <th style="width:8.5rem;">Price (€)</th>
                                    <th style="width:2.5rem;"></th>
                                </tr>
                            </thead>
                            <tbody id="bulkUrlPriceBody">
                                @php
                                    $oldSites = old('sites');
                                    if (!is_array($oldSites) || count($oldSites) < 2) {
                                        $oldSites = [['url' => '', 'price' => ''], ['url' => '', 'price' => '']];
                                    }
                                @endphp
                                @foreach($oldSites as $i => $row)
                                    <tr class="bulk-url-price-row">
                                        <td>
                                            <input type="url" name="sites[{{ $i }}][url]"
                                                   class="form-control form-control-sm @error('sites.'.$i.'.url') is-invalid @enderror"
                                                   placeholder="https://example.com"
                                                   value="{{ $row['url'] ?? '' }}" required>
                                            @error('sites.'.$i.'.url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td>
                                            <input type="number" name="sites[{{ $i }}][price]" step="0.01" min="0"
                                                   class="form-control form-control-sm @error('sites.'.$i.'.price') is-invalid @enderror"
                                                   placeholder="99"
                                                   value="{{ $row['price'] ?? '' }}" required>
                                            @error('sites.'.$i.'.price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-danger bulk-remove-row" title="Remove row" aria-label="Remove row">&times;</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="form-text mb-3">Minimum 2 sites. One open bulk request at a time. For a single site, use <strong>Add New Website</strong>.</div>

                    <div class="mb-0">
                        <label class="form-label">Note for our team (optional)</label>
                        <textarea name="publisher_note" class="form-control @error('publisher_note') is-invalid @enderror"
                                  rows="2" maxlength="2000" placeholder="Niches, languages, or anything we should know…">{{ old('publisher_note') }}</textarea>
                        @error('publisher_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit URL + prices</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0 d-none" id="formCard">
        <div class="card-body">
            <form id="addSiteForm" class="needs-validation" novalidate method="POST" action="{{ route('publisher.sites.store') }}">
                @csrf
                <input type="hidden" name="_method" id="methodField" value="POST">

                <div class="site-wizard-steps" id="siteWizardSteps" aria-label="Add website steps">
                    <div class="site-wizard-step active" data-step="1">
                        <span class="wiz-num">1</span>
                        <span>Site basics</span>
                    </div>
                    <div class="site-wizard-step" data-step="2">
                        <span class="wiz-num">2</span>
                        <span>Market + niche</span>
                    </div>
                    <div class="site-wizard-step" data-step="3">
                        <span class="wiz-num">3</span>
                        <span>Pricing + policies</span>
                    </div>
                </div>

                <!-- Step 1: Site basics -->
                <div class="wizard-pane active" data-wizard-pane="1">
                    <div class="form-section">
                        <span class="form-section-title">Identity</span>
                        <div class="row g-3 g-form">
                            <div class="col-md-4">
                                <label class="form-label">Site Name <span class="req" aria-hidden="true">*</span></label>
                                <input type="text" name="siteName" id="siteName" class="form-control" placeholder="Enter site name" value="{{ old('siteName') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Site URL <span class="req" aria-hidden="true">*</span></label>
                                <input type="url" name="siteUrl" id="siteUrl" class="form-control" placeholder="eg:https://example.com" value="{{ old('siteUrl') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Example URL <span class="req" aria-hidden="true">*</span></label>
                                <input type="url" name="exampleUrl" id="exampleUrl" class="form-control" placeholder="https://example.com/example" value="{{ old('exampleUrl') }}" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <span class="form-section-title">Authority metrics</span>
                        <div class="row bg-light p-3 rounded g-3 g-form">
                            <div class="col-md-3">
                                <label class="form-label">
                                    <abbr class="metric-abbr text-decoration-none" title="Moz Domain Authority — site strength score from 0–100">DA</abbr>
                                    (Domain Authority) <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="number" name="da" id="da" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old('da') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <abbr class="metric-abbr text-decoration-none" title="Ahrefs Domain Rating — backlink strength score from 0–100">DR</abbr>
                                    (Domain Rating) <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="number" name="dr" id="dr" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old('dr') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Traffic <span class="req" aria-hidden="true">*</span></label>
                                <input type="number" name="traffic" id="traffic" class="form-control" placeholder="Visitors/month" value="{{ old('traffic') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Turnaround Time <span class="req" aria-hidden="true">*</span></label>
                                @php $turnaroundDefault = old('turnaround_time', '3days'); @endphp
                                <select name="turnaround_time" id="turnaroundTime" class="form-select" required>
                                    <option value="24h" {{ $turnaroundDefault == '24h' ? 'selected' : '' }}>24 Hours</option>
                                    <option value="48h" {{ $turnaroundDefault == '48h' ? 'selected' : '' }}>48 Hours</option>
                                    <option value="3days" {{ $turnaroundDefault == '3days' ? 'selected' : '' }}>3 Days</option>
                                    <option value="5days" {{ $turnaroundDefault == '5days' ? 'selected' : '' }}>5 Days</option>
                                    <option value="7days" {{ $turnaroundDefault == '7days' ? 'selected' : '' }}>7 Days</option>
                                </select>
                                <div class="help-text">Estimated time to publish after order confirmation</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <span class="form-section-title">Description</span>
                        <div class="row">
                            <div class="col-12">
                                <label class="form-label">Site Description (500 words max) <span class="req" aria-hidden="true">*</span></label>
                                <div id="quillEditor" class="border rounded" style="height: 200px;">{{ old('siteDescription') }}</div>
                                <input type="hidden" name="siteDescription" id="siteDescription" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Market + niche -->
                <div class="wizard-pane" data-wizard-pane="2">
                    <div class="form-section">
                        <span class="form-section-title">Market & niche</span>
                        <div class="row bg-light p-3 rounded g-3 g-form">
                            <div class="col-md-4">
                                <label class="form-label">Language <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="language" id="selectedLanguage" value="{{ old('language', is_array(old('languages')) ? (old('languages')[0] ?? '') : old('languages')) }}">
                                <div class="single-select-wrapper" id="languageWrapper">
                                    <div class="single-select-input" id="languageInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select language">
                                        <span class="single-select-value" id="languageValue"><span class="single-select-placeholder">Select language...</span></span>
                                        <span class="single-select-arrow" aria-hidden="true">▾</span>
                                    </div>
                                    <div class="single-select-dropdown" id="languageDropdown">
                                        <div class="single-select-search">
                                            <input type="text" placeholder="Search languages..." id="languageSearch">
                                        </div>
                                        <div class="single-select-options" id="languageOptions">
                                            @foreach($languages as $language)
                                                <div class="single-select-option" data-value="{{ strtolower($language->code) }}" data-label="{{ $language->name }}">{{ $language->name }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Pick one language.
                                    <i class="fa fa-circle-question text-muted"
                                       role="button"
                                       tabindex="0"
                                       aria-label="Help: country options update to markets that match this language"
                                       data-bs-toggle="tooltip"
                                       data-bs-placement="top"
                                       title="Country options update to markets that match this language (e.g. German → DE, AT, CH)."></i>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Country / Market <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="country" id="selectedCountry" value="{{ old('country', is_array(old('countries')) ? (old('countries')[0] ?? '') : old('countries')) }}">
                                <div class="single-select-wrapper" id="countryWrapper">
                                    <div class="single-select-input" id="countryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select country or market">
                                        <span class="single-select-value" id="countryValue"><span class="single-select-placeholder">Select language first...</span></span>
                                        <span class="single-select-arrow" aria-hidden="true">▾</span>
                                    </div>
                                    <div class="single-select-dropdown" id="countryDropdown">
                                        <div class="single-select-search">
                                            <input type="text" placeholder="Search countries..." id="countrySearch">
                                        </div>
                                        <div class="single-select-options" id="countryOptions">
                                            @foreach($countries as $country)
                                                <div class="single-select-option" data-value="{{ strtolower($country->code) }}" data-label="{{ $country->name }}">{{ $country->name }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div id="relatedCountriesHint" class="mt-2 small text-muted"></div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    One country only.
                                    <i class="fa fa-circle-question text-muted"
                                       role="button"
                                       tabindex="0"
                                       aria-label="Help: matching markets are selectable"
                                       data-bs-toggle="tooltip"
                                       data-bs-placement="top"
                                       title="Matching markets are selectable. Other countries stay visible but faded."></i>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categories <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="categories" id="selectedCategories" value="{{ is_array(old('categories')) ? implode('|', old('categories')) : old('categories') }}">
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
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Topic niches for this market.
                                    <i class="fa fa-circle-question text-muted"
                                       role="button"
                                       tabindex="0"
                                       aria-label="Help: pick up to 7 topic categories for this market"
                                       data-bs-toggle="tooltip"
                                       data-bs-placement="top"
                                       title="Example: Tech for German / Austria. Pick up to 7 categories."></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Pricing + policies -->
                <div class="wizard-pane" data-wizard-pane="3">
                    <div class="form-section">
                        <span class="form-section-title">Pricing & link policy</span>
                        <div class="row bg-light p-3 rounded g-3 g-form">
                            <div class="col-md-4">
                                <label class="form-label">Price (€) <span class="req" aria-hidden="true">*</span></label>
                                <input type="number" name="price" id="price" class="form-control" placeholder="Enter price" min="0" step="0.01" value="{{ old('price') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Publication Duration <span class="req" aria-hidden="true">*</span></label>
                                <select name="publicationTime" id="publicationTime" class="form-select" required>
                                    <option value="">Select Duration</option>
                                    <option value="6months" {{ old('publicationTime') == '6months' ? 'selected' : '' }}>6 Months</option>
                                    <option value="1year" {{ old('publicationTime') == '1year' ? 'selected' : '' }}>1 Year</option>
                                    <option value="permanent" {{ old('publicationTime') == 'permanent' ? 'selected' : '' }}>Permanent</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Link Type <span class="req" aria-hidden="true">*</span></label>
                                <div class="d-flex gap-3 mt-2">
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

                    <div class="form-section">
                        <span class="form-section-title">Tags</span>
                        @php
                            $oldTag = old('site_tag');
                            if ($oldTag === null) {
                                if (old('sponsored')) $oldTag = 'sponsored';
                                elseif (old('partner_material')) $oldTag = 'partner_material';
                                elseif (old('as_you_prefer')) $oldTag = 'as_you_prefer';
                                else $oldTag = '';
                            }
                        @endphp
                        <div class="row bg-light p-3 rounded g-3 g-form align-items-center">
                            <div class="col-12">
                                <label class="form-label mb-2">Optional — choose one</label>
                                <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="Site tag">
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagNone" class="form-check-input" value="" {{ $oldTag === '' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagNone">None</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagSponsored" class="form-check-input" value="sponsored" {{ $oldTag === 'sponsored' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagSponsored">Sponsored</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagPartnerMaterial" class="form-check-input" value="partner_material" {{ $oldTag === 'partner_material' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagPartnerMaterial">Partner Materials</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" name="site_tag" id="tagAsYouPrefer" class="form-check-input" value="as_you_prefer" {{ $oldTag === 'as_you_prefer' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="tagAsYouPrefer">As You Prefer</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        @php
                            $hasSensitiveOld = collect(['crypto','trading','CBD','forex'])->contains(fn ($t) => filled(old("sensitive.$t")) || filled(old("price_sensitive.$t")));
                        @endphp
                        <button type="button"
                                class="disclosure-toggle"
                                id="sensitiveDisclosureBtn"
                                aria-expanded="{{ $hasSensitiveOld ? 'true' : 'false' }}"
                                aria-controls="sensitiveDisclosurePanel">
                            <i class="fa fa-chevron-{{ $hasSensitiveOld ? 'down' : 'right' }}" aria-hidden="true"></i>
                            Sensitive topics (optional)
                        </button>
                        <p class="small text-muted mb-0 mt-1">Only open if you accept crypto, trading, CBD, or forex placements.</p>
                        <div class="disclosure-panel" id="sensitiveDisclosurePanel" @unless($hasSensitiveOld) hidden @endunless>
                            <div class="row bg-light p-3 rounded mt-2">
                                <div class="col-12">
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach(['crypto','trading','CBD','forex'] as $topic)
                                        <div class="me-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="sensitive[{{ $topic }}]" class="form-check-input sensitive-checkbox" id="sensitive{{ $topic }}" {{ old("sensitive.$topic") ? 'checked' : '' }}>
                                                <label class="form-check-label" for="sensitive{{ $topic }}">{{ ucfirst($topic) }}</label>
                                            </div>
                                            <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 sensitive-price" placeholder="Extra price (€)" value="{{ old("price_sensitive.$topic") }}">
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wizard-nav">
                    <div>
                        <button type="button" class="btn btn-cta-secondary shadow-sm d-none" id="wizardBackBtn">Back</button>
                        <span class="wizard-draft-hint ms-2" id="wizardDraftHint"></span>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-cta-tertiary shadow-sm" id="closeBtn">Close</button>
                        <button type="button" class="btn btn-primary shadow-sm" id="wizardNextBtn">Next</button>
                        <button type="submit" class="btn btn-primary shadow-sm d-none" id="submitBtn">Submit</button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <div class="mt-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h4 class="mb-0">Your Sites</h4>
            <div class="d-inline-flex flex-wrap gap-2" role="group" aria-label="Filter sites by status">
                <button type="button" class="btn btn-sm site-status-filter is-active" data-status="active" id="sitesFilterActive" aria-pressed="true">
                    <span class="filter-main">
                        Active <span class="badge text-bg-secondary" id="sitesActiveCount">0</span>
                    </span>
                    <span class="filter-denote">Approved / live</span>
                </button>
                <button type="button" class="btn btn-sm site-status-filter" data-status="pending" id="sitesFilterPending" aria-pressed="false">
                    <span class="filter-main">
                        Pending <span class="badge text-bg-secondary" id="sitesPendingCount">0</span>
                    </span>
                    <span class="filter-denote">Awaiting approval</span>
                </button>
            </div>
        </div>
        <p class="small text-muted mb-2" id="sitesFilterHint">Approved and live sites on your panel.</p>
        <input type="text" id="siteSearch" class="form-control table-search" placeholder="Search sites...">
        <div id="sitesTableWrapper" class="mt-3"></div>
    </div>
</div>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="{{ asset('js/jquery-3.6.0.min.js') }}?v={{ @filemtime(public_path('js/jquery-3.6.0.min.js')) ?: '1' }}"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const addBtn = $('#showFormBtn');
const bulkBtn = $('#showBulkRequestBtn');
const bulkCard = $(); // legacy self-serve bulk UI removed
const closeBulkBtn = $();
const formCard = $('#formCard');
const submitBtn = $('#submitBtn');
const closeBtn = $('#closeBtn');
const formHeaderSpan = $('#formHeader');
const claimCard = $('#claimCard');

@if(session('open_bulk_request_modal'))
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('bulkRequestModal');
    if (el && window.bootstrap) {
        new bootstrap.Modal(el).show();
    }
});
@endif

(function () {
    const body = document.getElementById('bulkUrlPriceBody');
    const addBtn = document.getElementById('bulkAddRowBtn');
    if (!body || !addBtn) return;

    function reindexRows() {
        Array.from(body.querySelectorAll('.bulk-url-price-row')).forEach(function (tr, i) {
            const url = tr.querySelector('input[name*="[url]"]');
            const price = tr.querySelector('input[name*="[price]"]');
            if (url) url.name = 'sites[' + i + '][url]';
            if (price) price.name = 'sites[' + i + '][price]';
        });
    }

    function syncRemoveButtons() {
        const rows = body.querySelectorAll('.bulk-url-price-row');
        rows.forEach(function (tr) {
            const btn = tr.querySelector('.bulk-remove-row');
            if (btn) btn.disabled = rows.length <= 2;
        });
    }

    addBtn.addEventListener('click', function () {
        if (body.querySelectorAll('.bulk-url-price-row').length >= 200) return;
        const tr = document.createElement('tr');
        tr.className = 'bulk-url-price-row';
        tr.innerHTML =
            '<td><input type="url" class="form-control form-control-sm" placeholder="https://example.com" required></td>' +
            '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="99" required></td>' +
            '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger bulk-remove-row" title="Remove row" aria-label="Remove row">&times;</button></td>';
        body.appendChild(tr);
        reindexRows();
        syncRemoveButtons();
    });

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('.bulk-remove-row');
        if (!btn) return;
        const rows = body.querySelectorAll('.bulk-url-price-row');
        if (rows.length <= 2) return;
        btn.closest('tr')?.remove();
        reindexRows();
        syncRemoveButtons();
    });

    reindexRows();
    syncRemoveButtons();
})();

// Quill editor (guarded so a CDN/CSP failure cannot break the sites table loader)
var quill = null;
if (typeof Quill !== 'undefined' && document.getElementById('quillEditor')) {
    try {
        quill = new Quill('#quillEditor', {
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
    } catch (e) {
        console.warn('Quill init failed', e);
    }
}

// FR1 — progressive disclosure for sensitive topics
$('#sensitiveDisclosureBtn').on('click', function () {
    const panel = $('#sensitiveDisclosurePanel');
    const open = panel.prop('hidden');
    panel.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
    $(this).find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
});

// FR3 — inline validation on blur
function markFieldValidity(el) {
    if (!el || !el.checkValidity) return;
    if (el.value === '' && !el.required) {
        el.classList.remove('is-invalid', 'is-valid');
        return;
    }
    if (el.checkValidity()) {
        el.classList.remove('is-invalid');
        el.classList.add('is-valid');
    } else {
        el.classList.remove('is-valid');
        el.classList.add('is-invalid');
    }
}

$('#addSiteForm').on('blur', 'input[required], select[required]', function () {
    markFieldValidity(this);
});

// ==================== Single Select Component for Country & Language ====================
function initSingleSelect(wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId, valueDisplayId, placeholderText = 'Select option...') {
    let selectedValue = '';
    let selectedLabel = '';
    let allowedValues = null; // null = all options available
    const wrapper = $(`#${wrapperId}`);
    const input = $(`#${inputId}`);
    const dropdown = $(`#${dropdownId}`);
    const optionsContainer = $(`#${optionsId}`);
    const hiddenInput = $(`#${hiddenInputId}`);
    const searchInput = $(`#${searchId}`);
    const valueDisplay = $(`#${valueDisplayId}`);

    function updateDisplay() {
        if (selectedValue && selectedLabel) {
            valueDisplay.html(selectedLabel);
        } else {
            valueDisplay.html(`<span class="single-select-placeholder">${placeholderText}</span>`);
        }
        hiddenInput.val(selectedValue || '');
        hiddenInput.trigger('change');
        updateOptionsHighlight();
    }

    function selectOption(value, label) {
        selectedValue = value;
        selectedLabel = label;
        updateDisplay();
        dropdown.removeClass('show');
    }

    function updateOptionsHighlight() {
        optionsContainer.find('.single-select-option').each(function() {
            const $this = $(this);
            const value = String($this.data('value'));
            $this.toggleClass('selected', selectedValue === value);
        });
    }

    function isOptionAllowed(value) {
        if (allowedValues === null) return true;
        return allowedValues.includes(String(value).toLowerCase());
    }

    function filterOptions(searchTerm) {
        const term = (searchTerm || '').toLowerCase();
        optionsContainer.find('.single-select-option').each(function() {
            const $this = $(this);
            const value = String($this.data('value')).toLowerCase();
            const text = $this.text().toLowerCase();
            const matchesSearch = term === '' || text.includes(term);
            const matchesAllowed = isOptionAllowed(value);

            // Keep all countries visible (search can still hide); fade non-matching markets
            $this.toggleClass('hidden', !matchesSearch);
            $this.toggleClass('disabled', allowedValues !== null && !matchesAllowed);
            $this.toggleClass('suggested', allowedValues !== null && matchesAllowed);
        });

        // Suggested (allowed) countries first, then faded ones
        if (allowedValues !== null) {
            const opts = optionsContainer.find('.single-select-option').get();
            opts.sort((a, b) => {
                const aAllowed = $(a).hasClass('suggested') ? 0 : 1;
                const bAllowed = $(b).hasClass('suggested') ? 0 : 1;
                if (aAllowed !== bAllowed) return aAllowed - bAllowed;
                return $(a).text().localeCompare($(b).text());
            });
            optionsContainer.append(opts);
        }
    }

    function setAllowedValues(values) {
        allowedValues = values === null ? null : values.map(v => String(v).toLowerCase());
        // Clear selection if current value is no longer allowed
        if (selectedValue && allowedValues !== null && !allowedValues.includes(String(selectedValue).toLowerCase())) {
            selectedValue = '';
            selectedLabel = '';
            updateDisplay();
        } else {
            filterOptions(searchInput.val());
            updateOptionsHighlight();
        }
    }

    function setPlaceholder(text) {
        placeholderText = text;
        if (!selectedValue) {
            valueDisplay.html(`<span class="single-select-placeholder">${placeholderText}</span>`);
        }
    }

    input.on('click', function(e) {
        e.stopPropagation();
        $('.single-select-dropdown').not(dropdown).removeClass('show');
        $('.single-select-input').not(input).attr('aria-expanded', 'false');
        $('.multi-select-dropdown').removeClass('show');
        dropdown.toggleClass('show');
        const open = dropdown.hasClass('show');
        input.attr('aria-expanded', open ? 'true' : 'false');
        if (open) {
            searchInput.focus();
            filterOptions('');
        }
    });

    $(document).on('click', function() {
        $('.single-select-dropdown').removeClass('show');
        $('.single-select-input').attr('aria-expanded', 'false');
    });

    dropdown.on('click', function(e) {
        e.stopPropagation();
    });

    searchInput.on('keyup', function() {
        filterOptions($(this).val());
    });

    optionsContainer.on('click', '.single-select-option', function(e) {
        const $option = $(this);
        if ($option.hasClass('hidden') || $option.hasClass('disabled')) return;
        selectOption(String($option.data('value')), $option.data('label'));
    });

    function setSelectedValue(value, label) {
        selectedValue = value ? String(value).toLowerCase() : '';
        selectedLabel = label || '';
        updateDisplay();
    }

    function getSelectedValue() {
        return selectedValue;
    }

    function clearSelection() {
        selectedValue = '';
        selectedLabel = '';
        updateDisplay();
        searchInput.val('');
        filterOptions('');
    }

    // Initial placeholder
    updateDisplay();

    return {
        selectOption,
        setSelectedValue,
        getSelectedValue,
        clearSelection,
        setAllowedValues,
        setPlaceholder,
        filterOptions
    };
}

// ==================== Multi-Select Component for Categories ====================
function initMultiSelect(wrapperId, inputId, dropdownId, optionsId, hiddenInputId, searchId, maxSelections = null, placeholderText = 'Select options...') {
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
            input.html(`<span class="multi-select-placeholder">${placeholderText}</span>`);
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
        
        // Prefer `|` so category names that contain commas stay intact
        hiddenInput.val(selectedItems.map(item => item.value).join('|'));
        hiddenInput.trigger('change');
    }
    
    // Function to add an item
    function addItem(value, label) {
        if (maxSelections && selectedItems.length >= maxSelections) {
            Swal.fire({
                icon: 'warning',
                title: `Maximum ${maxSelections} selections allowed`,
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

window.languageCountryMap = @json($languageCountryMap ?? new \stdClass());
const languageCountryMap = window.languageCountryMap;

// Single language + single country (country list filtered by language)
let languageSingleSelect = initSingleSelect(
    'languageWrapper', 'languageInput', 'languageDropdown', 'languageOptions',
    'selectedLanguage', 'languageSearch', 'languageValue', 'Select language...'
);
let countrySingleSelect = initSingleSelect(
    'countryWrapper', 'countryInput', 'countryDropdown', 'countryOptions',
    'selectedCountry', 'countrySearch', 'countryValue', 'Select language first...'
);

function relatedCountryCodesForLanguage(langCode) {
    const related = [];
    (languageCountryMap[langCode] || []).forEach(item => {
        const code = typeof item === 'string' ? item : (item.code || '');
        if (code) related.push(String(code).toLowerCase());
    });
    return Array.from(new Set(related));
}

function applyLanguageCountryFilter(langCode, { clearCountry = true } = {}) {
    const hint = $('#relatedCountriesHint');
    if (!langCode) {
        // No language yet: all countries visible but not selectable
        countrySingleSelect.setAllowedValues([]);
        countrySingleSelect.setPlaceholder('Select language first...');
        if (clearCountry) countrySingleSelect.clearSelection();
        hint.text('Select a language first.');
        return;
    }

    const relatedCodes = relatedCountryCodesForLanguage(langCode);
    // Fade non-matching countries (keep them visible, non-selectable)
    countrySingleSelect.setAllowedValues(relatedCodes.length ? relatedCodes : null);
    countrySingleSelect.setPlaceholder('Select country...');
    if (clearCountry) countrySingleSelect.clearSelection();

    if (relatedCodes.length) {
        const labels = relatedCodes.map(code => {
            const opt = $(`#countryOptions .single-select-option[data-value="${code}"]`);
            return opt.length ? opt.data('label') : code.toUpperCase();
        });
        hint.text('Suggested: ' + labels.join(', '));
    } else {
        hint.text('All markets selectable for this language.');
    }
}

let syncingLanguageCountry = false;
$('#selectedLanguage').on('change', function() {
    if (syncingLanguageCountry) return;
    applyLanguageCountryFilter($(this).val() || '', { clearCountry: true });
});

// Start with countries locked until language is chosen
applyLanguageCountryFilter('', { clearCountry: false });

@php
    $oldLanguage = old('language', is_array(old('languages')) ? (old('languages')[0] ?? null) : old('languages'));
    $oldCountry = old('country', is_array(old('countries')) ? (old('countries')[0] ?? null) : old('countries'));
@endphp
@if($oldLanguage)
    (function() {
        const code = @json(strtolower((string) $oldLanguage));
        const opt = $(`#languageOptions .single-select-option[data-value="${code}"]`);
        if (opt.length) {
            syncingLanguageCountry = true;
            languageSingleSelect.setSelectedValue(code, opt.data('label'));
            applyLanguageCountryFilter(code, { clearCountry: false });
            syncingLanguageCountry = false;
        }
    })();
@endif
@if($oldCountry)
    (function() {
        const code = @json(strtolower((string) $oldCountry));
        const opt = $(`#countryOptions .single-select-option[data-value="${code}"]`);
        if (opt.length) {
            countrySingleSelect.setSelectedValue(code, opt.data('label'));
        }
    })();
@endif

// Initialize Category Multi Select (max 7)
let categoryMultiSelect = initMultiSelect('categoryWrapper', 'categoryInput', 'categoryDropdown', 'categoryOptions', 'selectedCategories', 'categorySearch', 7, 'Select categories (max 7)...');
@if(old('categories'))
    let oldCategories = @json(old('categories', []));
    if (typeof oldCategories === 'string') {
        oldCategories = String(oldCategories).split(/[|,]/).map(v => v.trim()).filter(Boolean);
    }
    if (oldCategories && oldCategories.length) {
        $('#categoryOptions .multi-select-option').each(function() {
            let val = $(this).data('value');
            if (oldCategories.includes(val)) {
                categoryMultiSelect.addItem(val, $(this).data('label'));
            }
        });
    }
@endif

const SITE_DRAFT_KEY = 'publisher_add_site_draft_v1';
let wizardStep = 1;
const wizardTotalSteps = 3;

function setWizardStep(step) {
    wizardStep = Math.max(1, Math.min(wizardTotalSteps, step));
    $('.wizard-pane').removeClass('active');
    $(`.wizard-pane[data-wizard-pane="${wizardStep}"]`).addClass('active');

    $('#siteWizardSteps .site-wizard-step').each(function() {
        const s = parseInt($(this).data('step'), 10);
        $(this).toggleClass('active', s === wizardStep);
        $(this).toggleClass('done', s < wizardStep);
    });

    $('#wizardBackBtn').toggleClass('d-none', wizardStep === 1);
    $('#wizardNextBtn').toggleClass('d-none', wizardStep === wizardTotalSteps);
    $('#submitBtn').toggleClass('d-none', wizardStep !== wizardTotalSteps);
}

function saveSiteDraft() {
    try {
        const draft = {
            siteName: $('#siteName').val(),
            siteUrl: $('#siteUrl').val(),
            exampleUrl: $('#exampleUrl').val(),
            da: $('#da').val(),
            dr: $('#dr').val(),
            traffic: $('#traffic').val(),
            turnaround_time: $('#turnaroundTime').val(),
            price: $('#price').val(),
            publicationTime: $('#publicationTime').val(),
            link_type: $('input[name="link_type"]:checked').val() || 'dofollow',
            language: $('#selectedLanguage').val(),
            country: $('#selectedCountry').val(),
            categories: $('#selectedCategories').val(),
            site_tag: $('input[name="site_tag"]:checked').val() || '',
            siteDescription: quill ? quill.root.innerHTML : ($('#siteDescription').val() || ''),
            sensitive: {},
            price_sensitive: {},
            step: wizardStep,
            savedAt: Date.now()
        };
        ['crypto','trading','CBD','forex'].forEach(topic => {
            draft.sensitive[topic] = $(`#sensitive${topic}`).is(':checked');
            draft.price_sensitive[topic] = $(`input[name="price_sensitive[${topic}]"]`).val();
        });
        localStorage.setItem(SITE_DRAFT_KEY, JSON.stringify(draft));
        $('#wizardDraftHint').text('Draft saved');
    } catch (e) {
        // ignore storage errors
    }
}

function clearSiteDraft() {
    try { localStorage.removeItem(SITE_DRAFT_KEY); } catch (e) {}
    $('#wizardDraftHint').text('');
}

function loadSiteDraft() {
    try {
        const raw = localStorage.getItem(SITE_DRAFT_KEY);
        if (!raw) return false;
        const draft = JSON.parse(raw);
        if (!draft || typeof draft !== 'object') return false;

        $('#siteName').val(draft.siteName || '');
        $('#siteUrl').val(draft.siteUrl || '');
        $('#exampleUrl').val(draft.exampleUrl || '');
        $('#da').val(draft.da || '');
        $('#dr').val(draft.dr || '');
        $('#traffic').val(draft.traffic || '');
        $('#turnaroundTime').val(draft.turnaround_time || '3days');
        $('#price').val(draft.price || '');
        $('#publicationTime').val(draft.publicationTime || '');
        if (draft.link_type === 'nofollow') {
            $('#linkTypeNofollow').prop('checked', true);
        } else {
            $('#linkTypeDofollow').prop('checked', true);
        }
        const draftTag = draft.site_tag
            || (draft.sponsored ? 'sponsored' : '')
            || (draft.partner_material ? 'partner_material' : '')
            || (draft.as_you_prefer ? 'as_you_prefer' : '');
        $(`input[name="site_tag"][value="${draftTag}"]`).prop('checked', true);
        if (!draftTag) $('#tagNone').prop('checked', true);
        if (draft.siteDescription) {
            if (quill) quill.root.innerHTML = draft.siteDescription;
            $('#siteDescription').val(draft.siteDescription);
        }
        ['crypto','trading','CBD','forex'].forEach(topic => {
            $(`#sensitive${topic}`).prop('checked', !!(draft.sensitive && draft.sensitive[topic]));
            $(`input[name="price_sensitive[${topic}]"]`).val((draft.price_sensitive && draft.price_sensitive[topic]) || '');
        });

        if (draft.language) {
            const langOpt = $(`#languageOptions .single-select-option[data-value="${draft.language}"]`);
            if (langOpt.length) {
                languageSingleSelect.setSelectedValue(draft.language, langOpt.data('label'));
            }
        }
        if (draft.country) {
            const countryOpt = $(`#countryOptions .single-select-option[data-value="${draft.country}"]`);
            if (countryOpt.length) {
                countrySingleSelect.setSelectedValue(draft.country, countryOpt.data('label'));
            }
        }
        if (draft.categories) {
            const raw = String(draft.categories);
            const cats = raw.split(raw.includes('|') ? '|' : ',').map(c => c.trim()).filter(Boolean);
            categoryMultiSelect.clearSelections();
            cats.forEach(val => {
                const opt = $(`#categoryOptions .multi-select-option[data-value="${val}"]`);
                if (opt.length) categoryMultiSelect.addItem(val, opt.data('label'));
            });
        }

        setWizardStep(draft.step || 1);
        $('#wizardDraftHint').text('Draft restored');
        return true;
    } catch (e) {
        return false;
    }
}

function validateWizardStep(step) {
    const pane = $(`.wizard-pane[data-wizard-pane="${step}"]`);
    let ok = true;
    let message = '';

    pane.find('input[required], select[required]').each(function() {
        if (!this.checkValidity()) {
            ok = false;
            $(this).addClass('is-invalid');
            if (!message) message = this.validationMessage || 'Please fill in all required fields.';
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    if (step === 1) {
        const desc = quill ? (quill.root.innerText || '').trim() : ($('#siteDescription').val() || '').replace(/<[^>]+>/g,'').trim();
        if (!desc) {
            ok = false;
            message = message || 'Please enter a site description.';
        } else {
            if (quill) $('#siteDescription').val(quill.root.innerHTML);
        }
    }

    if (step === 2) {
        if (!languageSingleSelect.getSelectedValue()) {
            ok = false;
            message = message || 'Please select a language.';
        }
        if (!countrySingleSelect.getSelectedValue()) {
            ok = false;
            message = message || 'Please select a country / market.';
        }
        if (categoryMultiSelect.getSelectedItems().length === 0) {
            ok = false;
            message = message || 'Please select at least one category.';
        }
    }

    if (!ok) {
        Swal.fire({ icon: 'error', title: 'Almost there', text: message || 'Please complete this step.' });
    }
    return ok;
}

$('#wizardNextBtn').on('click', function() {
    if (!validateWizardStep(wizardStep)) return;
    saveSiteDraft();
    setWizardStep(wizardStep + 1);
});

$('#wizardBackBtn').on('click', function() {
    saveSiteDraft();
    setWizardStep(wizardStep - 1);
});

$('#addSiteForm').on('change input', 'input, select, textarea', function() {
    if ($('#methodField').val() === 'POST') {
        saveSiteDraft();
    }
});
if (quill) {
    quill.on('text-change', function() {
        if ($('#methodField').val() === 'POST') {
            saveSiteDraft();
        }
    });
}

// Toggle form for CREATE
addBtn.on('click', function() {
    bulkCard.addClass('d-none');
    formCard.toggleClass('d-none');
    let isOpen = !formCard.hasClass('d-none');

    addBtn.toggleClass('d-none', isOpen);
    bulkBtn.toggleClass('d-none', isOpen);
    formHeaderSpan.text('Add New Website');

    if(isOpen){
        // Reset form for new site
        $('#addSiteForm')[0].reset();
        $('#methodField').val('POST');
        $('#addSiteForm').attr('action', '{{ route("publisher.sites.store") }}');
        if (quill) quill.root.innerHTML = '';
        submitBtn.prop('disabled', false).text('Submit');
        
        // Reset selects
        languageSingleSelect.clearSelection();
        countrySingleSelect.clearSelection();
        applyLanguageCountryFilter('', { clearCountry: false });
        categoryMultiSelect.clearSelections();
        
        // Enable site name and URL for create
        $('#siteName').prop('disabled', false);
        $('#siteUrl').prop('disabled', false);
        $('.readonly-note').remove();

        const restored = loadSiteDraft();
        if (!restored) {
            setWizardStep(1);
            $('#wizardDraftHint').text('');
        }
    }
});

bulkBtn.on('click', function() {
    // Opens #bulkRequestModal via data-bs-toggle; keep single-site form closed.
    formCard.addClass('d-none');
    closeBtn.addClass('d-none');
    claimCard.addClass('d-none');
    formHeaderSpan.text('Add New Website');
});

closeBulkBtn.on('click', function() {});

// Toggle form for CREATE — keep existing addBtn handler below
$('#addSiteForm').submit(function(e){
    if (quill) $('#siteDescription').val(quill.root.innerHTML);

    for (let s = 1; s <= wizardTotalSteps; s++) {
        if (!validateWizardStep(s)) {
            e.preventDefault();
            setWizardStep(s);
            return;
        }
    }

    let form = this;
    // Temporarily show all panes so native validity covers every required field
    $('.wizard-pane').addClass('active');
    if(!form.checkValidity()){
        e.preventDefault();
        e.stopPropagation();
        $(form).addClass('was-validated');
        for (let s = 1; s <= wizardTotalSteps; s++) {
            const pane = $(`.wizard-pane[data-wizard-pane="${s}"]`);
            if (pane.find('input:invalid, select:invalid').length > 0) {
                setWizardStep(s);
                return;
            }
        }
        setWizardStep(wizardStep);
    } else {
        if ($('#methodField').val() !== 'PUT') {
            clearSiteDraft();
        }
        submitBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Saving...');
    }
});

// Fetch sites
let sitesStatusFilter = 'active';

function syncSitesFilterUi(pendingCount, activeCount, status) {
    const pendingCountEl = document.getElementById('sitesPendingCount');
    const activeCountEl = document.getElementById('sitesActiveCount');
    const hint = document.getElementById('sitesFilterHint');

    if (pendingCountEl) pendingCountEl.textContent = String(pendingCount ?? 0);
    if (activeCountEl) activeCountEl.textContent = String(activeCount ?? 0);

    document.querySelectorAll('.site-status-filter').forEach(function (btn) {
        const on = btn.getAttribute('data-status') === status;
        btn.classList.toggle('is-active', on);
        btn.classList.remove('btn-primary', 'btn-outline-secondary');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    if (hint) {
        hint.textContent = status === 'active'
            ? 'Approved and live sites on your panel.'
            : 'Sites waiting for admin approval.';
    }
}

function initSitesTableTooltips(root) {
    if (!window.bootstrap || !bootstrap.Tooltip) return;
    (root || document).querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        const existing = bootstrap.Tooltip.getInstance(el);
        if (existing) existing.dispose();
        new bootstrap.Tooltip(el, {
            customClass: 'yt-tooltip',
            delay: { show: 200, hide: 0 },
            container: 'body',
        });
    });
}

function fetchSites(page = 1, query = '') {
    $('#sitesTableWrapper').html('<div class="text-muted">Loading...</div>');

    $.ajax({
        url: '{{ route("publisher.sites.ajax") }}',
        method: 'GET',
        dataType: 'html',
        data: { page: page, query: query, status: sitesStatusFilter },
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
        success: function(res) {
            const html = (res || '').trim();
            // Session expiry / middleware redirect often returns the login page HTML.
            if (html.includes('name="password"') && html.includes('/login')) {
                $('#sitesTableWrapper').html(
                    '<div class="text-center py-4">' +
                    '<div class="text-danger mb-2">Your session expired. Please refresh and sign in again.</div>' +
                    '<a class="btn btn-sm btn-primary" href="' + @json(route('login')) + '">Sign in</a>' +
                    '</div>'
                );
                return;
            }
            if (html === '') {
                $('#sitesTableWrapper').html(
                    '<div class="ui-empty-state text-center mx-auto py-4" style="max-width:420px">' +
                    '<div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,#185054)" aria-hidden="true"><i class="fa-solid fa-globe"></i></div>' +
                    '<h5 class="mb-2">No websites listed yet</h5>' +
                    '<p class="text-muted mb-3">Add your first site so advertisers can find and order from you.</p>' +
                    '<button type="button" class="btn btn-primary btn-sm" id="emptyAddSiteCta"><i class="fa fa-plus"></i> Add New Website</button>' +
                    '</div>'
                );
                $('#emptyAddSiteCta').on('click', function(){ $('#showFormBtn').trigger('click'); });
            } else {
                $('#sitesTableWrapper').html(html);
                const meta = document.getElementById('sitesStatusMeta');
                if (meta) {
                    syncSitesFilterUi(
                        parseInt(meta.getAttribute('data-pending') || '0', 10),
                        parseInt(meta.getAttribute('data-active') || '0', 10),
                        meta.getAttribute('data-status') || sitesStatusFilter
                    );
                }
                initSitesTableTooltips(document.getElementById('sitesTableWrapper'));
            }
        },
        error: function(xhr) {
            const message = xhr.status === 403
                ? 'You do not have access to load sites. Switch to the publisher role and retry.'
                : (xhr.status === 401 || xhr.status === 419)
                    ? 'Your session expired. Please refresh and sign in again.'
                    : 'Failed to load sites.';
            $('#sitesTableWrapper').html(
                '<div class="text-center py-4">' +
                '<div class="text-danger mb-2">' + message + '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-primary" id="retrySitesBtn">Retry</button>' +
                '</div>'
            );
            $('#retrySitesBtn').on('click', function () {
                fetchSites(page, query);
            });
        }
    });
}
window.loadSites = fetchSites;

// Debounced search
let delayTimer;
$(document).ready(function(){
    fetchSites();

    $(document).on('click', '.site-status-filter', function () {
        const next = this.getAttribute('data-status') || 'active';
        if (next === sitesStatusFilter) return;
        sitesStatusFilter = next;
        syncSitesFilterUi(
            parseInt(document.getElementById('sitesPendingCount')?.textContent || '0', 10),
            parseInt(document.getElementById('sitesActiveCount')?.textContent || '0', 10),
            sitesStatusFilter
        );
        fetchSites(1, $('#siteSearch').val());
    });

    $('#siteSearch').on('keyup', function(){
        clearTimeout(delayTimer);
        delayTimer = setTimeout(() => {
            fetchSites(1, $(this).val());
        }, 400);
    });

    $(document).on('click', '.pagination a', function(e){
        const href = $(this).attr('href');
        if (!href || href === '#') return;
        e.preventDefault();
        let page = $(this).data('page');
        if (!page) {
            try {
                page = new URL(href, window.location.origin).searchParams.get('page') || 1;
            } catch (err) {
                page = 1;
            }
        }
        fetchSites(page, $('#siteSearch').val());
    });

    $(document).on('click', '.pagination li[data-page]', function(){
        const page = $(this).data('page');
        if (page) fetchSites(page, $('#siteSearch').val());
    });
});

// Close form
closeBtn.on('click', function(){
    if ($('#methodField').val() !== 'PUT') {
        saveSiteDraft();
    }
    formCard.addClass('d-none');
    addBtn.removeClass('d-none');
    bulkBtn.removeClass('d-none');
    formHeaderSpan.text('Add New Website');
    $('#addSiteForm')[0].reset();
    if (quill) quill.root.innerHTML = '';
    $('.tag-checkbox').prop('checked', false);
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    
    // Reset selects
    languageSingleSelect.clearSelection();
    countrySingleSelect.clearSelection();
    applyLanguageCountryFilter('', { clearCountry: false });
    categoryMultiSelect.clearSelections();
    
    $('#siteName').prop('disabled', false);
    $('#siteUrl').prop('disabled', false);
    $('.readonly-note').remove();
    setWizardStep(1);
    $('#wizardDraftHint').text('');
});

// Expand / hide site details (handlers live here so AJAX table HTML needs no scripts)
$(document).on('click', '.action-view', function(e) {
    e.stopPropagation();
    const id = $(this).data('id');
    const expandRow = $('#expand-' + id);
    expandRow.toggleClass('expanded');

    const icon = $(this).find('i');
    const text = $(this).find('.btn-text');
    if (expandRow.hasClass('expanded')) {
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
        text.text('Hide');
    } else {
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
        text.text('View');
    }
});

$(document).on('click', '.btn-delete', function() {
    const form = $(this).closest('form');
    Swal.fire({
        title: 'Are you sure?',
        text: 'This site will be deleted permanently!',
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

// Edit functionality - Prefill all values
$(document).on('click', '.btn-edit', function() {
    const site = $(this).data('site');
    if (!site || !site.id) {
        Swal.fire({ icon: 'error', title: 'Could not edit', text: 'Site data failed to load. Refresh and try again.' });
        return;
    }

    // Show form
    $('#formCard').removeClass('d-none');
    $('#showFormBtn').addClass('d-none');
    $('#showBulkRequestBtn').addClass('d-none');
    $('#formHeader').text('Edit Site: ' + site.site_name);
    setWizardStep(1);
    $('#wizardDraftHint').text('');
    
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
    
    // Tags (single radio)
    let siteTag = '';
    if (site.sponsored == 1) siteTag = 'sponsored';
    else if (site.partner_material == 1) siteTag = 'partner_material';
    else if (site.as_you_prefer == 1) siteTag = 'as_you_prefer';
    $(`input[name="site_tag"][value="${siteTag}"]`).prop('checked', true);
    if (!siteTag) $('#tagNone').prop('checked', true);
    
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
    
    // Set Language (1) then Country (1) filtered by that language
    const langCode = (site.language || site.language_code || (Array.isArray(site.languages) ? site.languages[0] : null) || '').toString().toLowerCase();
    const countryCode = (site.country || site.country_code || (Array.isArray(site.countries) ? site.countries[0] : null) || '').toString().toLowerCase();

    syncingLanguageCountry = true;
    languageSingleSelect.clearSelection();
    countrySingleSelect.clearSelection();
    if (langCode) {
        const langOpt = $(`#languageOptions .single-select-option[data-value="${langCode}"]`);
        if (langOpt.length) {
            languageSingleSelect.setSelectedValue(langCode, langOpt.data('label'));
            applyLanguageCountryFilter(langCode, { clearCountry: false });
        }
    } else {
        applyLanguageCountryFilter('', { clearCountry: false });
    }
    if (countryCode) {
        const countryOpt = $(`#countryOptions .single-select-option[data-value="${countryCode}"]`);
        if (countryOpt.length) {
            countrySingleSelect.setSelectedValue(countryCode, countryOpt.data('label'));
        }
    }
    syncingLanguageCountry = false;
    
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
        // Fallback for pipe/comma-separated legacy category column
        const raw = String(site.category);
        raw.split(raw.includes('|') ? '|' : ',').map(v => v.trim()).filter(Boolean).forEach(categoryName => {
            let option = $(`#categoryOptions .multi-select-option[data-value="${categoryName}"]`);
            if (option.length) {
                categoryMultiSelect.addItem(categoryName, option.data('label'));
            }
        });
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

/* —— Claim a website —— */
$('#showClaimBtn').on('click', function () {
    formCard.addClass('d-none');
    bulkCard.addClass('d-none');
    claimCard.toggleClass('d-none');
    formHeaderSpan.text(claimCard.hasClass('d-none') ? 'Add New Website' : 'Claim a website');
});
$('#closeClaimCard').on('click', function () {
    claimCard.addClass('d-none');
    formHeaderSpan.text('Add New Website');
});
$('#claimWebsiteForm').on('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    const payload = Object.fromEntries(fd.entries());
    const res = await fetch(`{{ route('publisher.sites.claim') }}`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) {
        this.reset();
        claimCard.addClass('d-none');
    }
});

/* —— Site promotions: Feature / Discount / Bulk —— */
const promoCsrf = '{{ csrf_token() }}';

async function startFeatureStripeCheckout(siteId) {
    const res = await fetch(`/publisher/sites/${siteId}/feature/checkout`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    const data = await res.json().catch(() => ({}));
    if (data.success && data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
    }
    Swal.fire({ icon: 'error', title: 'Checkout unavailable', text: data.message || 'Could not start card payment.' });
}

$(document).on('click', '.btn-feature-site', async function () {
    const id = $(this).data('id');
    const name = $(this).data('name');
    let wallet = { feature_price: 10, feature_days: 7, balance: 0, top_up_url: @json(route('publisher.balance')), stripe_available: true };
    try {
        const w = await fetch(`{{ route('publisher.promotions.wallet') }}`, { headers: { 'Accept': 'application/json' }});
        wallet = await w.json();
    } catch (e) {}

    const canWallet = Number(wallet.balance || 0) >= Number(wallet.feature_price || 10);
    const result = await Swal.fire({
        title: 'Feature this website?',
        html: `<p>Feature <strong>${name}</strong> for <strong>${wallet.feature_days || 7} days</strong> to boost catalog visibility.</p>
               <p class="mb-1">Cost: <strong>€${Number(wallet.feature_price || 10).toFixed(2)}</strong></p>
               <p class="small text-muted">Publisher balance: €${Number(wallet.balance || 0).toFixed(2)}</p>
               <p class="small text-muted">Pay from earnings, or pay securely by card with Stripe.</p>`,
        showDenyButton: !!wallet.stripe_available,
        showCancelButton: true,
        confirmButtonText: canWallet ? 'Pay from wallet' : 'Use card / top up',
        denyButtonText: wallet.stripe_available ? 'Pay by card' : undefined,
        confirmButtonColor: '#185054',
        denyButtonColor: '#635bff',
    });

    if (result.isDenied) {
        return startFeatureStripeCheckout(id);
    }
    if (!result.isConfirmed) return;
    if (!canWallet) {
        return Swal.fire({
            icon: 'info',
            title: 'Insufficient balance',
            html: `Top up your wallet or pay by card.<br><br>
                   <button type="button" class="btn btn-sm btn-primary me-1" id="swalPayCard">Pay by card</button>
                   <a class="btn btn-sm btn-outline-secondary" href="${wallet.top_up_url}">Add Funds</a>`,
            didOpen: () => {
                document.getElementById('swalPayCard')?.addEventListener('click', () => startFeatureStripeCheckout(id));
            },
            showConfirmButton: false,
            showCancelButton: true,
        });
    }

    const res = await fetch(`/publisher/sites/${id}/feature`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    const data = await res.json();
    if (data.success) {
        Swal.fire({ icon: 'success', title: 'Featured!', text: data.message });
        if (data.success) { fetchSites(); }
    } else if (data.needs_top_up) {
        Swal.fire({
            icon: 'info',
            title: 'Top up or pay by card',
            html: `${(typeof escapeHtml === 'function' ? escapeHtml(data.message || '') : String(data.message || '').replace(/</g,'&lt;'))}<br><br>
                   <button type="button" class="btn btn-sm btn-primary me-1" id="swalPayCard2">Pay by card (€${Number(wallet.feature_price || 10).toFixed(2)})</button>
                   <a class="btn btn-sm btn-outline-secondary" href="${wallet.top_up_url}">Add Funds</a>`,
            didOpen: () => {
                document.getElementById('swalPayCard2')?.addEventListener('click', () => startFeatureStripeCheckout(id));
            },
            showConfirmButton: false,
            showCancelButton: true,
        });
    } else {
        Swal.fire({ icon: 'error', title: 'Could not feature', text: data.message || 'Failed' });
    }
});

$(document).on('click', '.btn-discount-site', async function () {
    const id = $(this).data('id');
    const name = $(this).data('name');
    const current = $(this).data('percent');
    const { value: form } = await Swal.fire({
        title: 'Set timed discount',
        html: `<p class="mb-2" style="font-size:14px;color:#334155;line-height:1.45;">
                   Lower the price of <strong>${name}</strong> for a limited time so advertisers see a clear sale in the catalog.
               </p>
               <p class="small text-muted mb-2">Ends automatically after the days you choose. You’ll get an email when it ends.</p>
               <label for="swal-pct" class="small fw-semibold d-block text-start ms-3 mb-0">Discount percent (1–70)</label>
               <input id="swal-pct" type="number" min="1" max="70" class="swal2-input" placeholder="e.g. 15" value="${current || 15}" aria-label="Discount percent">
               <label for="swal-days" class="small fw-semibold d-block text-start ms-3 mb-0 mt-2">Days active (1–90)</label>
               <input id="swal-days" type="number" min="1" max="90" class="swal2-input" placeholder="e.g. 7" value="7" aria-label="Days active">`,
        showCancelButton: true,
        confirmButtonText: 'Publish discount',
        confirmButtonColor: '#185054',
        preConfirm: () => ({
            percent: document.getElementById('swal-pct').value,
            days: document.getElementById('swal-days').value,
        }),
    });
    if (!form) return;
    const res = await fetch(`/publisher/sites/${id}/discount`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(form),
    });
    const data = await res.json();
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) { fetchSites(); }
});

$(document).on('click', '.btn-discount-clear', async function () {
    const id = $(this).data('id');
    const ok = await Swal.fire({ title: 'End this discount now?', showCancelButton: true, confirmButtonText: 'End discount', confirmButtonColor: '#b91c1c' });
    if (!ok.isConfirmed) return;
    const res = await fetch(`/publisher/sites/${id}/discount`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': promoCsrf, 'Accept': 'application/json' },
    });
    const data = await res.json();
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) { fetchSites(); }
});

$(document).on('click', '.btn-bulk-join', async function () {
    const id = $(this).data('id');
    const { value: percent } = await Swal.fire({
        title: 'Join bulk discount program',
        input: 'number',
        inputLabel: 'Discount % for 3–5 articles (10–15)',
        inputValue: 10,
        inputAttributes: { min: 10, max: 15, step: 1 },
        showCancelButton: true,
        confirmButtonText: 'Join',
        confirmButtonColor: '#185054',
    });
    if (percent === undefined || percent === null || percent === '') return;
    const res = await fetch(`/publisher/sites/${id}/bulk-discount`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': promoCsrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ percent }),
    });
    const data = await res.json();
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) { fetchSites(); }
});

$(document).on('click', '.btn-bulk-leave', async function () {
    const id = $(this).data('id');
    const ok = await Swal.fire({ title: 'Leave bulk program?', showCancelButton: true, confirmButtonText: 'Leave' });
    if (!ok.isConfirmed) return;
    const res = await fetch(`/publisher/sites/${id}/bulk-discount`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': promoCsrf, 'Accept': 'application/json' },
    });
    const data = await res.json();
    Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) { fetchSites(); }
});
</script>

@endsection