@extends('advertiser.layouts.app')

@section('title', 'Content Library')

@push('page-styles')
<link href="{{ asset('assets/css/single-select.css') }}?v={{ @filemtime(public_path('assets/css/single-select.css')) ?: '1' }}" rel="stylesheet">
@endpush

@section('content')
<link href="{{ asset('assets/vendor/quill-2.0.2/quill.snow.css') }}?v={{ @filemtime(public_path('assets/vendor/quill-2.0.2/quill.snow.css')) ?: '1' }}" rel="stylesheet">
<link href="{{ asset('assets/css/content-library.css') }}?v={{ @filemtime(public_path('assets/css/content-library.css')) ?: '1' }}" rel="stylesheet">


<div class="container-fluid library-page">
    @include('advertiser.partials.ordering-path', [
        'step' => 3,
        'title' => 'Place a guest post · Content',
        'subtitle' => 'Upload and approve articles here. You can also browse publishers first and upload when you pick a site.',
        'linkAll' => true,
        'contentRoute' => route('advertiser.content-library'),
    ])

    <div class="library-page-header mb-3">
        <h1 class="library-page-title">Content Library</h1>
        <div class="library-page-actions">
            @include('advertiser.partials.upload-article-button', [
                'uploadButtonId' => 'openUploadModalBtn',
                'uploadsEnabled' => $uploadsEnabled,
            ])
            <a href="{{ route('advertiser.catalog') }}" class="btn btn-link btn-sm library-browse-link" id="libraryBrowsePublishersBtn">
                Browse publishers
            </a>
        </div>
    </div>

    <div id="libraryFlash" class="alert d-none" role="status"></div>
    @if($editSubmission && ($editSubmission->libraryAvailability() === 'needs_fix' || request()->boolean('fix')))
        @php
            $fixReasons = $editSubmission->evaluationReasonGroups();
            $fixNotice = trim($editSubmission->editorNotice());
        @endphp
        <div class="alert alert-danger py-2 px-3 small mb-3" id="libraryFixBanner" role="status">
            <strong>Needs corrections.</strong>
            {{ $fixNotice !== '' ? $fixNotice : $editSubmission->libraryFixSummary() }}
            @if(($fixReasons['blocking'] ?? []) !== [])
                <ul class="mb-2 mt-2">
                    @foreach(array_slice($fixReasons['blocking'], 0, 5) as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="d-flex flex-wrap gap-2">
                @if($editSubmission->canEditArticle())
                    <button type="button" class="btn btn-sm btn-light js-open-editor" data-submission-id="{{ $editSubmission->id }}">
                        Edit article
                    </button>
                @endif
                @if($uploadsEnabled && $editSubmission->canEditArticle())
                    <button type="button" class="btn btn-sm btn-outline-light" id="openUploadModalBtnFix"
                            onclick="document.getElementById('openUploadModalBtn')?.click()">
                        Replace .docx
                    </button>
                @endif
            </div>
        </div>
    @endif
    @if(($nearExpiryCount ?? 0) > 0)
        <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
            <i class="fa fa-hourglass-half me-1" aria-hidden="true"></i>
            {{ $nearExpiryCount }} unused article{{ $nearExpiryCount === 1 ? '' : 's' }}
            expire{{ $nearExpiryCount === 1 ? 's' : '' }} within {{ (int) ($nearExpiryDays ?? 7) }} days.
            Order them before the original Word file is removed — a preview stays in Expired
            (kept {{ (int) ($retentionMonths ?? 6) }} months; articles linked to orders keep the original file).
        </div>
    @endif
    @unless($uploadsEnabled)
        <div class="alert alert-warning py-2 px-3 small mb-3" role="status">
            New uploads are temporarily turned off. You can still browse, archive, and order approved articles.
        </div>
    @endunless

    <form method="GET" action="{{ route('advertiser.content-library', absolute: false) }}" class="library-filter-bar mb-3">
        <input type="hidden" name="status" value="{{ $statusFilter ?? 'all' }}">
        <input type="hidden" name="availability" value="{{ $availabilityFilter ?? 'all' }}">
        <label class="form-label fw-semibold small text-muted mb-1" for="librarySearchInput">Search</label>
        <div class="library-filter-bar__row">
            <div class="library-filter-bar__search">
                <div class="position-relative slb-search-wrap">
                    <input type="search" name="q" id="librarySearchInput" class="form-control form-control-sm"
                           value="{{ $searchQuery ?? '' }}" placeholder="Search title, filename, URL, or body"
                           title="Results update as you type. Multi-word matches require every word. Searches title, filename, target URL, and article text."
                           autocomplete="off" enterkeyhint="search"
                           aria-describedby="librarySearchStatus">
                    <button type="button"
                            id="librarySearchClear"
                            class="btn btn-sm btn-link slb-search-clear{{ ($searchQuery ?? '') !== '' ? '' : ' d-none' }}"
                            aria-label="Clear search">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="library-filter-bar__select">
                <label class="visually-hidden" for="libraryCountryFilter">Country</label>
                @include('advertiser.partials.library-theme-select', [
                    'selectId' => 'libraryCountryFilter',
                    'name' => 'country',
                    'label' => 'Country',
                    'current' => $countryFilter ?? 'all',
                    'options' => array_merge(
                        [['value' => 'all', 'label' => 'All countries']],
                        collect($groupedByCountry ?? [])->map(fn ($count, $code) => [
                            'value' => $code,
                            'label' => strtoupper((string) $code).' ('.$count.')',
                        ])->values()->all()
                    ),
                ])
            </div>
            <div class="library-filter-bar__select">
                <label class="visually-hidden" for="libraryLanguageFilter">Language</label>
                @include('advertiser.partials.library-theme-select', [
                    'selectId' => 'libraryLanguageFilter',
                    'name' => 'language',
                    'label' => 'Language',
                    'current' => $languageFilter ?? 'all',
                    'options' => array_merge(
                        [['value' => 'all', 'label' => 'All languages']],
                        collect($groupedByLanguage ?? [])->map(fn ($count, $code) => [
                            'value' => $code,
                            'label' => strtoupper((string) $code).' ('.$count.')',
                        ])->values()->all()
                    ),
                ])
            </div>
            <div class="library-filter-bar__select">
                <label class="visually-hidden" for="librarySortFilter">Sort</label>
                @include('advertiser.partials.library-theme-select', [
                    'selectId' => 'librarySortFilter',
                    'name' => 'sort',
                    'label' => 'Sort',
                    'current' => $sort ?? 'latest',
                    'options' => [
                        ['value' => 'latest', 'label' => 'Newest'],
                        ['value' => 'title', 'label' => 'Title'],
                        ['value' => 'expires', 'label' => 'Expiry'],
                        ['value' => 'uniqueness', 'label' => 'Uniqueness'],
                        ['value' => 'quality', 'label' => 'Quality'],
                    ],
                ])
            </div>
            <div class="library-filter-bar__score">
                <label class="visually-hidden" for="libraryMinUniqueness">Minimum uniqueness</label>
                <input type="number" name="min_uniqueness" id="libraryMinUniqueness"
                       class="form-control form-control-sm" min="0" max="100" step="1"
                       value="{{ $minUniqueness !== null ? $minUniqueness : '' }}"
                       placeholder="Min unique %">
            </div>
            <div class="library-filter-bar__score">
                <label class="visually-hidden" for="libraryMinQuality">Minimum quality</label>
                <input type="number" name="min_quality" id="libraryMinQuality"
                       class="form-control form-control-sm" min="0" max="100" step="1"
                       value="{{ $minQuality !== null ? $minQuality : '' }}"
                       placeholder="Min quality %">
            </div>
            <div id="libraryFilterReset" class="library-filter-bar__actions{{ (
                ! empty($searchQuery)
                || ($countryFilter ?? 'all') !== 'all'
                || ($languageFilter ?? 'all') !== 'all'
                || ($availabilityFilter ?? 'available') !== 'available'
                || (($sort ?? 'latest') !== 'latest')
                || ($minUniqueness ?? null) !== null
                || ($minQuality ?? null) !== null
            ) ? '' : ' d-none' }}">
                <a href="{{ route('advertiser.content-library', absolute: false) }}" class="btn btn-sm btn-link">Reset</a>
            </div>
        </div>
        <div id="librarySearchStatus" class="form-text library-search-status" role="status" aria-live="polite"></div>
        <button type="submit" class="visually-hidden">Search</button>
    </form>

    <div id="libraryLiveRegion">
        @include('advertiser.partials.content-library-results')
    </div>
