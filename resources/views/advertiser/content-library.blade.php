@extends('advertiser.layouts.app')

@section('content')
@php
    $filterBase = $libraryFilterBase ?? [
        'status' => $statusFilter ?? 'all',
        'availability' => $availabilityFilter ?? 'all',
        'language' => $languageFilter ?? 'all',
        'country' => $countryFilter ?? 'all',
        'q' => $searchQuery ?? '',
    ];
    $libraryRoute = function (array $overrides = []) use ($filterBase) {
        $params = array_merge($filterBase, $overrides);
        if (($params['q'] ?? '') === '') {
            unset($params['q']);
        }
        return route('advertiser.content-library', $params);
    };
    $statusLabels = [
        'available' => 'Available',
        'in_progress' => 'In progress',
        'published' => 'Published',
        'needs_fix' => 'Needs fix',
        'expired' => 'Expired',
        'archived' => 'Archived',
        'unavailable' => 'Unavailable',
    ];
    $moderationBoxLabels = [
        'all' => 'All',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'needs_improvement' => 'Needs corrections',
    ];
    $moderationCounts = $moderationCounts ?? [
        'all' => 0,
        'approved' => 0,
        'rejected' => 0,
        'needs_improvement' => 0,
    ];
@endphp
<style>
    .library-table { background: #fff; border-radius: 12px; overflow: hidden; }
    .library-table table { margin-bottom: 0; }
    .library-table th {
        font-size: .72rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 600;
        border-bottom-width: 1px;
        white-space: nowrap;
    }
    .library-table td { vertical-align: middle; }
    .library-title { font-weight: 600; color: #0f172a; max-width: 280px; }
    .library-live-link {
        display: block;
        font-size: .8rem;
        margin-top: .2rem;
        max-width: 320px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .library-live-link a { color: var(--brand-primary, #0b6266); }
    .library-market {
        font-size: .78rem;
        background: var(--brand-primary-bg, #e8f8f7);
        color: var(--brand-primary, #0b6266);
        border-radius: 999px;
        padding: 3px 9px;
        white-space: nowrap;
    }
    .library-status {
        display: inline-flex;
        align-items: center;
        font-size: .72rem;
        font-weight: 600;
        letter-spacing: .02em;
        border-radius: 6px;
        padding: 4px 9px;
        border: 1px solid transparent;
        white-space: nowrap;
        line-height: 1.2;
    }
    .library-status--available,
    .library-status--published {
        background: var(--brand-primary-bg, #e8f8f7);
        color: var(--brand-primary, #0b6266);
        border-color: var(--brand-primary-border, #b8e8e6);
    }
    .library-status--in_progress {
        background: #f1f5f9;
        color: #475569;
        border-color: #e2e8f0;
    }
    .library-status--needs_fix {
        background: #f8fafc;
        color: var(--brand-primary, #0b6266);
        border-color: var(--brand-primary-border, #b8e8e6);
    }
    .library-status--expired,
    .library-status--archived,
    .library-status--unavailable {
        background: #f8fafc;
        color: #64748b;
        border-color: #e2e8f0;
    }
    .library-moderation-row {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        margin-bottom: 1rem;
    }
    .library-moderation-box {
        flex: 1 1 140px;
        min-width: 120px;
        max-width: 220px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
        padding: .65rem .85rem;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #334155;
        text-decoration: none;
        font-size: .84rem;
        font-weight: 600;
        transition: border-color .15s ease, background .15s ease, color .15s ease, box-shadow .15s ease;
    }
    .library-moderation-box:hover {
        border-color: var(--brand-primary-border, #b8e8e6);
        background: var(--brand-primary-bg, #e8f8f7);
        color: var(--brand-primary, #0b6266);
    }
    .library-moderation-box.is-active {
        background: var(--brand-primary, #0b6266);
        border-color: var(--brand-primary, #0b6266);
        color: #fff;
        box-shadow: 0 1px 2px rgba(11, 98, 102, .18);
    }
    .library-moderation-box .mod-count {
        font-size: .72rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        opacity: .75;
        background: rgba(15, 23, 42, .06);
        border-radius: 999px;
        padding: 2px 7px;
        line-height: 1.3;
    }
    .library-moderation-box.is-active .mod-count {
        background: rgba(255, 255, 255, .2);
        opacity: 1;
    }
    .library-scores { font-variant-numeric: tabular-nums; white-space: nowrap; color: #475569; }
    .library-preview {
        border: 1px solid var(--brand-primary-border, #b8e8e6);
        background: #fff;
        border-radius: 12px;
        padding: 14px 16px;
        max-height: 220px;
        overflow: auto;
        font-size: 0.9rem;
        line-height: 1.55;
        color: #334155;
    }
    .library-preview img,
    .article-editor-preview img,
    #articlePreviewBody img,
    .ql-editor img {
        max-width: 100%;
        height: auto;
        border-radius: 8px;
        margin: .5rem 0;
        display: block;
    }
    #articlePreviewBody mark.slb-mod-hit,
    .ql-editor mark.slb-mod-hit,
    .library-preview mark.slb-mod-hit {
        background: #fef08a;
        color: #854d0e;
        padding: 0 2px;
        border-radius: 3px;
    }
    #articlePreviewBody a.slb-mod-hit-link,
    .library-preview a.slb-mod-hit-link {
        outline: 2px solid #e67e22;
        outline-offset: 2px;
        background: #fff3cd;
        border-radius: 2px;
        padding: 0 .1em;
    }
    .library-reject-box {
        margin-top: 6px;
        padding: 8px 10px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid var(--brand-primary-border, #b8e8e6);
        color: #475569;
        font-size: 12px;
        line-height: 1.4;
        max-width: 420px;
    }
    .library-reject-box strong {
        display: block;
        margin-bottom: 2px;
        color: var(--brand-primary, #0b6266);
    }
    .library-feature-thumb {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        vertical-align: middle;
        margin-right: 8px;
    }
    .library-actions .btn { white-space: nowrap; }
    .library-filter-bar .form-select { min-width: 140px; }
    .library-page-actions { margin-top: .75rem; }
    .article-docs-shell {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        background: #f8fafc;
    }
    .article-docs-shell .ql-toolbar.ql-snow {
        border: none;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
    }
    .article-docs-shell .ql-container.ql-snow {
        border: none;
        background: #fff;
        min-height: 320px;
        font-size: 1rem;
    }
    .article-docs-shell .ql-editor {
        min-height: 320px;
        line-height: 1.65;
        padding: 1.25rem 1.5rem;
    }
    .article-editor-meta {
        font-size: .8rem;
        color: #64748b;
    }
</style>

<div class="container-fluid">
    @include('advertiser.partials.ordering-path', [
        'step' => 3,
        'title' => 'Place a guest post · Content',
        'subtitle' => 'One job here: upload and approve articles. Any approved article can be placed on any catalog site.',
        'linkAll' => true,
        'contentRoute' => route('advertiser.content-library'),
        'actions' => '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadContentModal" id="openUploadModalBtnTop"><i class="fa fa-upload me-1"></i> Upload article</button>'
            .'<a href="'.e(route('advertiser.catalog')).'" class="btn btn-sm btn-outline-primary">Browse publishers</a>',
    ])

    <div class="mb-3">
        <h2 class="mb-1 fw-semibold">Content Library</h2>
        <p class="text-muted mb-0 small">
            Upload a .docx (choose language and country yourself) → wait for approval → browse any publishers → assign in cart → pay.
            Multi-site orders need a different approved article for each website — language does not have to match the site.
        </p>
        <div class="library-page-actions">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadContentModal" id="openUploadModalBtn">
                <i class="fa fa-upload me-1"></i> Upload article
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    <div id="libraryFlash" class="alert d-none" role="status"></div>

    <form method="GET" action="{{ route('advertiser.content-library') }}" class="library-filter-bar row g-2 align-items-end mb-2">
        <input type="hidden" name="status" value="{{ $statusFilter ?? 'all' }}">
        <div class="col-md-3 col-lg-3">
            <label class="form-label small text-muted mb-1" for="librarySearchInput">Search</label>
            <input type="search" name="q" id="librarySearchInput" class="form-control form-control-sm"
                   value="{{ $searchQuery ?? '' }}" placeholder="Title or filename">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Country</label>
            <select name="country" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" @selected(($countryFilter ?? 'all') === 'all')>All</option>
                @foreach(($groupedByCountry ?? []) as $countryCode => $count)
                    <option value="{{ $countryCode }}" @selected(($countryFilter ?? 'all') === $countryCode)>
                        {{ strtoupper($countryCode) }} ({{ $count }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Language</label>
            <select name="language" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" @selected(($languageFilter ?? 'all') === 'all')>All</option>
                @foreach(($groupedByLanguage ?? []) as $langCode => $count)
                    <option value="{{ $langCode }}" @selected(($languageFilter ?? 'all') === $langCode)>
                        {{ strtoupper($langCode) }} ({{ $count }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
            @if(!empty($searchQuery) || ($statusFilter ?? 'all') !== 'all' || ($countryFilter ?? 'all') !== 'all' || ($languageFilter ?? 'all') !== 'all')
                <a href="{{ route('advertiser.content-library') }}" class="btn btn-sm btn-link">Reset</a>
            @endif
        </div>
    </form>

    <div class="library-moderation-row" role="group" aria-label="Moderation filter">
        @foreach($moderationBoxLabels as $key => $label)
            <a href="{{ $libraryRoute(['status' => $key]) }}"
               class="library-moderation-box @if(($statusFilter ?? 'all') === $key) is-active @endif"
               @if(($statusFilter ?? 'all') === $key) aria-current="true" @endif>
                <span>{{ $label }}</span>
                <span class="mod-count">{{ (int) ($moderationCounts[$key] ?? 0) }}</span>
            </a>
        @endforeach
    </div>

    <div class="library-table border shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Title</th>
                        <th>Market</th>
                        <th>Status</th>
                        <th>Scores</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($submissions as $submission)
                    @php
                        $availability = $submission->libraryAvailability();
                        $placement = $submission->placementItem();
                        $liveUrl = $submission->liveUrl();
                        $siteName = $placement?->site?->site_name;
                        $label = $statusLabels[$availability] ?? ucfirst(str_replace('_', ' ', $availability));
                    @endphp
                    <tr id="library-row-{{ $submission->id }}">
                        <td>
                            @if($submission->feature_image_url)
                                <img src="{{ \App\Services\ContentUpload\ArticlePreviewHtml::normalizeSrc((string) $submission->feature_image_url) }}"
                                     alt=""
                                     class="library-feature-thumb"
                                     loading="lazy"
                                     onerror="this.style.display='none'; this.insertAdjacentHTML('afterend','<span class=\'text-muted small\'>Image unavailable</span>');">
                            @endif
                            <div class="library-title text-truncate" data-title-display="{{ $submission->id }}" title="{{ $submission->title ?: $submission->original_filename }}">
                                {{ $submission->title ?: $submission->original_filename }}
                            </div>
                            @if($availability === 'published' && $liveUrl)
                                <div class="library-live-link">
                                    @if($siteName)
                                        <span class="text-muted">Published on {{ $siteName }}</span><br>
                                    @else
                                        <span class="text-muted">Published</span><br>
                                    @endif
                                    <a href="{{ $liveUrl }}" target="_blank" rel="noopener noreferrer">{{ $liveUrl }}</a>
                                </div>
                            @elseif($availability === 'in_progress' && $submission->order_id)
                                <div class="library-live-link text-muted">
                                    Order #{{ $submission->order_id }}
                                    @if($siteName) · {{ $siteName }} @endif
                                </div>
                            @elseif($availability === 'needs_fix')
                                <div class="library-reject-box">
                                    <strong>Needs changes</strong>
                                    {{ $submission->evaluation_report['summary'] ?? 'Fix issues and resubmit.' }}
                                    @php
                                        $hitTerms = $submission->evaluation_report['matched_terms'] ?? [];
                                        $blockedUrls = $submission->evaluation_report['blocked_urls'] ?? [];
                                    @endphp
                                    @if(is_array($hitTerms) && count($hitTerms))
                                        <div class="mt-1">Remove/rewrite: {{ implode(', ', array_slice($hitTerms, 0, 8)) }}</div>
                                    @endif
                                    @if(is_array($blockedUrls) && count($blockedUrls))
                                        <div class="mt-1">Blocked links: {{ implode(', ', array_slice($blockedUrls, 0, 5)) }}</div>
                                    @endif
                                </div>
                            @endif
                            <div class="library-title-edit d-none mt-2" data-title-edit="{{ $submission->id }}">
                                <div class="input-group input-group-sm" style="max-width:320px;">
                                    <input type="text" class="form-control" maxlength="200"
                                           value="{{ $submission->title }}"
                                           data-title-input="{{ $submission->id }}">
                                    <button type="button" class="btn btn-primary" onclick="saveLibraryTitle({{ $submission->id }})">Save</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="toggleLibraryTitleEdit({{ $submission->id }}, false)">Cancel</button>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="library-market">
                                {{ strtoupper((string) $submission->country) }}/{{ strtoupper((string) $submission->language) }}
                            </span>
                        </td>
                        <td>
                            <span class="library-status library-status--{{ $availability }}">{{ $label }}</span>
                        </td>
                        <td class="library-scores">
                            @if($submission->evaluated_at)
                                {{ $submission->uniqueness_score !== null ? $submission->uniqueness_score.'%' : '—' }}
                                ·
                                {{ $submission->quality_score !== null ? $submission->quality_score.'%' : '—' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end library-actions">
                            <div class="d-inline-flex flex-wrap gap-1 justify-content-end">
                                @if($submission->canBeOrdered())
                                    <a class="btn btn-sm btn-primary"
                                       href="{{ route('advertiser.content-library.order', $submission) }}">
                                        Order
                                    </a>
                                @elseif($availability === 'needs_fix')
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ route('advertiser.content-library', ['edit' => $submission->id, 'upload' => 1]) }}">
                                        Resubmit
                                    </a>
                                @elseif($availability === 'in_progress' || $availability === 'published')
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('advertiser.orders') }}">View order</a>
                                @endif

                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        More
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if($submission->preview_html)
                                            <li>
                                                <button type="button" class="dropdown-item"
                                                        onclick='openPreviewModal(@json($submission->title ?: $submission->original_filename), @json(\App\Services\ContentUpload\ArticlePreviewHtml::normalize((string) $submission->preview_html)))'>
                                                    Preview
                                                </button>
                                            </li>
                                        @endif
                                        @if(!$submission->isInUse() && !$submission->isArchived())
                                            @php
                                                $editorPayload = base64_encode(json_encode([
                                                    'id' => $submission->id,
                                                    'title' => $submission->title,
                                                    'country' => $submission->country,
                                                    'language' => $submission->language,
                                                    'preview_html' => \App\Services\ContentUpload\ArticlePreviewHtml::normalize((string) $submission->preview_html),
                                                    'word_count' => $submission->word_count,
                                                    'moderation_status' => $submission->moderation_status,
                                                    'can_order' => $submission->canBeOrdered(),
                                                    'anchor_text' => $submission->anchor_text,
                                                    'target_url' => $submission->target_url,
                                                    'feature_image_url' => $submission->feature_image_url
                                                        ? \App\Services\ContentUpload\ArticlePreviewHtml::normalizeSrc((string) $submission->feature_image_url)
                                                        : null,
                                                ], JSON_UNESCAPED_UNICODE));
                                            @endphp
                                            <li>
                                                <button type="button" class="dropdown-item"
                                                        data-editor-payload="{{ $editorPayload }}"
                                                        onclick="openArticleEditor(JSON.parse(atob(this.dataset.editorPayload)))">
                                                    Edit article
                                                </button>
                                            </li>
                                        @endif
                                        <li>
                                            <a class="dropdown-item" href="{{ route('advertiser.content-submissions.download', $submission) }}">Download</a>
                                        </li>
                                        @if(!$submission->isInUse() && !$submission->isArchived())
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="toggleLibraryTitleEdit({{ $submission->id }}, true)">Rename</button>
                                            </li>
                                        @endif
                                        @if($submission->isArchived())
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="restoreLibraryArticle({{ $submission->id }})">Restore</button>
                                            </li>
                                        @elseif($availability !== 'in_progress')
                                            <li>
                                                <button type="button" class="dropdown-item" onclick="archiveLibraryArticle({{ $submission->id }})">Archive</button>
                                            </li>
                                        @endif
                                        @if(!$submission->isInUse())
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button type="button" class="dropdown-item text-danger"
                                                        onclick="deleteLibraryArticle({{ $submission->id }}, @js($submission->title ?: $submission->original_filename))">
                                                    Delete
                                                </button>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            @if(($availabilityFilter ?? 'all') === 'archived')
                                No archived articles.
                            @elseif(!empty($searchQuery) || ($availabilityFilter ?? 'all') !== 'all' || ($countryFilter ?? 'all') !== 'all' || ($languageFilter ?? 'all') !== 'all')
                                No articles match these filters.
                            @else
                                <div class="py-2">
                                    <p class="mb-2">No articles yet. Upload a .docx here, or start the guided Place a guest post flow.</p>
                                    <p class="small text-muted mb-3 mb-md-2">After approval, assign the article in your cart (or continue the wizard) and checkout.</p>
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadContentModal">
                                            <i class="fa fa-upload me-1"></i> Upload article
                                        </button>
                                        <a href="{{ route('advertiser.wizard.start') }}" class="btn btn-sm btn-outline-primary">
                                            Place a guest post
                                        </a>
                                    </div>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $submissions->links() }}</div>
</div>

{{-- Upload modal --}}
<div class="modal fade" id="uploadContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="libraryUploadForm">
            <div class="modal-header">
                <h5 class="modal-title">Upload article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small mb-3">
                    {{ $uploadCfg['help']['preferred_format'] ?? 'Please upload your article as a Microsoft Word (.docx) document only.' }}
                    After upload you can preview and edit the article (add/remove images and links) before ordering.
                </div>
                <div class="mb-3">
                    <label class="form-label">Title <span class="text-muted">(optional)</span></label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="Article title"
                           value="{{ $editSubmission->title ?? '' }}">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Language <span class="text-danger">*</span></label>
                        <select name="language" id="libraryLanguage" class="form-select" required>
                            <option value="">Select language</option>
                            @foreach(($languages ?? []) as $language)
                                <option value="{{ strtolower($language->code) }}"
                                    @selected(strtolower((string) ($editSubmission->language ?? '')) === strtolower($language->code))>
                                    {{ $language->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Article text must match this language (e.g. German text for German). English is allowed when English is selected.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Country <span class="text-danger">*</span></label>
                        <select name="country" id="libraryCountry" class="form-select" required disabled>
                            <option value="">Select language first</option>
                        </select>
                        <div class="form-text">Countries update to markets that match the language.</div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Microsoft Word document (.docx)</label>
                    <input type="file" name="file" id="libraryFileInput" class="form-control" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </div>
                <input type="hidden" name="replace_id" id="replaceIdInput" value="{{ $editSubmission->id ?? '' }}">
                <div id="libraryUploadFeedback" class="small" aria-live="polite"></div>
                <div class="progress d-none mt-2" id="libraryUploadProgress" style="height:6px;"><div class="progress-bar" style="width:0%"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="libraryUploadBtn">Upload &amp; preview</button>
            </div>
        </form>
    </div>
</div>

{{-- Docs-style editor modal --}}
<div class="modal fade" id="articleEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
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
                </div>
                <div id="articleEditorFeedback" class="small" aria-live="polite"></div>
            </div>
            <div class="modal-footer flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="articleEditorPreviewBtn">Preview</button>
                <button type="button" class="btn btn-primary" id="articleEditorSaveBtn">Save &amp; re-check</button>
                <a href="#" class="btn btn-success d-none" id="articleEditorOrderBtn">Order</a>
            </div>
        </div>
    </div>
</div>

{{-- Full preview modal --}}
<div class="modal fade" id="articlePreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="articlePreviewTitle">Article preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="library-preview" style="max-height:none;" id="articlePreviewBody"></div>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
const libraryUpdateUrl = @json(url('/advertiser/content-submissions'));
const libraryContentUrl = @json(url('/advertiser/content-submissions'));
const libraryImageUploadUrl = @json(route('advertiser.content-submissions.editor-image'));
const libraryOrderUrlBase = @json(url('/advertiser/content-library'));
const libraryCsrf = @json(csrf_token());
const libraryLanguageCountryMap = @json($languageCountryMap ?? new \stdClass());
const libraryPreferredCountry = @json(strtolower((string) ($editSubmission->country ?? '')));
let articleQuill = null;
let articleEditorSubmissionId = null;

function refreshLibraryCountries(preferredCountry) {
    const langSelect = document.getElementById('libraryLanguage');
    const countrySelect = document.getElementById('libraryCountry');
    if (!langSelect || !countrySelect) return;
    const lang = (langSelect.value || '').toLowerCase();
    const options = libraryLanguageCountryMap[lang] || [];
    const keep = (preferredCountry || countrySelect.value || '').toLowerCase();
    countrySelect.innerHTML = '';
    if (!lang) {
        countrySelect.disabled = true;
        countrySelect.innerHTML = '<option value="">Select language first</option>';
        return;
    }
    countrySelect.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select country';
    countrySelect.appendChild(placeholder);
    options.forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.code;
        opt.textContent = item.name;
        if (keep && keep === item.code) opt.selected = true;
        countrySelect.appendChild(opt);
    });
    if (keep && !Array.from(countrySelect.options).some(function (o) { return o.value === keep; })) {
        countrySelect.value = '';
    }
}
document.getElementById('libraryLanguage')?.addEventListener('change', function () {
    refreshLibraryCountries('');
});
document.addEventListener('DOMContentLoaded', function () {
    refreshLibraryCountries(libraryPreferredCountry);
});
document.getElementById('uploadContentModal')?.addEventListener('shown.bs.modal', function () {
    refreshLibraryCountries(libraryPreferredCountry || document.getElementById('libraryCountry')?.value || '');
});

function showLibraryFlash(message, ok) {
    const el = document.getElementById('libraryFlash');
    if (!el) return;
    el.className = 'alert alert-' + (ok ? 'success' : 'danger');
    el.textContent = message;
    el.classList.remove('d-none');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openPreviewModal(title, html) {
    document.getElementById('articlePreviewTitle').textContent = title || 'Article preview';
    const body = document.getElementById('articlePreviewBody');
    body.innerHTML = html || '';
    fixPreviewImages(body);
    new bootstrap.Modal(document.getElementById('articlePreviewModal')).show();
}

/**
 * Rewrite absolute /storage/... image URLs onto the current origin so previews
 * still work when APP_URL differs from the browser host.
 */
function fixPreviewImages(root) {
    if (!root) return;
    root.querySelectorAll('img').forEach(function (img) {
        const src = img.getAttribute('src') || '';
        const match = src.match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
        if (match) {
            img.setAttribute('src', match[1]);
        }
        img.addEventListener('error', function () {
            if (img.dataset.fallbackApplied) return;
            img.dataset.fallbackApplied = '1';
            // Last resort: if relative path failed and we still have an absolute, try same-origin.
            const again = (img.getAttribute('src') || '').match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
            if (again) {
                img.setAttribute('src', again[1]);
                return;
            }
            img.alt = 'Image failed to load';
            img.style.outline = '1px dashed #f59e0b';
            img.style.minHeight = '48px';
            img.style.background = '#fffbeb';
        });
    });
}

function ensureArticleQuill() {
    if (articleQuill || typeof Quill === 'undefined') {
        return articleQuill;
    }

    const toolbarOptions = [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'image'],
        ['clean'],
    ];

    articleQuill = new Quill('#articleQuillEditor', {
        theme: 'snow',
        placeholder: 'Edit your article…',
        modules: { toolbar: toolbarOptions },
    });

    const toolbar = articleQuill.getModule('toolbar');
    toolbar.addHandler('image', function () {
        const input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'image/png,image/jpeg,image/gif,image/webp');
        input.click();
        input.onchange = async function () {
            const file = input.files && input.files[0];
            if (!file) return;
            const feedback = document.getElementById('articleEditorFeedback');
            feedback.textContent = 'Uploading image…';
            const fd = new FormData();
            fd.append('image', file);
            try {
                const res = await fetch(libraryImageUploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (!res.ok || !data.success || !data.url) {
                    feedback.innerHTML = '<span class="text-danger">' + (data.message || data.error || 'Image upload failed') + '</span>';
                    return;
                }
                const range = articleQuill.getSelection(true) || { index: articleQuill.getLength() };
                articleQuill.insertEmbed(range.index, 'image', data.url, 'user');
                articleQuill.setSelection(range.index + 1);
                feedback.innerHTML = '<span class="text-success">Image added. You can remove it with Backspace/Delete.</span>';
            } catch (e) {
                feedback.innerHTML = '<span class="text-danger">Network error while uploading image.</span>';
            }
        };
    });

    return articleQuill;
}

function openArticleEditor(submission) {
    if (!submission || !submission.id) return;
    articleEditorSubmissionId = submission.id;
    ensureArticleQuill();
    document.getElementById('articleEditorTitle').value = submission.title || '';
    const market = ((submission.country || '') + '/' + (submission.language || '')).toUpperCase();
    const status = submission.moderation_status || '';
    document.getElementById('articleEditorMeta').textContent =
        market + (status ? ' · ' + status.replace(/_/g, ' ') : '') +
        (submission.word_count ? ' · ' + submission.word_count + ' words' : '');
    document.getElementById('articleEditorFeedback').textContent = '';
    if (articleQuill) {
        articleQuill.root.innerHTML = submission.preview_html || '<p><br></p>';
    }
    const orderBtn = document.getElementById('articleEditorOrderBtn');
    if (submission.can_order) {
        orderBtn.href = libraryOrderUrlBase + '/' + submission.id + '/order';
        orderBtn.classList.remove('d-none');
    } else {
        orderBtn.classList.add('d-none');
    }
    const uploadModalEl = document.getElementById('uploadContentModal');
    const uploadModal = bootstrap.Modal.getInstance(uploadModalEl);
    if (uploadModal) uploadModal.hide();
    new bootstrap.Modal(document.getElementById('articleEditorModal')).show();
}

async function saveArticleEditor() {
    if (!articleEditorSubmissionId || !articleQuill) return;
    const feedback = document.getElementById('articleEditorFeedback');
    const btn = document.getElementById('articleEditorSaveBtn');
    const html = articleQuill.root.innerHTML;
    const title = (document.getElementById('articleEditorTitle').value || '').trim();
    btn.disabled = true;
    feedback.textContent = 'Saving and re-checking…';
    try {
        const res = await fetch(libraryContentUrl + '/' + articleEditorSubmissionId + '/content', {
            method: 'PUT',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ preview_html: html, title: title }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            feedback.innerHTML = '<span class="text-danger">' + (data.message || 'Could not save article.') + '</span>';
            btn.disabled = false;
            return;
        }
        feedback.innerHTML = '<span class="text-success">' + (data.message || 'Article saved.') + '</span>';
        if (data.submission) {
            openArticleEditor(data.submission);
        }
        setTimeout(function () { window.location.reload(); }, 900);
    } catch (e) {
        feedback.innerHTML = '<span class="text-danger">Network error while saving.</span>';
        btn.disabled = false;
    }
}

document.getElementById('articleEditorSaveBtn')?.addEventListener('click', saveArticleEditor);
document.getElementById('articleEditorPreviewBtn')?.addEventListener('click', function () {
    if (!articleQuill) return;
    openPreviewModal(
        document.getElementById('articleEditorTitle').value || 'Article preview',
        articleQuill.root.innerHTML
    );
});

function toggleLibraryTitleEdit(id, open) {
    const edit = document.querySelector('[data-title-edit="' + id + '"]');
    if (!edit) return;
    edit.classList.toggle('d-none', !open);
    if (open) {
        const input = document.querySelector('[data-title-input="' + id + '"]');
        input?.focus();
        input?.select();
    }
}

async function saveLibraryTitle(id) {
    const input = document.querySelector('[data-title-input="' + id + '"]');
    if (!input) return;
    const title = (input.value || '').trim();
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': libraryCsrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ title: title }),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not rename article.', false);
            return;
        }
        const display = document.querySelector('[data-title-display="' + id + '"]');
        const nextTitle = (data.submission && data.submission.title) || title || (data.submission && data.submission.original_filename) || 'Article';
        if (display) {
            display.textContent = nextTitle;
            display.title = nextTitle;
        }
        toggleLibraryTitleEdit(id, false);
        showLibraryFlash('Article renamed.', true);
    } catch (e) {
        showLibraryFlash('Network error while renaming.', false);
    }
}

async function deleteLibraryArticle(id, label) {
    if (!window.confirm('Delete "' + (label || 'this article') + '"? This cannot be undone.')) {
        return;
    }
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not delete article.', false);
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article deleted.', true);
    } catch (e) {
        showLibraryFlash('Network error while deleting.', false);
    }
}

async function archiveLibraryArticle(id) {
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id + '/archive', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not archive article.', false);
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article archived.', true);
    } catch (e) {
        showLibraryFlash('Network error while archiving.', false);
    }
}

async function restoreLibraryArticle(id) {
    try {
        const res = await fetch(libraryUpdateUrl + '/' + id + '/restore', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': libraryCsrf, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            showLibraryFlash(data.message || 'Could not restore article.', false);
            return;
        }
        document.getElementById('library-row-' + id)?.remove();
        showLibraryFlash('Article restored.', true);
    } catch (e) {
        showLibraryFlash('Network error while restoring.', false);
    }
}

document.getElementById('libraryUploadForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fileInput = document.getElementById('libraryFileInput');
    const file = fileInput.files && fileInput.files[0];
    const feedback = document.getElementById('libraryUploadFeedback');
    const btn = document.getElementById('libraryUploadBtn');
    const progress = document.getElementById('libraryUploadProgress');
    const bar = progress.querySelector('.progress-bar');

    if (!file) return;
    if (!/\.docx$/i.test(file.name)) {
        feedback.innerHTML = '<span class="text-danger">Please upload a Microsoft Word (.docx) document only.</span>';
        return;
    }
    if (!document.getElementById('libraryCountry').value || !document.getElementById('libraryLanguage').value) {
        feedback.innerHTML = '<span class="text-danger">Please select country and language before uploading.</span>';
        return;
    }

    const fd = new FormData(this);
    btn.disabled = true;
    progress.classList.remove('d-none');
    bar.style.width = '40%';
    feedback.textContent = 'Uploading your article…';

    try {
        const res = await fetch(@json(route('advertiser.content-library.upload')), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' },
            body: fd,
        });
        bar.style.width = '100%';
        const data = await res.json();
        if (!data.success) {
            feedback.innerHTML = '<span class="text-danger">' + (data.message || 'Upload failed') + '</span>';
            btn.disabled = false;
            return;
        }
        feedback.innerHTML = '<span class="text-success">' + (data.message || 'Uploaded') + ' Opening editor…</span>';
        if (data.submission) {
            openArticleEditor(Object.assign({}, data.submission, {
                can_order: !!(data.submission.can_order || data.approved),
            }));
        } else {
            setTimeout(function () { window.location.href = @json(route('advertiser.content-library')); }, 800);
        }
        btn.disabled = false;
        progress.classList.add('d-none');
        bar.style.width = '0%';
    } catch (err) {
        feedback.innerHTML = '<span class="text-danger">Network error while uploading.</span>';
        btn.disabled = false;
    }
});

@if(!empty($openUpload))
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('uploadContentModal')).show();
});
@endif

if (window.location.hash === '#upload') {
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('uploadContentModal')).show();
    });
}
</script>
@endsection
