@extends('publisher.layouts.app')

@section('title', 'My Sites')

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
        border-color: #0b6266;
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

    /* Scoped to add/bulk cards only — never clip the AJAX sites table. */
    #formCard table,
    #bulkCard table,
    #claimCard table {
        width: 100%;
        border-collapse: collapse;
        background-color: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 6px rgba(50,50,93,0.05);
    }

    #formCard th, #formCard td,
    #bulkCard th, #bulkCard td,
    #claimCard th, #claimCard td {
        padding: 12px 15px;
        border-bottom: 1px solid #e6ebf1;
        text-align: left;
    }

    #formCard th,
    #bulkCard th,
    #claimCard th {
        background-color: #f6f9fc;
        font-weight: 600;
        color: #525f7f;
    }

    #sitesTableWrapper {
        min-height: 80px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    #sitesTableWrapper .sites-responsive-table {
        width: 100%;
        min-width: 1100px;
        table-layout: fixed;
    }

    #sitesTableWrapper .sites-responsive-table th,
    #sitesTableWrapper .sites-responsive-table td {
        padding: 12px 10px;
        vertical-align: middle;
    }

    #sitesTableWrapper .sites-responsive-table td[data-label="Category"] {
        max-width: 220px;
        white-space: normal;
        word-break: break-word;
    }

    #sitesTableWrapper .sites-responsive-table td[data-label="Actions"] {
        min-width: 220px;
        white-space: normal;
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
        background-color: #0b6266;
        color: white;
        border-color: #0b6266;
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
        border-color: #3aaeb2;
        background: rgba(58, 174, 178, 0.08);
        color: #0b6266;
    }
    .site-wizard-step.active .wiz-num {
        background: #0b6266;
        color: #fff;
    }
    .site-wizard-step.done {
        border-color: #c8ebe9;
        color: #0b6266;
    }
    .site-wizard-step.done .wiz-num {
        background: #4ECDCB;
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

    .btn-primary {
        background-color: #0b6266;
        border-color: #0b6266;
    }

    .btn-primary:hover {
        background-color: #3aaeb2;
        border-color: #3aaeb2;
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
        border-color: #0b6266;
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
        border-color: #0b6266;
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
        color: #0b6266;
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
        border-color: #0b6266;
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
        color: #0b6266;
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
            table-layout: auto;
        }

        #sitesTableWrapper .sites-responsive-table td[data-label="Category"] {
            max-width: none;
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
            text-align: right !important;
        }

        #sitesTableWrapper .sites-responsive-table tr.main-row td::before {
            content: attr(data-label);
            font-weight: 600;
            color: #64748b;
            text-align: left;
            flex-shrink: 0;
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

    .site-status-filter-group {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .site-status-filter {
        display: inline-flex !important;
        align-items: center;
        gap: 6px;
        line-height: 1.2;
        padding: 0.4rem 0.9rem !important;
        background: #fff;
        border: 1px solid #c5d4d6;
        color: #334155;
        box-shadow: none;
    }
    .site-status-filter:hover {
        background: #f1f7f7;
        border-color: #9ec5c8;
        color: #123f42;
    }
    .site-status-filter.is-active {
        background: #0f766e;
        border-color: #0f766e;
        color: #fff;
        font-weight: 600;
        box-shadow: none;
    }
    .site-status-filter.is-active:hover {
        background: #0d9488;
        border-color: #0d9488;
        color: #fff;
    }
    .site-status-filter.is-active .badge {
        background: rgba(255, 255, 255, 0.22) !important;
        color: #fff !important;
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
    <h1 class="h3 mb-1"><span id="formHeader">My Sites</span></h1>
    <p class="small text-muted mb-3" id="sitesAddDoorsHint">
        <strong>Add New Website</strong> — one site, you fill every field.
        <strong>I want to add many sites</strong> — send URL + price; we add metrics, you finish details.
        <strong>Bulk Import (Agency)</strong> — upload a full CSV when you already have DA, niches, and descriptions.
    </p>

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

    <button id="showBulkRequestBtn" type="button" class="btn mb-3 shadow-sm btn-outline-secondary ms-1"
            data-bs-toggle="modal" data-bs-target="#bulkRequestModal"
            @if(!empty($openBulkRequest)) disabled title="You already have an open bulk request" @endif>
        <i class="fa fa-layer-group"></i> I want to add many sites
    </button>

    <button id="showBulkBtn" type="button" class="btn mb-3 shadow-sm btn-outline-primary ms-1">
        <i class="fa fa-file-csv"></i> Bulk Import (Agency)
    </button>

    <button id="showClaimBtn" type="button" class="btn mb-3 shadow-sm btn-outline-warning ms-1">
        <i class="fa fa-user-check"></i> Claim a website
    </button>
    <a href="{{ route('site-claims.index') }}" class="btn mb-3 shadow-sm btn-outline-secondary ms-1" id="myClaimsLink">
        <i class="fa fa-list"></i> My claims
    </a>

    @if(!empty($awaitingDetailsCount) && $awaitingDetailsCount > 0)
        <a href="{{ route('publisher.bulk-sites.complete') }}" class="btn mb-3 shadow-sm btn-upload ms-1" id="bulkCompleteDetailsBtn">
            <i class="fa fa-pen-to-square"></i> Complete details ({{ $awaitingDetailsCount }})
        </a>
    @endif
    @if(!empty($detailsCompleteCount) && $detailsCompleteCount > 0)
        <a href="{{ route('publisher.bulk-sites.review') }}" class="btn mb-3 shadow-sm btn-outline-primary ms-1" id="bulkReviewSubmitBtn">
            <i class="fa fa-clipboard-check"></i> Review &amp; submit ({{ $detailsCompleteCount }})
        </a>
    @endif

    @if(!empty($openBulkRequest))
        @php
            $bulkNextStep = 'Next: our marketer adds DA/DR/traffic/language/country/niches → you add descriptions & listing details → we approve.';
            if (($awaitingDetailsCount ?? 0) > 0) {
                $bulkNextStep = 'Next: add descriptions and listing details with Complete details, then we approve.';
            } elseif (($detailsCompleteCount ?? 0) > 0) {
                $bulkNextStep = 'Next: review and submit your listings, then we approve.';
            }
        @endphp
        <div class="alert alert-light border mb-3">
            <strong>Bulk request #{{ $openBulkRequest->id }}</strong>
            — status: <span class="text-capitalize">{{ $openBulkRequest->statusLabel() }}</span>.
            You submitted <strong>URL + price</strong> only — track progress under
            <a href="{{ route('publisher.websites', ['status' => 'pending']) }}" class="fw-semibold">Pending</a>.
            {{ $bulkNextStep }}
            @if(($openBulkRequest->estimated_count ?? 0) > 0)
                <span class="d-block small text-muted mt-1">{{ $openBulkRequest->estimated_count }} site(s) in this request.</span>
            @endif
        </div>
    @endif

    {{-- Guided bulk: publisher submits URL + price only (marketing fills metrics) --}}
    <div class="modal fade" id="bulkRequestModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form method="POST" action="{{ route('publisher.bulk-sites.request') }}" class="modal-content" id="bulkRequestForm" novalidate>
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add many websites</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="border rounded-3 p-3 mb-3" style="background:#f7fafb;">
                        <div class="fw-semibold mb-2">How bulk onboarding works</div>
                        <ol class="small text-muted mb-0 ps-3">
                            <li class="mb-1"><strong>You</strong> add only <strong>Website URL</strong> + <strong>Price</strong> (type, paste, or upload a 2-column sheet).</li>
                            <li class="mb-1"><strong>Our marketer</strong> adds stats and niches (DA, DR, traffic, language, country, niches).</li>
                            <li class="mb-1"><strong>You</strong> finish descriptions, link type, and timing, then review &amp; submit.</li>
                            <li><strong>We</strong> review and approve — sites stay hidden until then.</li>
                        </ol>
                    </div>

                    @error('sites')
                        <div class="alert alert-danger py-2 small">{{ $message }}</div>
                    @enderror

                    <div class="mb-3 border rounded-3 p-3">
                        <div class="fw-semibold mb-2">Import URL + price</div>
                        <p class="small text-muted mb-3 mb-md-2">
                            Upload a CSV/TSV with <strong>two columns</strong> (Website URL, Price), or paste the same from Excel/Sheets.
                            Header row optional. Excel: <em>File → Save As → CSV</em>, or copy both columns and paste below.
                        </p>

                        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                            <label class="btn btn-sm btn-outline-primary mb-0" for="bulkSheetFile">
                                <i class="fa fa-file-csv me-1"></i> Upload sheet (CSV / TSV)
                            </label>
                            <input type="file" id="bulkSheetFile" class="d-none"
                                   accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values,text/plain">
                            <a href="#" id="bulkSheetTemplateBtn" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-download me-1"></i> Sample CSV
                            </a>
                            <span class="form-text mb-0" id="bulkSheetFileName"></span>
                        </div>

                        <label class="form-label mb-1" for="bulkPasteUrls">Paste into the box, then click Fill rows</label>
                        <textarea id="bulkPasteUrls" class="form-control form-control-sm font-monospace" rows="5"
                                  placeholder="https://site-one.com,99&#10;https://site-two.com,150&#10;&#10;# Excel: copy two columns (URL + Price) and paste here&#10;# URLs only (one per line) also work — add prices in the table"></textarea>
                        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                            <button type="button" class="btn btn-sm btn-primary" id="bulkPasteUrlsBtn">
                                <i class="fa fa-clipboard-list me-1"></i> Fill rows from paste
                            </button>
                            <span class="form-text mb-0">Formats: <code>url,price</code> · tab from Excel · <code>url price</code> · URLs only</span>
                        </div>
                        <div class="small text-success mt-1 d-none" id="bulkPasteUrlsSuccess" role="status"></div>
                        <div class="small text-danger mt-1 d-none" id="bulkPasteUrlsError" role="alert"></div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Your sites (URL + price only)</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="bulkAddRowBtn">
                            <i class="fa fa-plus"></i> Add row
                        </button>
                    </div>

                    <div class="bulk-url-price-list mb-3" id="bulkUrlPriceTable">
                        <div id="bulkUrlPriceBody">
                            @php
                                $oldSites = old('sites');
                                if (!is_array($oldSites) || count($oldSites) < 2) {
                                    $oldSites = [['url' => '', 'price' => ''], ['url' => '', 'price' => '']];
                                }
                                $openedFirstEmpty = false;
                            @endphp
                            @foreach($oldSites as $i => $row)
                                @php
                                    $oldUrl = old_text('sites.'.$i.'.url');
                                    $oldPrice = old_text('sites.'.$i.'.price');
                                    $filledCount = (trim($oldUrl) !== '' ? 1 : 0) + (trim($oldPrice) !== '' ? 1 : 0);
                                    $rowHasErrors = $errors->has('sites.'.$i.'.url') || $errors->has('sites.'.$i.'.price');
                                    $openAsFirstEmpty = $filledCount === 0 && ! $rowHasErrors && ! $openedFirstEmpty;
                                    $rowOpen = $rowHasErrors || $filledCount > 0 || $openAsFirstEmpty;
                                    if ($openAsFirstEmpty) {
                                        $openedFirstEmpty = true;
                                    }
                                    $chipLabel = $filledCount === 0
                                        ? 'Empty'
                                        : ($filledCount === 2 ? 'Ready' : '1/2 filled');
                                    $chipClass = $filledCount === 0
                                        ? 'is-empty'
                                        : ($filledCount === 2 ? 'is-ready' : 'is-partial');
                                    $summaryUrl = trim($oldUrl) !== '' ? $oldUrl : 'Website URL';
                                    $summaryPrice = trim($oldPrice) !== '' ? '€'.$oldPrice : 'No price';
                                @endphp
                                <details class="bulk-url-price-row" @if($rowOpen) open @endif>
                                    <summary class="bulk-url-price-row__summary">
                                        <span class="bulk-url-price-row__identity">
                                            <span class="fw-semibold text-break" data-bulk-url-label>{{ $summaryUrl }}</span>
                                        </span>
                                        <span class="bulk-url-price-row__meta">
                                            <span class="text-nowrap" data-bulk-price-label>{{ $summaryPrice }}</span>
                                            <span class="bulk-url-price-row__chip {{ $chipClass }}" data-bulk-url-price-chip>{{ $chipLabel }}</span>
                                        </span>
                                    </summary>
                                    <div class="bulk-url-price-row__body">
                                        <div class="bulk-url-price-row__fields">
                                            <div class="bulk-url-price-field">
                                                <label class="form-label" for="bulk-url-{{ $i }}">Website URL <span class="text-danger">*</span></label>
                                                <input type="url"
                                                       id="bulk-url-{{ $i }}"
                                                       name="sites[{{ $i }}][url]"
                                                       class="form-control @error('sites.'.$i.'.url') is-invalid @enderror"
                                                       placeholder="https://example.com"
                                                       value="{{ $oldUrl }}">
                                                @error('sites.'.$i.'.url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                            <div class="bulk-url-price-field bulk-url-price-field--price">
                                                <label class="form-label" for="bulk-price-{{ $i }}">Price (€) <span class="text-danger">*</span></label>
                                                <input type="number"
                                                       id="bulk-price-{{ $i }}"
                                                       name="sites[{{ $i }}][price]"
                                                       step="0.01"
                                                       min="0"
                                                       class="form-control @error('sites.'.$i.'.price') is-invalid @enderror"
                                                       placeholder="99"
                                                       value="{{ $oldPrice }}">
                                                @error('sites.'.$i.'.price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger bulk-remove-row" title="Remove row" aria-label="Remove row">Remove</button>
                                    </div>
                                </details>
                            @endforeach
                        </div>
                        <div class="small text-danger mt-2 d-none" id="bulkUrlPriceError" role="alert"></div>
                    </div>
                    <div class="form-text mb-3">Minimum 2 sites. One open bulk request at a time. For a single site, use <strong>Add New Website</strong> (you fill every field). Agencies that already have DA, niches, and descriptions can use <strong>Bulk Import (Agency)</strong>.</div>

                    <div class="mb-0">
                        <label class="form-label">Note for our team (optional)</label>
                        <textarea name="publisher_note" class="form-control @error('publisher_note') is-invalid @enderror"
                                  rows="2" maxlength="2000" placeholder="Niches, languages, or anything we should know…">{{ old_text('publisher_note') }}</textarea>
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
                    <input type="text" name="website_url" class="form-control" placeholder="example.com or https://example.com" required>
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
                    <button type="submit" class="btn btn-warning">Submit claim for review</button>
                </div>
            </form>
        </div>
    </div>

    @php
        $myClaims = \App\Models\SiteClaim::query()
            ->with('site:id,site_name,domain,site_url,publisher_id')
            ->where('claimer_id', auth()->id())
            ->latest('id')
            ->limit(10)
            ->get();
        \App\Models\SiteClaim::applyCatalogIdentity($myClaims, auth()->user());
    @endphp
    @if($myClaims->isNotEmpty())
    <div class="card shadow-sm border-0 mb-3" id="myClaimsPanel">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0"><i class="fa fa-user-check me-1"></i> Your ownership claims</h5>
                <span class="small text-muted">We email you after each review.</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Website</th>
                            <th>Name match</th>
                            <th>Status</th>
                            <th>Reviewed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($myClaims as $claim)
                            @php
                                $statusClass = match($claim->status) {
                                    'approved' => 'bg-success',
                                    'rejected' => 'bg-danger',
                                    default => 'bg-warning text-dark',
                                };
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $claim->display_name }}</div>
                                    <div class="small text-muted">{{ $claim->display_host }}</div>
                                    @if($claim->status !== 'pending' && filled($claim->admin_notes))
                                        <div class="small text-muted fst-italic">Note: {{ $claim->admin_notes }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($claim->name_matches)
                                        <span class="badge bg-success-subtle text-success border">Matches</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-dark border">Mismatch</span>
                                    @endif
                                </td>
                                <td><span class="badge {{ $statusClass }}">{{ ucfirst($claim->status) }}</span></td>
                                <td class="small text-muted">{{ optional($claim->reviewed_at)->diffForHumans() ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
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
                    <li><code>country</code> / <code>language</code> = one 2-letter code each (e.g. <code>at</code> + <code>de</code> for German in Austria)</li>
                    <li>Legacy columns <code>countries</code> / <code>languages</code> still accepted (first code only)</li>
                    <li><code>categories</code> = exact category names, separated by <code>|</code> (max 7)</li>
                    <li><code>turnaround_time</code> = <code>24h</code>, <code>48h</code>, <code>3days</code>, <code>5days</code>, or <code>7days</code></li>
                    <li><code>publication_time</code> = <code>6months</code>, <code>1year</code>, or <code>permanent</code></li>
                    <li><code>link_type</code> = <code>dofollow</code> or <code>nofollow</code></li>
                    <li><code>description</code> must be at least 50 characters</li>
                    <li>Flags (<code>sponsored</code>, <code>partner_material</code>, <code>as_you_prefer</code>) use <code>1</code> / <code>0</code> — set only one</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('publisher.sites.bulk-import') }}" enctype="multipart/form-data">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label">CSV file</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div class="col-md-5">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1" id="bulkDryRun" name="dry_run">
                            <label class="form-check-label" for="bulkDryRun">Dry run (validate only — nothing saved)</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">
                                <i class="fa fa-upload me-1"></i> Upload &amp; Import
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="closeBulkBtn">Close</button>
                        </div>
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
                                <input type="text" name="siteName" id="siteName" class="form-control" placeholder="Enter site name" value="{{ old_text('siteName') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Site URL <span class="req" aria-hidden="true">*</span></label>
                                <input type="url" name="siteUrl" id="siteUrl" class="form-control" placeholder="eg:https://example.com" value="{{ old_text('siteUrl') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Example URL <span class="req" aria-hidden="true">*</span></label>
                                <input type="url" name="exampleUrl" id="exampleUrl" class="form-control" placeholder="https://example.com/example" value="{{ old_text('exampleUrl') }}" required>
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
                                <input type="number" name="da" id="da" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old_text('da') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">
                                    <abbr class="metric-abbr text-decoration-none" title="Ahrefs Domain Rating — backlink strength score from 0–100">DR</abbr>
                                    (Domain Rating) <span class="req" aria-hidden="true">*</span>
                                </label>
                                <input type="number" name="dr" id="dr" class="form-control" placeholder="0-100" min="0" max="100" value="{{ old_text('dr') }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Traffic <span class="req" aria-hidden="true">*</span></label>
                                <input type="number" name="traffic" id="traffic" class="form-control" placeholder="Visitors/month" value="{{ old_text('traffic') }}" required>
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
                                <div id="quillEditor" class="border rounded" style="height: 200px;">{!! old_text('siteDescription') !!}</div>
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
                                <label class="form-label">Country / Market <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="country" id="selectedCountry" value="{{ old_text('country', old_text('countries')) }}">
                                <div class="single-select-wrapper" id="countryWrapper">
                                    <div class="single-select-input" id="countryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select country or market">
                                        <span class="single-select-value" id="countryValue"><span class="single-select-placeholder">Select country...</span></span>
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
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Pick country first.
                                    <i class="fa fa-circle-question text-muted"
                                       role="button"
                                       tabindex="0"
                                       aria-label="Help: pick country first then a paired language"
                                       title="Pick the market country first. Language options then show only allowed pairs (e.g. Germany → German; UAE → Arabic or English)."></i>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Language <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="language" id="selectedLanguage" value="{{ old_text('language', old_text('languages')) }}">
                                <div class="single-select-wrapper" id="languageWrapper">
                                    <div class="single-select-input" id="languageInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select language">
                                        <span class="single-select-value" id="languageValue"><span class="single-select-placeholder">Select country first...</span></span>
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
                                <div id="relatedLanguagesHint" class="mt-2 small text-muted"></div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Only languages paired with the country.
                                    <i class="fa fa-circle-question text-muted"
                                       role="button"
                                       tabindex="0"
                                       aria-label="Help: language list follows the selected country"
                                       title="Germany allows German only. Gulf markets allow Arabic and English."></i>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categories <span class="req" aria-hidden="true">*</span></label>
                                <input type="hidden" name="categories" id="selectedCategories" value="{{ implode('|', \App\Models\Category::normalizeNicheInputs(old('categories', []))) }}">
                                <div class="multi-select-wrapper" id="categoryWrapper" data-multi-select="category">
                                    <div class="multi-select-input" id="categoryInput" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select categories">
                                        <span class="multi-select-placeholder">Select categories (max 7)...</span>
                                    </div>
                                    <div class="multi-select-dropdown" id="categoryDropdown" role="listbox" aria-multiselectable="true">
                                        <div class="multi-select-search">
                                            <input type="text" placeholder="Type to search categories…" id="categorySearch" autocomplete="off" aria-label="Search categories">
                                        </div>
                                        <div class="multi-select-options" id="categoryOptions">
                                            @foreach($categories as $categoryName)
                                                <div class="multi-select-option" role="option" data-value="{{ $categoryName }}" data-label="{{ $categoryName }}">{{ $categoryName }}</div>
                                            @endforeach
                                        </div>
                                        <div class="multi-select-empty d-none" id="categoryEmpty" role="status">No categories found</div>
                                    </div>
                                </div>
                                <div class="help-text mt-1 d-flex align-items-center gap-1">
                                    Topic niches for this market.
                                    <i class="fa fa-circle-question text-muted"
                                       role="button"
                                       tabindex="0"
                                       aria-label="Help: pick up to 7 topic categories for this market"
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
                                <input type="number" name="price" id="price" class="form-control" placeholder="Enter price" min="0" step="0.01" value="{{ old_text('price') }}" required>
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
                            $oldTag = \App\Support\SiteTag::normalize($oldTag) ?? '';
                        @endphp
                        <div class="row bg-light p-3 rounded g-3 g-form align-items-center">
                            <div class="col-12">
                                <label class="form-label mb-2">Optional — choose one</label>
                                <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="Site tag">
                                    @foreach(\App\Support\SiteTag::publisherFormOptions() as $value => $label)
                                        @php $tagId = $value === '' ? 'tagNone' : 'tag'.str_replace(' ', '', ucwords(str_replace('_', ' ', $value))); @endphp
                                        <div class="form-check">
                                            <input type="radio" name="site_tag" id="{{ $tagId }}" class="form-check-input" value="{{ $value }}" {{ $oldTag === $value ? 'checked' : '' }}>
                                            <label class="form-check-label" for="{{ $tagId }}">{{ $label }}</label>
                                        </div>
                                    @endforeach
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
                                                <input type="checkbox" name="sensitive[{{ $topic }}]" value="1" class="form-check-input sensitive-checkbox" id="sensitive{{ $topic }}" {{ old("sensitive.$topic") ? 'checked' : '' }}>
                                                <label class="form-check-label" for="sensitive{{ $topic }}">{{ ucfirst($topic) }}</label>
                                            </div>
                                            <input type="number" name="price_sensitive[{{ $topic }}]" class="form-control mt-1 sensitive-price" placeholder="Extra price (€)" value="{{ old_text("price_sensitive.$topic") }}" min="0" step="0.01">
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        @php
                            $homepageDays = config('site_placement.homepage_days', [1, 7, 30]);
                            $hasHomepageOld = collect($homepageDays)->contains(fn ($d) => filled(old("homepage.$d")) || filled(old("price_homepage.$d")));
                            $hasSocialOld = collect(['facebook', 'instagram', 'x'])->contains(fn ($c) => filled(old("social.$c")));
                            $hasPlacementOld = $hasHomepageOld || $hasSocialOld;
                        @endphp
                        <button type="button"
                                class="disclosure-toggle"
                                id="placementDisclosureBtn"
                                aria-expanded="{{ $hasPlacementOld ? 'true' : 'false' }}"
                                aria-controls="placementDisclosurePanel">
                            <i class="fa fa-chevron-{{ $hasPlacementOld ? 'down' : 'right' }}" aria-hidden="true"></i>
                            Homepage &amp; social promotions (optional)
                        </button>
                        <p class="small text-muted mb-0 mt-1">Leave empty if you do not offer homepage placement or social sharing. Advertisers only see what you enable.</p>
                        <div class="disclosure-panel" id="placementDisclosurePanel" @unless($hasPlacementOld) hidden @endunless>
                            <div class="row bg-light p-3 rounded mt-2 g-3">
                                <div class="col-12">
                                    <p class="fw-semibold small mb-2">Homepage placement</p>
                                    <p class="small text-muted mb-2">Offer putting the guest article on your homepage for 1, 7, or 30 days. Price €0 = Free. Leave unchecked to not offer that duration.</p>
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach($homepageDays as $days)
                                            <div class="me-3" style="min-width:140px;">
                                                <div class="form-check">
                                                    <input type="checkbox"
                                                           name="homepage[{{ $days }}]"
                                                           class="form-check-input homepage-checkbox"
                                                           id="homepage{{ $days }}"
                                                           value="1"
                                                           {{ old("homepage.$days") ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="homepage{{ $days }}">{{ $days }} day{{ $days > 1 ? 's' : '' }}</label>
                                                </div>
                                                <input type="number"
                                                       name="price_homepage[{{ $days }}]"
                                                       class="form-control mt-1 homepage-price"
                                                       placeholder="Fee (€) — 0 = Free"
                                                       value="{{ old_text("price_homepage.$days") }}"
                                                       min="0"
                                                       step="0.01"
                                                       inputmode="decimal">
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="col-12">
                                    <p class="fw-semibold small mb-2">Social media sharing</p>
                                    <p class="small text-muted mb-2">Always free for advertisers. They get whatever you check — no choice on their side. Uncheck if you will not share.</p>
                                    <div class="d-flex flex-wrap gap-3">
                                        @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X'] as $channel => $label)
                                            <div class="form-check">
                                                <input type="checkbox"
                                                       name="social[{{ $channel }}]"
                                                       class="form-check-input social-checkbox"
                                                       id="social{{ ucfirst($channel) }}"
                                                       value="1"
                                                       {{ old("social.$channel") ? 'checked' : '' }}>
                                                <label class="form-check-label" for="social{{ ucfirst($channel) }}">{{ $label }}</label>
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
                        <button type="submit" class="btn btn-primary shadow-sm d-none" id="submitBtn">Review &amp; submit</button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    {{-- Last look before the listing goes to review. The wizard splits the form
         across panes, so publishers need to see the whole listing at once. --}}
    <div class="modal fade" id="sitePreviewModal" tabindex="-1" aria-labelledby="sitePreviewLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sitePreviewLabel">Check your listing before you submit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="sitePreviewBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cta-secondary" data-bs-dismiss="modal" id="sitePreviewBackBtn">Back to edit</button>
                    <button type="button" class="btn btn-primary" id="sitePreviewConfirmBtn">Looks right — submit</button>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-5">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h4 class="mb-0">Your Sites</h4>
            <div class="d-inline-flex flex-wrap align-items-center gap-2" role="group" aria-label="Filter sites by status">
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter is-active" data-status="active" id="sitesFilterActive" aria-pressed="true">
                        <span class="filter-main">
                            Active <span class="badge text-bg-secondary" id="sitesActiveCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Active"
                        body="Approved / live sites on your panel."
                        label="What Active means"
                        placement="top"
                    />
                </div>
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter" data-status="pending" id="sitesFilterPending" aria-pressed="false">
                        <span class="filter-main">
                            Pending <span class="badge text-bg-secondary" id="sitesPendingCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Pending"
                        body="Bulk drafts with the marketer, sites that need your details, and listings waiting for admin approval."
                        label="What Pending means"
                        placement="top"
                    />
                </div>
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter" data-status="invites" id="sitesFilterInvites" aria-pressed="false">
                        <span class="filter-main">
                            Invites <span class="badge text-bg-secondary" id="sitesInviteCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Invites"
                        body="Sites our team added for you. Accept to show them in My Sites, or decline to remove them."
                        label="What Invites means"
                        placement="top"
                    />
                </div>
                <div class="site-status-filter-group">
                    <button type="button" class="btn btn-sm site-status-filter" data-status="archived" id="sitesFilterArchived" aria-pressed="false">
                        <span class="filter-main">
                            Archive <span class="badge text-bg-secondary" id="sitesArchivedCount">0</span>
                        </span>
                    </button>
                    <x-glass-tip
                        title="Archive"
                        body="Sites you hid from the catalog. Restore one to put it back on Active."
                        label="What Archive means"
                        placement="top"
                    />
                </div>
            </div>
        </div>
        <p class="small text-muted mb-2" id="sitesFilterHint">Approved and live sites on your panel.</p>
        <div class="mb-2" style="max-width: 22rem;">
            <x-slb-search-field name="site_search" id="siteSearch" placeholder="Search sites..." input-class="form-control table-search" mode="" />
        </div>
        <div id="sitesTableWrapper" class="mt-3"></div>
    </div>
</div>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<link href="{{ asset('assets/css/multi-select.css') }}?v={{ @filemtime(public_path('assets/css/multi-select.css')) ?: '1' }}" rel="stylesheet">
<link href="{{ asset('assets/css/publisher-websites.css') }}?v={{ @filemtime(public_path('assets/css/publisher-websites.css')) ?: '1' }}" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
@php
    $pwOldLanguage = old_text('language', old_text('languages'));
    $pwOldCountry = old_text('country', old_text('countries'));
    $pwOldCategories = \App\Models\Category::normalizeNicheInputs(old('categories', []));
@endphp
<script>
window.__publisherWebsitesInlineLoaded = true;

/**
 * My Sites row thumbs: walk /media → /storage (and thumb → full → cover)
 * so Hostinger broken public/storage symlinks do not blank the preview column.
 * Defined before ajax table HTML so inline onerror always finds it.
 */
window.publisherSitePreviewOnError = function (img) {
    if (!img) return;
    var chain = [];
    try {
        chain = JSON.parse(img.getAttribute('data-preview-chain') || '[]');
    } catch (e) {
        chain = [];
    }
    if (!Array.isArray(chain)) chain = [];
    var i = parseInt(img.getAttribute('data-preview-i') || '0', 10);
    if (isNaN(i) || i < 0) i = 0;
    var next = i + 1;
    if (next < chain.length && chain[next]) {
        img.setAttribute('data-preview-i', String(next));
        img.src = chain[next];
        return;
    }
    img.onerror = null;
    img.removeAttribute('src');
    var wrap = img.closest('.site-row-preview');
    if (wrap) {
        wrap.classList.add('is-empty');
        wrap.removeAttribute('data-zoom-src');
        wrap.removeAttribute('data-zoom-chain');
        wrap.innerHTML = '<i class="fa fa-image" aria-hidden="true"></i>';
    }
};
window.PublisherWebsitesConfig = {
    csrfToken: @json(csrf_token()),
    maxBulkRows: {{ (int) \App\Models\BulkSiteRequest::MAX_SITES_PER_REQUEST }},
    openBulkRequestModal: @json((bool) session('open_bulk_request_modal')),
    countryLanguageMap: @json($countryLanguageMap ?? new \stdClass()),
    languageCountryMap: @json($languageCountryMap ?? new \stdClass()),
    descMinChars: {{ (int) \App\Support\SiteDescriptionRules::MIN_CHARS }},
    descMaxWords: {{ (int) \App\Support\SiteDescriptionRules::MAX_WORDS }},
    descPlaceholder: @json(\App\Support\SiteDescriptionRules::placeholder()),
    old: {
        language: @json($pwOldLanguage ? strtolower((string) $pwOldLanguage) : null),
        country: @json($pwOldCountry ? strtolower((string) $pwOldCountry) : null),
        categories: @json($pwOldCategories),
    },
    routes: {
        ajax: @json(route('publisher.sites.ajax')),
        store: @json(route('publisher.sites.store')),
        login: @json(route('login')),
        balance: @json(route('publisher.balance')),
        promotionsWallet: @json(route('publisher.promotions.wallet')),
    },
    bulkMinPercent: {{ (int) config('site_promotions.bulk.min_percent', 10) }},
    bulkMaxPercent: {{ (int) config('site_promotions.bulk.max_percent', 80) }},
};
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!(window.PublisherWebsitesConfig && window.PublisherWebsitesConfig.openBulkRequestModal)) return;
    var el = document.getElementById('bulkRequestModal');
    if (el && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        window.bootstrap.Modal.getOrCreateInstance(el).show();
    }
});
</script>

<script>
const addBtn = $('#showFormBtn');
const bulkBtn = $('#showBulkBtn');
const bulkRequestBtn = $('#showBulkRequestBtn');
const claimBtn = $('#showClaimBtn');
const bulkCard = $('#bulkCard');
const claimCard = $('#claimCard');
const closeBulkBtn = $('#closeBulkBtn');
const formCard = $('#formCard');
const submitBtn = $('#submitBtn');
const closeBtn = $('#closeBtn');
const formHeaderSpan = $('#formHeader');
let editingLiveMarket = null;

function snapshotMarketFromForm() {
    const cats = (categoryMultiSelect && typeof categoryMultiSelect.getSelectedItems === 'function')
        ? categoryMultiSelect.getSelectedItems().map(function (c) {
            return String(c).toLowerCase().trim();
        }).filter(Boolean).sort()
        : [];
    return {
        country: String((countrySingleSelect && countrySingleSelect.getSelectedValue()) || '').toLowerCase(),
        language: String((languageSingleSelect && languageSingleSelect.getSelectedValue()) || '').toLowerCase(),
        categories: cats.join('|'),
    };
}

function marketChangedFromSnapshot() {
    if (!editingLiveMarket) return false;
    const now = snapshotMarketFromForm();
    return now.country !== editingLiveMarket.country
        || now.language !== editingLiveMarket.language
        || now.categories !== editingLiveMarket.categories;
}

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

$('#placementDisclosureBtn').on('click', function () {
    const panel = $('#placementDisclosurePanel');
    const open = panel.prop('hidden');
    panel.prop('hidden', !open);
    $(this).attr('aria-expanded', open ? 'true' : 'false');
    $(this).find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
});

function setPlacementDisclosureOpen(open) {
    const panel = $('#placementDisclosurePanel');
    const btn = $('#placementDisclosureBtn');
    if (!panel.length || !btn.length) return;
    panel.prop('hidden', !open);
    btn.attr('aria-expanded', open ? 'true' : 'false');
    btn.find('i').toggleClass('fa-chevron-right', !open).toggleClass('fa-chevron-down', open);
}

function clearHomepageSocialFields() {
    $('.homepage-checkbox').prop('checked', false);
    $('.homepage-price').val('');
    $('.social-checkbox').prop('checked', false);
}

function fillHomepageSocialFromSite(site) {
    clearHomepageSocialFields();
    let hasPlacement = false;

    let homepage = site.homepage_placement_prices || null;
    if (typeof homepage === 'string') {
        try { homepage = JSON.parse(homepage); } catch (e) { homepage = null; }
    }
    if (homepage && typeof homepage === 'object') {
        Object.keys(homepage).forEach(function (days) {
            const $cb = $(`#homepage${days}`);
            if (!$cb.length) return;
            $cb.prop('checked', true);
            $(`input[name="price_homepage[${days}]"]`).val(homepage[days]);
            hasPlacement = true;
        });
    }

    let social = site.social_promotion || null;
    if (typeof social === 'string') {
        try { social = JSON.parse(social); } catch (e) { social = null; }
    }
    if (social && typeof social === 'object') {
        ['facebook', 'instagram', 'x'].forEach(function (channel) {
            if (!social[channel]) return;
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            $(id).prop('checked', true);
            hasPlacement = true;
        });
    }

    if (hasPlacement) {
        setPlacementDisclosureOpen(true);
    }
}

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

window.countryLanguageMap = @json($countryLanguageMap ?? new \stdClass());
const countryLanguageMap = window.countryLanguageMap;

// Country first → language list filtered by allowed pairs
let countrySingleSelect = initSingleSelect(
    'countryWrapper', 'countryInput', 'countryDropdown', 'countryOptions',
    'selectedCountry', 'countrySearch', 'countryValue', 'Select country...'
);
let languageSingleSelect = initSingleSelect(
    'languageWrapper', 'languageInput', 'languageDropdown', 'languageOptions',
    'selectedLanguage', 'languageSearch', 'languageValue', 'Select country first...'
);

function relatedLanguageCodesForCountry(countryCode) {
    const related = [];
    (countryLanguageMap[countryCode] || []).forEach(item => {
        const code = typeof item === 'string' ? item : (item.code || '');
        if (code) related.push(String(code).toLowerCase());
    });
    return Array.from(new Set(related));
}

function applyCountryLanguageFilter(countryCode, { clearLanguage = true, preferLanguage = null } = {}) {
    const hint = $('#relatedLanguagesHint');
    if (!countryCode) {
        languageSingleSelect.setAllowedValues([]);
        languageSingleSelect.setPlaceholder('Select country first...');
        if (clearLanguage) languageSingleSelect.clearSelection();
        if (hint.length) hint.text('Select a country first.');
        return;
    }

    const relatedCodes = relatedLanguageCodesForCountry(countryCode);
    languageSingleSelect.setAllowedValues(relatedCodes.length ? relatedCodes : null);
    languageSingleSelect.setPlaceholder('Select language...');
    if (clearLanguage) languageSingleSelect.clearSelection();

    if (relatedCodes.length === 1) {
        const only = relatedCodes[0];
        const opt = $(`#languageOptions .single-select-option[data-value="${only}"]`);
        if (opt.length) {
            languageSingleSelect.setSelectedValue(only, opt.data('label'));
        }
        if (hint.length) hint.text('Language locked to ' + (opt.data('label') || only.toUpperCase()) + ' for this country.');
    } else if (relatedCodes.length) {
        if (preferLanguage && relatedCodes.indexOf(String(preferLanguage).toLowerCase()) !== -1) {
            const code = String(preferLanguage).toLowerCase();
            const opt = $(`#languageOptions .single-select-option[data-value="${code}"]`);
            if (opt.length) languageSingleSelect.setSelectedValue(code, opt.data('label'));
        }
        const labels = relatedCodes.map(code => {
            const opt = $(`#languageOptions .single-select-option[data-value="${code}"]`);
            return opt.length ? opt.data('label') : code.toUpperCase();
        });
        if (hint.length) hint.text('Allowed: ' + labels.join(', '));
    } else if (hint.length) {
        hint.text('No paired languages for this country.');
    }
}

let syncingCountryLanguage = false;
$('#selectedCountry').on('change', function() {
    if (syncingCountryLanguage) return;
    applyCountryLanguageFilter($(this).val() || '', { clearLanguage: true });
});

// Start with languages locked until country is chosen
applyCountryLanguageFilter('', { clearLanguage: false });

@php
    $pwOldLanguage = old_text('language', old_text('languages'));
    $pwOldCountry = old_text('country', old_text('countries'));
    $pwOldCategories = \App\Models\Category::normalizeNicheInputs(old('categories', []));
@endphp
@if($pwOldCountry)
    (function() {
        const code = @json(strtolower((string) $pwOldCountry));
        const opt = $(`#countryOptions .single-select-option[data-value="${code}"]`);
        if (opt.length) {
            syncingCountryLanguage = true;
            countrySingleSelect.setSelectedValue(code, opt.data('label'));
            applyCountryLanguageFilter(code, {
                clearLanguage: false,
                preferLanguage: @json($pwOldLanguage ? strtolower((string) $pwOldLanguage) : null)
            });
            syncingCountryLanguage = false;
        }
    })();
@elseif($pwOldLanguage)
    (function() {
        // Legacy: language without country — leave language locked until country is picked.
        applyCountryLanguageFilter('', { clearLanguage: true });
    })();
@endif

// Initialize Category Multi Select (max 7)
let categoryMultiSelect = initMultiSelect('categoryWrapper', 'categoryInput', 'categoryDropdown', 'categoryOptions', 'selectedCategories', 'categorySearch', 7, 'Select categories (max 7)...');
@if($pwOldCategories !== [])
    let oldCategories = @json($pwOldCategories);
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
            homepage: {},
            price_homepage: {},
            social: {},
            step: wizardStep,
            savedAt: Date.now()
        };
        ['crypto','trading','CBD','forex'].forEach(topic => {
            draft.sensitive[topic] = $(`#sensitive${topic}`).is(':checked');
            draft.price_sensitive[topic] = $(`input[name="price_sensitive[${topic}]"]`).val();
        });
        [1, 7, 30].forEach(days => {
            draft.homepage[days] = $(`#homepage${days}`).is(':checked');
            draft.price_homepage[days] = $(`input[name="price_homepage[${days}]"]`).val();
        });
        ['facebook', 'instagram', 'x'].forEach(channel => {
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            draft.social[channel] = $(id).is(':checked');
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
window.clearSiteDraft = clearSiteDraft;
window.getPublisherQuill = function () { return quill; };

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
        let hasPlacementDraft = false;
        [1, 7, 30].forEach(days => {
            const on = !!(draft.homepage && draft.homepage[days]);
            $(`#homepage${days}`).prop('checked', on);
            $(`input[name="price_homepage[${days}]"]`).val((draft.price_homepage && draft.price_homepage[days]) || '');
            if (on) hasPlacementDraft = true;
        });
        ['facebook', 'instagram', 'x'].forEach(channel => {
            const on = !!(draft.social && draft.social[channel]);
            const id = '#social' + channel.charAt(0).toUpperCase() + channel.slice(1);
            $(id).prop('checked', on);
            if (on) hasPlacementDraft = true;
        });
        if (hasPlacementDraft) {
            setPlacementDisclosureOpen(true);
        }

        if (draft.country) {
            const countryOpt = $(`#countryOptions .single-select-option[data-value="${draft.country}"]`);
            if (countryOpt.length) {
                syncingCountryLanguage = true;
                countrySingleSelect.setSelectedValue(draft.country, countryOpt.data('label'));
                applyCountryLanguageFilter(draft.country, {
                    clearLanguage: false,
                    preferLanguage: draft.language || null
                });
                syncingCountryLanguage = false;
            }
        } else {
            applyCountryLanguageFilter('', { clearLanguage: true });
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
        if (!countrySingleSelect.getSelectedValue()) {
            ok = false;
            message = message || 'Please select a country / market.';
        }
        if (!languageSingleSelect.getSelectedValue()) {
            ok = false;
            message = message || 'Please select a language.';
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
    claimCard.addClass('d-none');
    formCard.toggleClass('d-none');
    let isOpen = !formCard.hasClass('d-none');

    addBtn.toggleClass('d-none', isOpen);
    bulkBtn.toggleClass('d-none', isOpen);
    bulkRequestBtn.toggleClass('d-none', isOpen);
    claimBtn.toggleClass('d-none', isOpen);
    formHeaderSpan.text('Add New Website');

    if(isOpen){
        closeBtn.removeClass('d-none');
        // Reset form for new site
        $('#addSiteForm')[0].reset();
        if (!$('#methodField').length) {
            $('#addSiteForm').append('<input type="hidden" name="_method" id="methodField" value="POST">');
        } else {
            $('#methodField').val('POST');
        }
        $('#addSiteForm').attr('action', "{{ route('publisher.sites.store') }}");
        if (quill) quill.root.innerHTML = '';
        submitBtn.prop('disabled', false).text('Review & submit');
        window.sitePreviewConfirmed = false;
        
        // Reset selects
        languageSingleSelect.clearSelection();
        countrySingleSelect.clearSelection();
        applyCountryLanguageFilter('', { clearLanguage: true });
        categoryMultiSelect.clearSelections();
        
        // Enable site name and URL for create
        $('#siteName').prop('disabled', false);
        $('#siteUrl').prop('disabled', false);
        $('.readonly-note').remove();
        $('#wizardDraftHint').text('');
        editingLiveMarket = null;
        window.siteRereviewConfirmed = false;

        const restored = loadSiteDraft();
        if (!restored) {
            setWizardStep(1);
            $('#wizardDraftHint').text('');
        }
    }
});

bulkBtn.on('click', function() {
    formCard.addClass('d-none');
    claimCard.addClass('d-none');
    closeBtn.addClass('d-none');
    addBtn.removeClass('d-none');
    bulkRequestBtn.removeClass('d-none');
    claimBtn.removeClass('d-none');
    bulkCard.toggleClass('d-none');
    bulkBtn.toggleClass('d-none', !bulkCard.hasClass('d-none'));
    formHeaderSpan.text(bulkCard.hasClass('d-none') ? 'My Sites' : 'Bulk Import');
});

closeBulkBtn.on('click', function() {
    bulkCard.addClass('d-none');
    bulkBtn.removeClass('d-none');
    formHeaderSpan.text('My Sites');
});

// Form validation + listing preview gate (modal handlers live in always-on JS)
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
    } else if (!window.sitePreviewConfirmed) {
        e.preventDefault();
        if (typeof window.showSiteListingPreview === 'function') {
            window.showSiteListingPreview();
        }
    } else if (
        $('#methodField').val() === 'PUT'
        && editingLiveMarket
        && marketChangedFromSnapshot()
        && !window.siteRereviewConfirmed
    ) {
        e.preventDefault();
        Swal.fire({
            title: 'Send this site for re-review?',
            html: 'Changing <strong>country, language, or categories</strong> takes this site offline until an admin approves it again. Price and description changes stay as you set them.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Update and send for review',
            cancelButtonText: 'Go back',
        }).then(function (result) {
            if (!result.isConfirmed) return;
            window.siteRereviewConfirmed = true;
            window.sitePreviewConfirmed = true;
            $('#addSiteForm').trigger('submit');
        });
    } else {
        if ($('#methodField').val() !== 'PUT') {
            clearSiteDraft();
        }
        // Defer disable: Chromium aborts form submit when the submitting
        // button is disabled synchronously inside the submit handler.
        setTimeout(function () {
            submitBtn.prop('disabled', true).html('<span class="loading-spinner"></span> Saving...');
        }, 0);
    }
});

