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
    .site-link-type {
        font-size: .72rem;
        padding: 1px 7px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #64748b;
    }
    .site-link-type.is-nofollow { background: #fef3c7; color: #92400e; }
    .site-order-row.is-filtered-out { display: none !important; }
    #uploadResultPreview { display: none; }
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
    .library-actions .btn { white-space: nowrap; }
    .library-filter-bar .form-select { min-width: 140px; }
</style>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="mb-1 fw-semibold">Content Library</h2>
            <p class="text-muted mb-0 small">One article → one website. Upload a .docx, then order when approved.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadContentModal" id="openUploadModalBtn">
            <i class="fa fa-upload me-1"></i> Upload article
        </button>
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
                                    <button type="button" class="btn btn-sm btn-primary"
                                            onclick="openOrderModal({{ $submission->id }}, @js($submission->title ?: $submission->original_filename), @js($submission->anchor_text), @js($submission->target_url), @js($submission->feature_image_url), {{ $submission->hasLink() ? 'true' : 'false' }}, @js($submission->country), @js($submission->language))">
                                        Order
                                    </button>
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
                                No articles yet. Upload a .docx to get started.
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
                    Each approved article can be ordered on one website.
                </div>
                <div class="mb-3">
                    <label class="form-label">Title <span class="text-muted">(optional)</span></label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="Article title"
                           value="{{ $editSubmission->title ?? '' }}">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Country <span class="text-danger">*</span></label>
                        <select name="country" id="libraryCountry" class="form-select" required>
                            <option value="">Select country</option>
                            @foreach(($countries ?? []) as $country)
                                <option value="{{ strtolower($country->code) }}"
                                    @selected(strtolower((string) ($editSubmission->country ?? '')) === strtolower($country->code))>
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
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
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Microsoft Word document (.docx)</label>
                    <input type="file" name="file" id="libraryFileInput" class="form-control" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </div>
                <input type="hidden" name="replace_id" id="replaceIdInput" value="{{ $editSubmission->id ?? '' }}">
                <div id="libraryUploadFeedback" class="small" aria-live="polite"></div>
                <div class="progress d-none mt-2" id="libraryUploadProgress" style="height:6px;"><div class="progress-bar" style="width:0%"></div></div>
                <div id="uploadResultPreview" class="mt-3">
                    <div class="library-preview" id="uploadResultPreviewBody"></div>
                    <div class="small text-muted mt-2" id="uploadResultLinkInfo"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="libraryUploadBtn">Upload article</button>
            </div>
        </form>
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

{{-- Order modal: single site --}}
<div class="modal fade" id="orderContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" action="{{ route('advertiser.content-library.order') }}" id="libraryOrderForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Order <span id="orderArticleTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="content_submission_id" id="orderSubmissionId">
                <div class="alert alert-info small">Select <strong>one</strong> website for this article.</div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Anchor text <span class="text-muted">(optional)</span></label>
                        <input type="text" name="anchor_text" id="orderAnchor" class="form-control" maxlength="120">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Target URL (HTTPS) <span class="text-muted">(optional)</span></label>
                        <input type="url" name="target_url" id="orderTarget" class="form-control" placeholder="https://">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Feature Image URL <span class="text-muted">(optional)</span></label>
                        <input type="url" name="feature_image_url" id="orderFeature" class="form-control" placeholder="https://...">
                    </div>
                </div>

                <div id="noLinkNotice" class="alert alert-warning d-none">
                    No link was found in this article (and none was entered).
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="allow_no_link" value="1" id="allowNoLink">
                        <label class="form-check-label" for="allowNoLink">Continue without a link</label>
                    </div>
                </div>

                <div id="nofollowNotice" class="alert alert-warning d-none">
                    The selected website accepts <strong>nofollow</strong> links only.
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="acknowledge_nofollow" value="1" id="acknowledgeNofollow">
                        <label class="form-check-label" for="acknowledgeNofollow">I understand and want to continue</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Website</label>
                    <input type="search" id="siteOrderSearch" class="form-control form-control-sm mb-2"
                           placeholder="Search by name or URL">
                    <div class="small text-muted mb-2" id="siteOrderCount"></div>
                    <div class="border rounded-3 p-3" style="max-height:260px;overflow:auto;" id="siteOrderList">
                        @forelse($sites as $site)
                            <div class="form-check mb-2 site-order-row"
                                 data-site-name="{{ strtolower($site['site_name']) }}"
                                 data-site-url="{{ strtolower($site['site_url'] ?? '') }}">
                                <input class="form-check-input site-order-check" type="radio" name="site_id"
                                       value="{{ $site['id'] }}" id="site_{{ $site['id'] }}"
                                       data-link-type="{{ $site['link_type'] ?? 'dofollow' }}"
                                       data-countries="{{ implode(',', $site['countries'] ?? []) }}"
                                       data-languages="{{ implode(',', $site['languages'] ?? []) }}">
                                <label class="form-check-label" for="site_{{ $site['id'] }}">
                                    {{ $site['site_name'] }}
                                    <span class="text-muted small">· €{{ number_format((float) $site['advertiser_price'], 2) }}</span>
                                    @if(($site['discount_percent'] ?? 0) > 0)
                                        <span class="site-link-type">−{{ rtrim(rtrim(number_format((float) $site['discount_percent'], 2), '0'), '.') }}%</span>
                                    @endif
                                    <span class="site-link-type">{{ strtoupper(implode('/', ($site['countries'] ?? []) ?: ['any'])) }}/{{ strtoupper(implode('/', ($site['languages'] ?? []) ?: ['any'])) }}</span>
                                    <span class="site-link-type {{ ($site['link_type'] ?? '') === 'nofollow' ? 'is-nofollow' : '' }}">
                                        {{ ($site['link_type'] ?? 'dofollow') === 'nofollow' ? 'nofollow' : 'dofollow' }}
                                    </span>
                                </label>
                            </div>
                        @empty
                            <div class="text-muted">No active websites available.</div>
                        @endforelse
                    </div>
                </div>

                <div class="mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="publication_mode" id="libPubImmediate" value="immediate" checked>
                        <label class="form-check-label" for="libPubImmediate">Publish Immediately</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="publication_mode" id="libPubScheduled" value="scheduled">
                        <label class="form-check-label" for="libPubScheduled">Schedule Publication</label>
                    </div>
                </div>
                <div id="libScheduleFields" class="row g-2 d-none">
                    <div class="col-md-4"><input type="date" name="scheduled_date" class="form-control" min="{{ now()->toDateString() }}" max="{{ now()->addMonths(3)->toDateString() }}"></div>
                    <div class="col-md-4"><input type="time" name="scheduled_time" class="form-control" value="09:00"></div>
                    <div class="col-md-4">
                        <select name="timezone" class="form-select">
                            <option value="UTC" selected>UTC</option>
                            <option value="Europe/London">Europe/London</option>
                            <option value="Europe/Berlin">Europe/Berlin</option>
                            <option value="America/New_York">America/New_York</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Continue to checkout</button>
            </div>
        </form>
    </div>
</div>

<script>
const libraryUpdateUrl = @json(url('/advertiser/content-submissions'));
const libraryCsrf = @json(csrf_token());

document.getElementById('libPubScheduled')?.addEventListener('change', syncLibSchedule);
document.getElementById('libPubImmediate')?.addEventListener('change', syncLibSchedule);
function syncLibSchedule() {
    document.getElementById('libScheduleFields').classList.toggle('d-none', !document.getElementById('libPubScheduled').checked);
}

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

function syncNoLinkNotice() {
    const anchor = (document.getElementById('orderAnchor').value || '').trim();
    const target = (document.getElementById('orderTarget').value || '').trim();
    const notice = document.getElementById('noLinkNotice');
    const allow = document.getElementById('allowNoLink');
    const missing = !anchor && !target;
    notice.classList.toggle('d-none', !missing);
    if (!missing) allow.checked = false;
}

function syncNofollowNotice() {
    const selected = document.querySelector('.site-order-check:checked');
    const notice = document.getElementById('nofollowNotice');
    const ack = document.getElementById('acknowledgeNofollow');
    const hasLink = (document.getElementById('orderAnchor').value || '').trim() !== ''
        || (document.getElementById('orderTarget').value || '').trim() !== '';
    const show = !!(selected && (selected.dataset.linkType || '') === 'nofollow' && hasLink);
    notice.classList.toggle('d-none', !show);
    if (!show) ack.checked = false;
}

function filterSiteOrderList() {
    const search = (document.getElementById('siteOrderSearch')?.value || '').toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.site-order-row').forEach(function (row) {
        if (row.dataset.marketMatch === '0') {
            row.classList.add('is-filtered-out');
            return;
        }
        const name = row.dataset.siteName || '';
        const url = row.dataset.siteUrl || '';
        const match = !search || name.includes(search) || url.includes(search);
        row.classList.toggle('is-filtered-out', !match);
        if (match) visible += 1;
    });
    const countEl = document.getElementById('siteOrderCount');
    if (countEl) {
        countEl.textContent = visible + ' matching website' + (visible === 1 ? '' : 's') + ' · pick one';
    }
}