</div>

{{-- Upload modal --}}
<div class="modal fade" id="uploadContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="libraryUploadForm" method="post" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title">Upload article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @php
                    $uploadMaxKb = (int) ($uploadCfg['max_kilobytes'] ?? 10240);
                    $uploadMaxMb = max(1, (int) round($uploadMaxKb / 1024));
                @endphp
                <ol class="library-upload-steps mb-3" aria-label="Upload steps">
                    <li data-upload-step="file" class="is-current">File</li>
                    <li data-upload-step="market">Market</li>
                    <li data-upload-step="rights" class="is-pending">Rights</li>
                </ol>
                <x-ui.callout variant="info" class="ui-callout--sm mb-3">
                    Microsoft Word (.docx) only — not PDF, Google Doc, or pasted text.
                    Max {{ $uploadMaxMb }} MB each. You can select up to {{ (int) config('content_library.multi_upload_limit', 10) }} files; they upload one after another with the same market.
                    Unused articles are kept {{ (int) ($retentionMonths ?? 6) }} months, then the original file is removed and a preview stays in Expired.
                    A single file opens in the editor next.
                    Image rights are asked after we read the file, and only if it contains pictures.
                </x-ui.callout>

                <div class="mb-3">
                    <label class="library-dropzone" id="libraryDropzone" for="libraryFileInput">
                        <input type="file" name="file" id="libraryFileInput" class="visually-hidden" multiple
                               accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                        <span class="library-dropzone__idle" id="libraryDropzoneIdle">
                            <i class="fa fa-file-word" aria-hidden="true"></i>
                            <strong>Drop .docx files here or click to browse</strong>
                            <span>Word only — not PDF, Google Doc, or pasted text. Multiple files allowed.</span>
                        </span>
                        <span class="library-dropzone__file d-none" id="libraryDropzoneFile"></span>
                    </label>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="libraryCountry">Country <span class="text-danger">*</span></label>
                        <select name="country" id="libraryCountry" class="form-select" required>
                            <option value="">Select country</option>
                            @foreach(($countries ?? []) as $country)
                                <option value="{{ strtolower($country->code) }}"
                                    @selected(strtolower((string) ($editSubmission?->country ?? '')) === strtolower($country->code))>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Pick the market country first — language stays closed until you do.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="libraryLanguage">Language <span class="text-danger">*</span></label>
                        <select name="language" id="libraryLanguage" class="form-select" required disabled>
                            <option value="">Select country first</option>
                        </select>
                        <div class="form-text" id="libraryLanguageHint">Select a country first, then a paired language (e.g. Germany → German).</div>
                    </div>
                </div>
                <div class="mb-3" id="libraryMarketChipWrap">
                    <span class="library-market-chip d-none" id="libraryMarketChip"></span>
                </div>

                <div class="mb-3" id="libraryTitleWrap">
                    <label class="form-label" for="libraryTitleInput">Title <span class="text-muted">(optional)</span></label>
                    <input type="text" name="title" id="libraryTitleInput" class="form-control" maxlength="200"
                           placeholder="Defaults to the filename"
                           value="{{ $editSubmission?->title ?? '' }}">
                </div>

                <input type="hidden" name="replace_id" id="replaceIdInput" value="{{ $editSubmission?->id ?? '' }}">
                <div id="libraryUploadFeedback" class="small" aria-live="polite"></div>
                <div class="progress d-none mt-2" id="libraryUploadProgress" style="height:6px;"><div class="progress-bar" style="width:0%"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="libraryUploadCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-upload" id="libraryUploadBtn">Upload and edit</button>
            </div>
        </form>
    </div>