let sitesStatusExplicit = false;
let sitesAutoOpenPendingChecked = false;
let sitesStatusFilter = (function () {
    try {
        const params = new URLSearchParams(window.location.search);
        sitesStatusExplicit = params.has('status');
        const raw = (params.get('status') || 'active').toLowerCase();
        return (raw === 'pending' || raw === 'active' || raw === 'invites' || raw === 'archived') ? raw : 'active';
    } catch (e) {
        return 'active';
    }
})();

function syncSitesStatusUrl(status) {
    try {
        const url = new URL(window.location.href);
        if (status && status !== 'active') {
            url.searchParams.set('status', status);
        } else {
            url.searchParams.delete('status');
        }
        history.replaceState({}, '', url.pathname + url.search + url.hash);
    } catch (e) { /* ignore */ }
}

window.setSitesStatusFilter = function (status) {
    const next = (status === 'pending' || status === 'invites' || status === 'archived') ? status : 'active';
    sitesStatusFilter = next;
    syncSitesStatusUrl(next);
    syncSitesFilterUi(
        parseInt(document.getElementById('sitesPendingCount')?.textContent || '0', 10),
        parseInt(document.getElementById('sitesActiveCount')?.textContent || '0', 10),
        sitesStatusFilter,
        null,
        parseInt(document.getElementById('sitesInviteCount')?.textContent || '0', 10),
        parseInt(document.getElementById('sitesArchivedCount')?.textContent || '0', 10)
    );
};
const ACTIVE_SITES_SEEN_KEY = 'slb_publisher_active_sites_seen_v1';