function openOrderModal(id, title, anchor, target, feature, hasLink, country, language) {
    document.getElementById('orderSubmissionId').value = id;
    document.getElementById('orderArticleTitle').textContent = title || 'article';
    document.getElementById('orderAnchor').value = anchor || '';
    document.getElementById('orderTarget').value = target || '';
    document.getElementById('orderFeature').value = feature || '';
    document.getElementById('allowNoLink').checked = false;
    document.getElementById('acknowledgeNofollow').checked = false;
    const search = document.getElementById('siteOrderSearch');
    if (search) search.value = '';
    const c = (country || '').toLowerCase();
    const l = (language || '').toLowerCase();
    document.querySelectorAll('.site-order-check').forEach(function (el) {
        el.checked = false;
        const siteCountries = (el.dataset.countries || '').toLowerCase().split(',').filter(Boolean);
        const siteLanguages = (el.dataset.languages || '').toLowerCase().split(',').filter(Boolean);
        const countryOk = !c || siteCountries.length === 0 || siteCountries.includes(c);
        const languageOk = !l || siteLanguages.length === 0 || siteLanguages.includes(l);
        const wrap = el.closest('.site-order-row');
        if (wrap) wrap.dataset.marketMatch = (countryOk && languageOk) ? '1' : '0';
    });
    filterSiteOrderList();
    syncNoLinkNotice();
    syncNofollowNotice();
    new bootstrap.Modal(document.getElementById('orderContentModal')).show();
}