</div>

{{-- Docs-style editor modal --}}
<div class="modal fade" id="articleEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Edit article</h5>
                    <div class="article-editor-meta" id="articleEditorMeta"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" class="form-control" id="articleEditorTitle" maxlength="200" placeholder="Article title">
                </div>
                <div class="alert alert-light border small mb-3">
                    Edit like a document: format text, insert or remove images, and add or remove links. Saving re-checks the article for approval.
                </div>
                <div class="article-docs-shell mb-3">
                    <div id="articleQuillEditor"></div>
                    <button type="button"
                            class="btn btn-sm btn-dark article-img-remove d-none"
                            id="articleImageRemoveBtn"
                            aria-label="Remove image"
                            title="Remove image">
                        <i class="fa fa-trash" aria-hidden="true"></i>
                    </button>
                </div>

                <div id="articleEditorFeedback" class="article-editor-feedback small" aria-live="polite"></div>
                <p id="articleEditorImageCount" class="article-editor-image-count small text-muted mb-2" hidden></p>

                {{-- Shown when the article gains images the current declaration does not cover. --}}
                <div id="articleEditorImageRights" class="article-editor-rights border rounded-3 d-none">
                    @include('advertiser.partials.image-rights-declaration', [
                        'idPrefix' => 'editorImageRights',
                        'submission' => null,
                    ])
                </div>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="articleEditorPreviewBtn">Preview</button>
                <button type="button" class="btn btn-primary" id="articleEditorSaveBtn">Save &amp; re-check</button>
            </div>
        </div>
    </div>
