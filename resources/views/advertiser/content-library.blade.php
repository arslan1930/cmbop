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
    $statusBadge = [
        'available' => 'success',
        'in_progress' => 'primary',
        'published' => 'success',
        'needs_fix' => 'warning',
        'expired' => 'secondary',
        'archived' => 'secondary',
        'unavailable' => 'light',
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
    <div class="mb-3">
        <h2 class="mb-1 fw-semibold">Content Library</h2>
        <p class="text-muted mb-0 small">
            Start here: <strong>upload</strong> a .docx (language first, then country) → wait for <strong>approval</strong> →
            click <strong>Order</strong> to pick matching publishers → assign one article per site in your cart → <strong>pay</strong>.
            Multi-site orders need a different approved article for each website.
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

    <form method="GET" action="{{ route('advertiser.content-library') }}" class="library-filter-bar row g-2 align-items-end mb-3">
        <div class="col-md-4 col-lg-3">
            <label class="form-label small text-muted mb-1" for="librarySearchInput">Search</label>
            <input type="search" name="q" id="librarySearchInput" class="form-control form-control-sm"
                   value="{{ $searchQuery ?? '' }}" placeholder="Title or filename">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small text-muted mb-1">Status</label>
            <select name="availability" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach([
                    'all' => 'Active',
                    'available' => 'Available',
                    'in_progress' => 'In progress',
                    'published' => 'Published',
                    'needs_fix' => 'Needs fix',
                    'expired' => 'Expired',
                    'archived' => 'Archived',
                ] as $key => $label)
                    <option value="{{ $key }}" @selected(($availabilityFilter ?? 'all') === $key)>{{ $label }}</option>
                @endforeach
            </select>
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
            @if(!empty($searchQuery) || ($availabilityFilter ?? 'all') !== 'all' || ($countryFilter ?? 'all') !== 'all' || ($languageFilter ?? 'all') !== 'all')
                <a href="{{ route('advertiser.content-library') }}" class="btn btn-sm btn-link">Reset</a>
            @endif
        </div>
    </form>

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
                        $badge = $statusBadge[$availability] ?? 'secondary';
                        $label = $statusLabels[$availability] ?? ucfirst(str_replace('_', ' ', $availability));
                    @endphp
                    <tr id="library-row-{{ $submission->id }}">
                        <td>
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
                                <div class="library-live-link text-warning">
                                    {{ $submission->evaluation_report['summary'] ?? 'Fix issues and resubmit.' }}
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
                            <span class="badge text-bg-{{ $badge }}">{{ $label }}</span>
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
                                                        onclick='openPreviewModal(@json($submission->title ?: $submission->original_filename), @json($submission->preview_html))'>
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
                                                    'preview_html' => $submission->preview_html,
                                                    'word_count' => $submission->word_count,
                                                    'moderation_status' => $submission->moderation_status,
                                                    'can_order' => $submission->canBeOrdered(),
                                                    'anchor_text' => $submission->anchor_text,
                                                    'target_url' => $submission->target_url,
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
                                    <p class="mb-2">No articles yet. Upload a .docx to start your first guest post.</p>
                                    <p class="small text-muted mb-3 mb-md-2">After approval, use <strong>Order</strong> to open the catalog for that language, assign the article in your cart, and checkout.</p>
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadContentModal">
                                        <i class="fa fa-upload me-1"></i> Upload article
                                    </button>
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
                        <div class="form-text">English articles can be ordered on any English-country website.</div>
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
    document.getElementById('articlePreviewBody').innerHTML = html || '';
    new bootstrap.Modal(document.getElementById('articlePreviewModal')).show();
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