function parseActiveIds(raw) {
    if (!raw) return [];
    return String(raw)
        .split(',')
        .map(function (part) { return parseInt(part, 10); })
        .filter(function (id) { return Number.isFinite(id) && id > 0; });
}

function getSeenActiveSiteIds() {
    try {
        const raw = localStorage.getItem(ACTIVE_SITES_SEEN_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(parsed)) return new Set();
        return new Set(parsed.map(function (id) { return parseInt(id, 10); }).filter(Boolean));
    } catch (e) {
        return new Set();
    }
}

function saveSeenActiveSiteIds(ids) {
    try {
        localStorage.setItem(ACTIVE_SITES_SEEN_KEY, JSON.stringify(Array.from(ids)));
    } catch (e) { /* ignore quota / private mode */ }
}

function markActiveSitesSeen(activeIds) {
    const seen = getSeenActiveSiteIds();
    (activeIds || []).forEach(function (id) { seen.add(id); });
    saveSeenActiveSiteIds(seen);
}

function syncNewActiveBadges(activeIds, markSeen) {
    const ids = Array.isArray(activeIds) ? activeIds : [];
    const seen = getSeenActiveSiteIds();

    if (seen.size === 0) {
        // First visit: seed current actives so historical listings don't flash as "new".
        saveSeenActiveSiteIds(new Set(ids));
        markSeen = false;
    } else if (markSeen) {
        markActiveSitesSeen(ids);
    }

    const latestSeen = markSeen ? getSeenActiveSiteIds() : (seen.size === 0 ? new Set(ids) : seen);
    const newIdSet = new Set(ids.filter(function (id) { return !latestSeen.has(id); }));

    document.querySelectorAll('[data-site-new-badge]').forEach(function (badge) {
        const row = badge.closest('tr.main-row');
        const id = row ? parseInt(row.getAttribute('data-id') || '', 10) : 0;
        const isNew = id > 0 && newIdSet.has(id);
        if (window.PulseBadge && typeof window.PulseBadge.sync === 'function') {
            window.PulseBadge.sync(badge, isNew ? 1 : 0);
            if (isNew) {
                badge.textContent = 'New';
                badge.classList.add('is-visible');
            } else {
                badge.textContent = '';
                badge.classList.remove('is-visible');
            }
        } else if (isNew) {
            badge.hidden = false;
            badge.textContent = 'New';
            badge.classList.add('is-visible', 'is-pulsing', 'pulse-badge');
        } else {
            badge.hidden = true;
            badge.textContent = '';
            badge.classList.remove('is-visible', 'is-pulsing');
        }
        badge.setAttribute('aria-label', isNew ? 'Newly approved site' : 'Not new');
    });

    return newIdSet.size;
}