document.getElementById('siteOrderSearch')?.addEventListener('input', filterSiteOrderList);
document.getElementById('orderAnchor')?.addEventListener('input', function () {
    syncNoLinkNotice();
    syncNofollowNotice();
});
document.getElementById('orderTarget')?.addEventListener('input', function () {
    syncNoLinkNotice();
    syncNofollowNotice();
});
document.querySelectorAll('.site-order-check').forEach(function (el) {
    el.addEventListener('change', syncNofollowNotice);
});

document.getElementById('libraryOrderForm')?.addEventListener('submit', function (e) {
    if (!document.querySelector('.site-order-check:checked')) {
        e.preventDefault();
        showLibraryFlash('Please select one website.', false);
        return;
    }
    const anchor = (document.getElementById('orderAnchor').value || '').trim();
    const target = (document.getElementById('orderTarget').value || '').trim();
    if (!anchor && !target && !document.getElementById('allowNoLink').checked) {
        e.preventDefault();
        syncNoLinkNotice();
        document.getElementById('noLinkNotice').classList.remove('d-none');
        document.getElementById('allowNoLink').focus();
        return;
    }
    const selected = document.querySelector('.site-order-check:checked');
    if (selected && (selected.dataset.linkType || '') === 'nofollow' && (anchor || target)
        && !document.getElementById('acknowledgeNofollow').checked) {
        e.preventDefault();
        syncNofollowNotice();
        document.getElementById('nofollowNotice').classList.remove('d-none');
        document.getElementById('acknowledgeNofollow').focus();
    }
});

document.getElementById('libraryUploadForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fileInput = document.getElementById('libraryFileInput');
    const file = fileInput.files && fileInput.files[0];
    const feedback = document.getElementById('libraryUploadFeedback');
    const btn = document.getElementById('libraryUploadBtn');
    const progress = document.getElementById('libraryUploadProgress');
    const bar = progress.querySelector('.progress-bar');
    const previewWrap = document.getElementById('uploadResultPreview');
    const previewBody = document.getElementById('uploadResultPreviewBody');
    const linkInfo = document.getElementById('uploadResultLinkInfo');

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
    previewWrap.style.display = 'none';

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
        feedback.innerHTML = '<span class="text-success">' + (data.message || 'Uploaded') + '</span>';
        if (data.submission && data.submission.preview_html) {
            previewBody.innerHTML = data.submission.preview_html;
            linkInfo.textContent = data.has_link && data.submission.anchor_text
                ? ('Detected link: ' + data.submission.anchor_text)
                : 'No link detected. You can add one when ordering.';
            previewWrap.style.display = 'block';
        }
        setTimeout(function () { window.location.href = @json(route('advertiser.content-library')); }, 1200);
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