</div>

{{-- Full preview modal --}}
<div class="modal fade" id="articlePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header flex-wrap gap-2">
                <div class="me-auto">
                    <h5 class="modal-title mb-0" id="articlePreviewTitle">Article preview</h5>
                    <div class="small text-muted" id="articlePreviewHeadingHint"></div>
                </div>
                <div class="article-preview-toolbar d-flex flex-wrap gap-2">
                    <button type="button"
                            class="btn btn-sm btn-primary d-none"
                            id="articlePreviewEditBtn">
                        Edit article
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary d-none"
                            id="articlePreviewDuplicateBtn">
                        Duplicate
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-primary btn-copy-icon"
                            id="articleCopyHeadingBtn"
                            title="Copy heading to clipboard"
                            aria-label="Copy heading to clipboard">
                        <i class="fa fa-copy" aria-hidden="true"></i>
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-primary btn-copy-icon"
                            id="articleCopyContentBtn"
                            title="Copy article to clipboard"
                            aria-label="Copy article to clipboard">
                        <i class="fa fa-clone" aria-hidden="true"></i>
                    </button>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="library-preview" style="max-height:none;" id="articlePreviewBody"></div>
                <div id="articlePreviewLinkMeta" class="border-top mt-3 pt-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <div class="fw-semibold">Links in this article</div>
                        <button type="button" class="btn btn-sm btn-primary d-none" id="articleLinksSaveBtn">Save link edits</button>
                    </div>
                    <div id="articlePreviewLinksList"></div>
                    <p class="small text-muted mb-0 mt-2" id="articleLinksHelp">Shown outside the article so you can review every anchor and URL.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('assets/vendor/quill-2.0.2/quill.js') }}?v={{ @filemtime(public_path('assets/vendor/quill-2.0.2/quill.js')) ?: '1' }}"></script>
<script src="{{ asset('assets/js/article-preview-tools.js') }}?v={{ @filemtime(public_path('assets/js/article-preview-tools.js')) ?: '1' }}"></script>
<script>
window.ContentLibraryBoot = {
    libraryUpdateUrl: @json(parse_url(url('/advertiser/content-submissions'), PHP_URL_PATH) ?: '/advertiser/content-submissions'),
    libraryContentUrl: @json(parse_url(url('/advertiser/content-submissions'), PHP_URL_PATH) ?: '/advertiser/content-submissions'),
    libraryImageUploadUrl: @json(route('advertiser.content-submissions.editor-image', absolute: false)),
    libraryPreviewUrlBase: @json(parse_url(url('/advertiser/content-submissions'), PHP_URL_PATH) ?: '/advertiser/content-submissions'),
    libraryCsrf: @json(csrf_token()),
    libraryLanguageCountryMap: @json($languageCountryMap ?? new \stdClass()),
    libraryCountryLanguageMap: @json($countryLanguageMap ?? new \stdClass()),
    libraryPreferredCountry: @json(strtolower((string) ($editSubmission?->country ?? ''))),
    libraryPreferredLanguage: @json(strtolower((string) ($editSubmission?->language ?? ''))),
    uploadsEnabled: @json(!empty($uploadsEnabled)),
    openUpload: @json(!empty($openUpload)),
    uploadUrl: @json(route('advertiser.content-library.upload', absolute: false)),
    duplicateUrl: @json(url('/advertiser/content-library')),
    multiUploadLimit: @json((int) config('content_library.multi_upload_limit', 10)),
    libraryIndexUrl: @json(route('advertiser.content-library', absolute: false)),
    libraryResultsUrl: @json(route('advertiser.content-library.results', absolute: false)),
    editSubmission: @json($editSubmissionBoot ?? null),
    maxKilobytes: @json((int) ($uploadCfg['max_kilobytes'] ?? 10240)),
    phpMaxKilobytes: @json((int) ($uploadCfg['php_max_kilobytes'] ?? 0)),
};
</script>
<script src="{{ asset('assets/js/single-select.js') }}?v={{ @filemtime(public_path('assets/js/single-select.js')) ?: '1' }}" defer></script>
<script src="{{ asset('assets/js/content-library.js') }}?v={{ @filemtime(public_path('assets/js/content-library.js')) ?: '1' }}-c512" defer></script>

@endsection