function syncSitesFilterUi(pendingCount, activeCount, status, activeIds, inviteCount, archivedCount) {
    const pendingCountEl = document.getElementById('sitesPendingCount');
    const activeCountEl = document.getElementById('sitesActiveCount');
    const inviteCountEl = document.getElementById('sitesInviteCount');
    const archivedCountEl = document.getElementById('sitesArchivedCount');
    const hint = document.getElementById('sitesFilterHint');
    const meta = document.getElementById('sitesStatusMeta');
    const bulkWaiting = parseInt(meta?.getAttribute('data-bulk-waiting') || '0', 10);
    const openBulk = meta?.getAttribute('data-open-bulk') === '1';
    const invites = inviteCount ?? parseInt(meta?.getAttribute('data-invites') || '0', 10);
    const archived = archivedCount ?? parseInt(meta?.getAttribute('data-archived') || '0', 10);

    if (pendingCountEl) {
        pendingCountEl.textContent = String(pendingCount ?? 0);
        pendingCountEl.classList.toggle('text-bg-secondary', !(pendingCount > 0));
        pendingCountEl.classList.toggle('text-bg-warning', pendingCount > 0);
    }
    if (activeCountEl) activeCountEl.textContent = String(activeCount ?? 0);
    if (inviteCountEl) {
        inviteCountEl.textContent = String(invites || 0);
        inviteCountEl.classList.toggle('text-bg-secondary', !(invites > 0));
        inviteCountEl.classList.toggle('text-bg-info', invites > 0);
    }
    if (archivedCountEl) {
        archivedCountEl.textContent = String(archived || 0);
        archivedCountEl.classList.toggle('text-bg-secondary', !(archived > 0));
        archivedCountEl.classList.toggle('text-bg-dark', archived > 0);
    }

    document.querySelectorAll('.site-status-filter').forEach(function (btn) {
        const on = btn.getAttribute('data-status') === status;
        btn.classList.toggle('is-active', on);
        btn.classList.remove('btn-primary', 'btn-outline-secondary');
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    const ids = Array.isArray(activeIds) ? activeIds : parseActiveIds(
        meta?.getAttribute('data-active-ids') || ''
    );
    syncNewActiveBadges(ids, false);

    if (hint) {
        if (status === 'active') {
            if (pendingCount > 0) {
                hint.textContent = (activeCount > 0)
                    ? 'Approved and live sites on your panel. ' + pendingCount + ' are in Pending.'
                    : 'No live sites in this tab. ' + pendingCount + ' are in Pending.';
            } else if ((invites || 0) > 0 && !(activeCount > 0)) {
                hint.textContent = 'No live sites in this tab. ' + invites + ' are in Invites.';
            } else if ((archived || 0) > 0 && !(activeCount > 0)) {
                hint.textContent = 'No live sites in this tab. ' + archived + ' are archived.';
            } else {
                hint.textContent = 'Approved and live sites on your panel.';
            }
        } else if (status === 'invites') {
            hint.textContent = 'Sites our team added for you — accept to move them into My Sites, or decline to remove them.';
        } else if (status === 'archived') {
            hint.textContent = 'Hidden from the catalog. Restore a site to put it back on Active.';
        } else if (bulkWaiting > 0) {
            hint.textContent = bulkWaiting === 1
                ? '1 site is with our marketer; others below may need your details or admin review.'
                : bulkWaiting + ' sites are with our marketer; others below may need your details or admin review.';
        } else if (openBulk) {
            hint.textContent = 'Your bulk request is open — drafts appear here as the marketer adds them, then you finish details.';
        } else {
            hint.textContent = 'Drafts that need your details, plus sites waiting for admin approval.';
        }
    }
}

function initSitesTableTips(root) {
    const scope = root || document.getElementById('sitesTableWrapper') || document;
    if (window.GlassTip && typeof window.GlassTip.enhance === 'function') {
        window.GlassTip.enhance(scope);
    }
    initSitePreviewZoom(scope);
}

function initSitePreviewZoom(root) {
    const scope = root || document;
    if (!window.matchMedia || window.matchMedia('(hover: none)').matches) return;

    let pop = document.getElementById('sitePreviewZoomPop');
    if (!pop) {
        pop = document.createElement('div');
        pop.id = 'sitePreviewZoomPop';
        pop.className = 'site-preview-zoom-pop';
        pop.setAttribute('aria-hidden', 'true');
        pop.innerHTML = '<img alt="" decoding="async">';
        document.body.appendChild(pop);
    }
    const img = pop.querySelector('img');
    let hideTimer = null;
    let zoomChain = [];
    let zoomIndex = 0;

    function place(trigger) {
        const rect = trigger.getBoundingClientRect();
        const pad = 12;
        const popW = pop.offsetWidth || 360;
        const popH = pop.offsetHeight || 220;
        let left = rect.right + 12;
        let top = rect.top + (rect.height / 2) - (popH / 2);
        if (left + popW > window.innerWidth - pad) {
            left = rect.left - popW - 12;
        }
        if (left < pad) left = pad;
        if (top < pad) top = pad;
        if (top + popH > window.innerHeight - pad) {
            top = Math.max(pad, window.innerHeight - popH - pad);
        }
        pop.style.left = Math.round(left) + 'px';
        pop.style.top = Math.round(top) + 'px';
    }

    function parseZoomChain(trigger) {
        let chain = [];
        try {
            chain = JSON.parse(trigger.getAttribute('data-zoom-chain') || '[]');
        } catch (e) {
            chain = [];
        }
        if (!Array.isArray(chain) || !chain.length) {
            const src = trigger.getAttribute('data-zoom-src');
            chain = src ? [src] : [];
        }
        return chain.filter(Boolean);
    }

    function show(trigger) {
        if (trigger.classList.contains('is-empty')) return;
        zoomChain = parseZoomChain(trigger);
        if (!zoomChain.length) return;
        clearTimeout(hideTimer);
        zoomIndex = 0;
        img.onerror = function () {
            zoomIndex += 1;
            if (zoomIndex < zoomChain.length) {
                img.src = zoomChain[zoomIndex];
                return;
            }
            img.onerror = null;
            pop.classList.remove('is-visible');
        };
        if (img.getAttribute('src') !== zoomChain[0]) {
            img.setAttribute('src', zoomChain[0]);
        }
        img.setAttribute('alt', trigger.getAttribute('aria-label') || 'Site preview');
        pop.classList.add('is-visible');
        place(trigger);
        requestAnimationFrame(function () { place(trigger); });
    }

    function hide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () {
            pop.classList.remove('is-visible');
            img.onerror = null;
        }, 80);
    }

    scope.querySelectorAll('.site-row-preview[data-zoom-src]').forEach(function (el) {
        if (el.getAttribute('data-zoom-ready') === '1') return;
        el.setAttribute('data-zoom-ready', '1');
        el.addEventListener('mouseenter', function () { show(el); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function () { show(el); });
        el.addEventListener('blur', hide);
    });
}

function fetchSites(page = 1, query = '', opts = {}) {
    window.__publisherSitesList = {
        page: parseInt(page, 10) || 1,
        query: query == null ? '' : String(query),
    };
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
                if (sitesStatusFilter === 'invites') {
                    $('#sitesTableWrapper').html(
                        '<div class="alert alert-light border text-center mb-0">' +
                        '<i class="fa fa-inbox me-2 text-muted"></i>' +
                        'No site invites waiting. When our team adds a website for you, Accept / Decline appear here.' +
                        '</div>'
                    );
                } else if (sitesStatusFilter === 'archived') {
                    $('#sitesTableWrapper').html(
                        '<div class="alert alert-light border text-center mb-0">' +
                        '<i class="fa fa-box-archive me-2 text-muted"></i>' +
                        'No archived sites. Live listings you archive are hidden from the catalog and show here.' +
                        '</div>'
                    );
                } else {
                    $('#sitesTableWrapper').html(
                        '<div class="ui-empty-state text-center mx-auto py-4" style="max-width:420px">' +
                        '<div class="mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:52px;height:52px;border-radius:50%;background:var(--brand-primary-bg,#e6f5f5);color:var(--brand-primary,var(--brand-primary, #1a585e))" aria-hidden="true"><i class="fa-solid fa-globe"></i></div>' +
                        '<h5 class="mb-2">No websites listed yet</h5>' +
                        '<p class="text-muted mb-3">Add your first site so advertisers can find and order from you.</p>' +
                        '<button type="button" class="btn btn-primary btn-sm" id="emptyAddSiteCta"><i class="fa fa-plus"></i> Add New Website</button>' +
                        '</div>'
                    );
                }
                syncNewActiveBadges([], !!opts.acknowledgeNewActive);
            } else {
                $('#sitesTableWrapper').html(html);
                const meta = document.getElementById('sitesStatusMeta');
                const activeIds = parseActiveIds(meta?.getAttribute('data-active-ids') || '');
                if (meta) {
                    const pendingFromMeta = parseInt(meta.getAttribute('data-pending') || '0', 10);
                    const activeFromMeta = parseInt(meta.getAttribute('data-active') || '0', 10);
                    syncSitesFilterUi(
                        pendingFromMeta,
                        activeFromMeta,
                        meta.getAttribute('data-status') || sitesStatusFilter,
                        activeIds,
                        parseInt(meta.getAttribute('data-invites') || '0', 10),
                        parseInt(meta.getAttribute('data-archived') || '0', 10)
                    );
                    // Auto-open Pending when Active is empty and the URL did not set ?status=
                    if (!sitesAutoOpenPendingChecked) {
                        sitesAutoOpenPendingChecked = true;
                        if (
                            !sitesStatusExplicit
                            && sitesStatusFilter === 'active'
                            && activeFromMeta === 0
                            && pendingFromMeta > 0
                            && !String(query || '').trim()
                        ) {
                            if (typeof window.setSitesStatusFilter === 'function') {
                                window.setSitesStatusFilter('pending');
                            }
                            fetchSites(1, query, opts);
                            return;
                        }
                    }
                }
                if (opts.acknowledgeNewActive) {
                    syncNewActiveBadges(activeIds, true);
                }
                initSitesTableTips(document.getElementById('sitesTableWrapper'));
            }
        },
        error: function(xhr) {
            const message = xhr.status === 403
                ? 'You do not have access to load sites. Refresh the page (or switch to Publisher) and try again.'
                : (xhr.status === 401 || xhr.status === 419)
                    ? 'Your session expired. Please refresh and sign in again.'
                    : 'Failed to load sites.';
            $('#sitesTableWrapper').html(
                '<div class="text-center py-4">' +
                '<div class="text-danger mb-2">' + message + '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-primary me-2" id="retrySitesBtn">Retry</button>' +
                '<button type="button" class="btn btn-sm btn-primary" id="reloadSitesPageBtn">Refresh page</button>' +
                '</div>'
            );
            $('#reloadSitesPageBtn').on('click', function () {
                window.location.reload();
            });
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
    syncSitesFilterUi(0, 0, sitesStatusFilter);
    fetchSites();
    if (sitesStatusFilter === 'pending' || sitesStatusFilter === 'invites' || sitesStatusFilter === 'archived') {
        const section = document.getElementById('sitesTableWrapper');
        if (section && typeof section.scrollIntoView === 'function') {
            setTimeout(function () {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 120);
        }
    }

    $(document).on('click', '#emptyAddSiteCta', function () {
        $('#showFormBtn').trigger('click');
    });

    $(document).on('click', '[data-switch-status]', function () {
        const next = this.getAttribute('data-switch-status') || 'active';
        sitesStatusExplicit = true;
        if (typeof window.setSitesStatusFilter === 'function') {
            window.setSitesStatusFilter(next);
        }
        fetchSites(1, $('#siteSearch').val());
    });

    $(document).on('click', '.site-status-filter', function () {
        const next = this.getAttribute('data-status') || 'active';
        const acknowledgeNewActive = next === 'active';
        sitesStatusExplicit = true;
        if (next === sitesStatusFilter) {
            if (acknowledgeNewActive) {
                const meta = document.getElementById('sitesStatusMeta');
                syncNewActiveBadges(parseActiveIds(meta?.getAttribute('data-active-ids') || ''), true);
            }
            return;
        }
        sitesStatusFilter = next;
        syncSitesStatusUrl(sitesStatusFilter);
        syncSitesFilterUi(
            parseInt(document.getElementById('sitesPendingCount')?.textContent || '0', 10),
            parseInt(document.getElementById('sitesActiveCount')?.textContent || '0', 10),
            sitesStatusFilter,
            null,
            parseInt(document.getElementById('sitesInviteCount')?.textContent || '0', 10),
            parseInt(document.getElementById('sitesArchivedCount')?.textContent || '0', 10)
        );
        fetchSites(1, $('#siteSearch').val(), { acknowledgeNewActive: acknowledgeNewActive });
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


function prefillSiteForm(site) {
    $('#formCard').removeClass('d-none');
    $('#claimCard').addClass('d-none');
    $('#bulkCard').addClass('d-none');
    $('#showFormBtn').addClass('d-none');
    $('#showBulkBtn').addClass('d-none');
    $('#showBulkRequestBtn').addClass('d-none');
    $('#showClaimBtn').addClass('d-none');
    closeBtn.removeClass('d-none');
    $('#formHeader').text('Edit Site: ' + site.site_name);
    setWizardStep(1);
    $('#wizardDraftHint').text('');

    $('#methodField').remove();
    $('#addSiteForm')
        .attr('action', '/publisher/sites/' + site.id)
        .append('<input type="hidden" name="_method" value="PUT" id="methodField">');

    $('#siteName').val(site.site_name).prop('disabled', true);
    $('#siteUrl').val(site.site_url).prop('disabled', true);
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

    if (site.link_type === 'dofollow') {
        $('#linkTypeDofollow').prop('checked', true);
    } else {
        $('#linkTypeNofollow').prop('checked', true);
    }

    let siteTag = '';
    if (site.sponsored) siteTag = 'sponsored';
    else if (site.partner_material) siteTag = 'partner_material';
    else if (site.as_you_prefer) siteTag = 'as_you_prefer';
    $(`input[name="site_tag"][value="${siteTag}"]`).prop('checked', true);
    if (!siteTag) $('#tagNone').prop('checked', true);

    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    if (site.sensitive_prices) {
        let prices = typeof site.sensitive_prices === 'string' ? JSON.parse(site.sensitive_prices) : site.sensitive_prices;
        for (const key in prices) {
            $(`input[name="sensitive[${key}]"]`).prop('checked', true);
            $(`input[name="price_sensitive[${key}]"]`).val(prices[key]);
        }
    }
    fillHomepageSocialFromSite(site);

    const langCode = (site.language || (Array.isArray(site.languages) ? site.languages[0] : null) || '').toString().toLowerCase();
    const countryCode = (site.country || (Array.isArray(site.countries) ? site.countries[0] : null) || '').toString().toLowerCase();
    syncingCountryLanguage = true;
    languageSingleSelect.clearSelection();
    countrySingleSelect.clearSelection();
    if (countryCode) {
        const countryOpt = $(`#countryOptions .single-select-option[data-value="${countryCode}"]`);
        if (countryOpt.length) {
            countrySingleSelect.setSelectedValue(countryCode, countryOpt.data('label'));
            applyCountryLanguageFilter(countryCode, {
                clearLanguage: false,
                preferLanguage: langCode || null
            });
        }
    } else {
        applyCountryLanguageFilter('', { clearLanguage: true });
    }
    syncingCountryLanguage = false;

    categoryMultiSelect.clearSelections();
    (Array.isArray(site.categories) ? site.categories : []).forEach(categoryName => {
        let option = $(`#categoryOptions .multi-select-option[data-value="${categoryName}"]`);
        if (option.length) {
            categoryMultiSelect.addItem(categoryName, option.data('label'));
        }
    });

    if (quill) {
        quill.root.innerHTML = site.description || '';
    }

    if (site.is_live) {
        $('#wizardDraftHint').text('Changing country, language, or categories will send this site for re-review and take it offline.');
        editingLiveMarket = snapshotMarketFromForm();
        window.siteRereviewConfirmed = false;
    } else {
        editingLiveMarket = null;
        window.siteRereviewConfirmed = false;
    }

    $('#submitBtn').prop('disabled', false).text('Review & update');
    window.sitePreviewConfirmed = false;
    $('html, body').animate({ scrollTop: $("#formCard").offset().top - 100 }, 500);
}

$(document).ready(function(){
    @if(session()->has('success'))
        clearSiteDraft();
    @endif

    @if($errors->any())
        (async function reopenSiteFormAfterValidationError() {
            formCard.removeClass('d-none');
            claimCard.addClass('d-none');
            bulkCard.addClass('d-none');
            addBtn.addClass('d-none');
            bulkBtn.addClass('d-none');
            bulkRequestBtn.addClass('d-none');
            claimBtn.addClass('d-none');
            closeBtn.removeClass('d-none');

            const editingSiteId = @json(session('editing_site_id'));
            if (editingSiteId) {
                try {
                    const res = await fetch(`/publisher/sites/${editingSiteId}/edit-data`, { headers: { 'Accept': 'application/json' } });
                    const data = await res.json();
                    if (data.success && data.site) {
                        // Keep validated old() scalar fields; restore action/method + readonly identity fields.
                        $('#methodField').remove();
                        $('#addSiteForm')
                            .attr('action', '/publisher/sites/' + data.site.id)
                            .append('<input type="hidden" name="_method" value="PUT" id="methodField">');
                        $('#siteName').val(data.site.site_name).prop('disabled', true);
                        $('#siteUrl').val(data.site.site_url).prop('disabled', true);
                        if (!$('#siteName').next('.readonly-note').length) {
                            $('#siteName').after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
                        }
                        if (!$('#siteUrl').next('.readonly-note').length) {
                            $('#siteUrl').after('<small class="text-muted readonly-note d-block">Due to security reasons, this field is readonly</small>');
                        }
                        formHeaderSpan.text('Edit Site: ' + data.site.site_name);
                        $('#submitBtn').prop('disabled', false).text('Review & update');
                        window.sitePreviewConfirmed = false;
                        if (data.site.is_live) {
                            $('#wizardDraftHint').text('Changing country, language, or categories will send this site for re-review and take it offline.');
                            editingLiveMarket = snapshotMarketFromForm();
                            window.siteRereviewConfirmed = false;
                        } else {
                            editingLiveMarket = null;
                            window.siteRereviewConfirmed = false;
                        }
                    }
                } catch (e) {
                    formHeaderSpan.text('Edit Site');
                    $('#submitBtn').text('Review & update');
                }
            } else {
                formHeaderSpan.text('Add New Website');
                $('#submitBtn').prop('disabled', false).text('Review & submit');
                window.sitePreviewConfirmed = false;
            }

            if (quill && !quill.root.innerHTML.trim()) {
                const oldDesc = @json(old_text('siteDescription'));
                if (oldDesc) quill.root.innerHTML = oldDesc;
            }

            setWizardStep(1);
            $('html, body').animate({ scrollTop: formCard.offset().top - 100 }, 400);
        })();
    @endif


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
            confirmButtonText: 'Yes, delete it!',
            customClass: { confirmButton: 'slb-swal-danger' },
            reverseButtons: true,
            focusCancel: true,
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    });

    $(document).on('click', '.btn-archive-site', async function () {
        const id = $(this).data('id');
        const name = String($(this).data('name') || 'This site');
        const ok = await Swal.fire({
            title: 'Archive this site?',
            text: `${name} will be hidden from the catalog. You can restore it later.`,
            showCancelButton: true,
            confirmButtonText: 'Archive'
        });
        if (!ok.isConfirmed) return;
        const res = await fetch(`/publisher/sites/${id}/archive`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
        if (data.success) loadSites();
    });

    $(document).on('click', '.btn-unarchive-site', async function () {
        const id = $(this).data('id');
        const res = await fetch(`/publisher/sites/${id}/unarchive`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        });
        const data = await res.json().catch(() => ({}));
        Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
        if (data.success) loadSites();
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
    bulkRequestBtn.removeClass('d-none');
    claimBtn.removeClass('d-none');
    formHeaderSpan.text('My Sites');
    $('#addSiteForm')[0].reset();
    if (quill) quill.root.innerHTML = '';
    $('.tag-checkbox').prop('checked', false);
    $('.sensitive-checkbox').prop('checked', false);
    $('.sensitive-price').val('');
    clearHomepageSocialFields();
    setPlacementDisclosureOpen(false);
    languageSingleSelect.clearSelection();
    countrySingleSelect.clearSelection();
    applyCountryLanguageFilter('', { clearLanguage: true });
    categoryMultiSelect.clearSelections();
    $('#siteName').prop('disabled', false);
    $('#siteUrl').prop('disabled', false);
    $('.readonly-note').remove();
    setWizardStep(1);
    $('#wizardDraftHint').text('');
    $('#addSiteForm').attr('action', "{{ route('publisher.sites.store') }}");
    $('#methodField').remove();
    $('#addSiteForm').append('<input type="hidden" name="_method" id="methodField" value="POST">');
    $('#submitBtn').text('Review & submit');
    window.sitePreviewConfirmed = false;
    window.siteRereviewConfirmed = false;
    editingLiveMarket = null;
});

// Edit via lean JSON endpoint
$(document).on('click', '.btn-edit', async function() {
    const siteHint = $(this).data('site') || {};
    const id = $(this).data('id') || siteHint.id;
    if (!id) {
        Swal.fire({ icon: 'error', title: 'Could not load site for editing' });
        return;
    }
    try {
        const res = await fetch(`/publisher/sites/${id}/edit-data`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data.success || !data.site) {
            Swal.fire({ icon: 'error', title: 'Could not load site for editing' });
            return;
        }
        if (data.site.is_live) {
            const warn = await Swal.fire({
                title: 'Edit live site?',
                html: 'Changing <strong>country, language, or categories</strong> will remove this site from the catalog until an admin re-approves it. Price and description changes stay live.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Continue editing'
            });
            if (!warn.isConfirmed) return;
        }
        prefillSiteForm(data.site);
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Could not load site for editing' });
    }
});

/* —— Claim a website —— */
$('#showClaimBtn').on('click', function () {
    formCard.addClass('d-none');
    bulkCard.addClass('d-none');
    bulkBtn.removeClass('d-none');
    claimCard.toggleClass('d-none');
    formHeaderSpan.text(claimCard.hasClass('d-none') ? 'My Sites' : 'Claim a website');
});
$('#closeClaimCard').on('click', function () {
    claimCard.addClass('d-none');
    formHeaderSpan.text('My Sites');
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
    await Swal.fire({ icon: data.success ? 'success' : 'error', title: data.message || 'Done' });
    if (data.success) {
        this.reset();
        claimCard.addClass('d-none');
        // Claims panel is server-rendered — reload so the new pending row appears.
        window.location.reload();
    }
});
</script>
<script src="{{ asset('js/multi-select.js') }}?v={{ @filemtime(public_path('js/multi-select.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/publisher-websites-bulk.js') }}?v={{ @filemtime(public_path('assets/js/publisher-websites-bulk.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/publisher-websites.js') }}?v={{ @filemtime(public_path('assets/js/publisher-websites.js')) ?: '1' }}"></script>

@endsection