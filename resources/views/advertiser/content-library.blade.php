@extends('advertiser.layouts.app')

@section('content')
<style>
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
    .library-preview p { margin-bottom: .65rem; }
    .library-preview a { color: var(--brand-primary, #0b6266); word-break: break-all; }
    .library-preview-label {
        font-size: .72rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--brand-primary-soft, #3aaeb2);
        font-weight: 600;
        margin-bottom: .35rem;
    }
    .library-link-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--brand-primary-bg, #e8f8f7);
        color: var(--brand-primary, #0b6266);
        border-radius: 999px;
        padding: 4px 10px;
        font-size: .78rem;
        max-width: 100%;
    }
    .site-link-type {
        font-size: .72rem;
        padding: 1px 7px;
        border-radius: 999px;
        background: #f1f5f9;
        color: #64748b;
    }
    .site-link-type.is-nofollow {
        background: #fef3c7;
        color: #92400e;
    }
    #uploadResultPreview { display: none; }
</style>

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-semibold">Content Library</h2>
            <p class="text-muted mb-0">Upload Microsoft Word (.docx) articles by country and language. Only approved articles can be ordered.</p>
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

    <div class="alert alert-info border-0 shadow-sm">
        <strong>Before you upload:</strong>
        {{ $uploadCfg['help']['before_upload'] ?? 'Please upload your article as a Microsoft Word (.docx) document only. Maximum size: 5 MB.' }}
    </div>

    <div class="mb-2 d-flex flex-wrap gap-2">
        @foreach(['all' => 'All', 'approved' => 'Approved', 'needs_improvement' => 'Needs improvement', 'rejected' => 'Rejected', 'processing' => 'Processing'] as $key => $label)
            <a href="{{ route('advertiser.content-library', ['status' => $key, 'language' => $languageFilter ?? 'all']) }}"
               class="btn btn-sm {{ ($statusFilter ?? 'all') === $key ? 'btn-dark' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>
    <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
        <span class="small text-muted me-1">Language:</span>
        <a href="{{ route('advertiser.content-library', ['status' => $statusFilter ?? 'all', 'language' => 'all']) }}"
           class="btn btn-sm {{ ($languageFilter ?? 'all') === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
        @foreach(($groupedByLanguage ?? []) as $langCode => $count)
            <a href="{{ route('advertiser.content-library', ['status' => $statusFilter ?? 'all', 'language' => $langCode]) }}"
               class="btn btn-sm {{ ($languageFilter ?? 'all') === $langCode ? 'btn-primary' : 'btn-outline-primary' }}">
                {{ strtoupper($langCode) }} <span class="opacity-75">({{ $count }})</span>
            </a>
        @endforeach
    </div>

    <div class="row g-3">
        @forelse($submissions as $submission)
            @php
                $status = $submission->moderation_status;
                $badge = match($status) {
                    'approved' => 'success',
                    'needs_improvement' => 'warning',
                    'rejected' => 'danger',
                    'processing' => 'info',
                    default => 'secondary',
                };
            @endphp
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <h5 class="h6 mb-0">{{ $submission->title ?: $submission->original_filename }}</h5>
                            <span class="badge text-bg-{{ $badge }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
                        </div>
                        <div class="small text-muted mb-2">
                            {{ $submission->original_filename }} · {{ number_format($submission->word_count) }} words
                            @if($submission->country || $submission->language)
                                · <span class="library-link-chip">{{ strtoupper((string) $submission->country) }}/{{ strtoupper((string) $submission->language) }}</span>
                            @endif
                        </div>

                        @if($submission->preview_html)
                            <div class="mb-3">
                                <div class="library-preview-label">Article preview</div>
                                <div class="library-preview">{!! $submission->preview_html !!}</div>
                            </div>
                        @else
                            <div class="alert alert-light border small mb-3 mb-0">No preview available for this article.</div>
                        @endif

                        @if($submission->hasLink())
                            <div class="mb-3">
                                <span class="library-link-chip" title="{{ $submission->target_url }}">
                                    <i class="fa fa-link"></i>
                                    <span class="text-truncate">{{ $submission->anchor_text }}</span>
                                </span>
                            </div>
                        @else
                            <div class="small text-muted mb-3"><i class="fa fa-unlink me-1"></i> No link detected in this article</div>
                        @endif

                        @if($submission->evaluated_at)
                            <div class="border rounded-3 p-2 bg-light small mb-3">
                                <div class="fw-semibold mb-1">Article report</div>
                                <div class="d-flex gap-3 mb-2">
                                    <div><strong>Uniqueness</strong><br>{{ $submission->uniqueness_score !== null ? $submission->uniqueness_score.'%' : '—' }}</div>
                                    <div><strong>Quality</strong><br>{{ $submission->quality_score !== null ? $submission->quality_score.'%' : '—' }}</div>
                                </div>
                                @if(!empty($submission->evaluation_report['summary'] ?? null))
                                    <div class="text-muted">{{ $submission->evaluation_report['summary'] }}</div>
                                @endif
                            </div>
                        @endif

                        <div class="mt-auto d-flex flex-wrap gap-2">
                            <a href="{{ route('advertiser.content-submissions.download', $submission) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-download"></i> Document
                            </a>
                            @if($submission->preview_html)
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick='openPreviewModal(@json($submission->title ?: $submission->original_filename), @json($submission->preview_html))'>
                                    Full preview
                                </button>
                            @endif
                            @if($submission->needsCorrection())
                                <a class="btn btn-sm btn-outline-primary"
                                   href="{{ route('advertiser.content-library', ['edit' => $submission->id, 'upload' => 1]) }}">
                                    Edit & resubmit
                                </a>
                            @endif
                            @if($submission->canBeOrdered())
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="openOrderModal({{ $submission->id }}, @js($submission->title ?: $submission->original_filename), @js($submission->anchor_text), @js($submission->target_url), @js($submission->feature_image_url), {{ $submission->hasLink() ? 'true' : 'false' }}, @js($submission->country), @js($submission->language))">
                                    Select websites & order
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        No articles yet. Upload a .docx to get started.
                    </div>
                </div>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $submissions->links() }}</div>
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
                <div class="alert alert-warning small">
                    {{ $uploadCfg['help']['preferred_format'] ?? 'Please upload your article as a Microsoft Word (.docx) document only.' }}
                    Select country and language before uploading.
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
                    <div class="form-text">Other formats are not accepted. Anchor text and URL are filled automatically when found in the article.</div>
                </div>
                <input type="hidden" name="replace_id" id="replaceIdInput" value="{{ $editSubmission->id ?? '' }}">
                <div id="libraryUploadFeedback" class="small" aria-live="polite"></div>
                <div class="progress d-none mt-2" id="libraryUploadProgress" style="height:6px;"><div class="progress-bar" style="width:0%"></div></div>

                <div id="uploadResultPreview" class="mt-3">
                    <div class="library-preview-label">Article preview</div>
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

{{-- Order modal --}}
<div class="modal fade" id="orderContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" action="{{ route('advertiser.content-library.order') }}" id="libraryOrderForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Place order with <span id="orderArticleTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="content_submission_id" id="orderSubmissionId">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Anchor text <span class="text-muted">(optional)</span></label>
                        <input type="text" name="anchor_text" id="orderAnchor" class="form-control" maxlength="120">
                        <div class="form-text">Filled automatically from the article when a link is found. You can edit it.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Target URL (HTTPS) <span class="text-muted">(optional)</span></label>
                        <input type="url" name="target_url" id="orderTarget" class="form-control" placeholder="https://">
                        <div class="form-text">Filled automatically from the article when a link is found.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Feature Image URL <span class="text-muted">(optional)</span></label>
                        <input type="url" name="feature_image_url" id="orderFeature" class="form-control" placeholder="https://...">
                    </div>
                </div>

                <div id="noLinkNotice" class="alert alert-warning d-none">
                    No link was found in this article (and none was entered).
                    You can still continue without a link if you want.
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="allow_no_link" value="1" id="allowNoLink">
                        <label class="form-check-label" for="allowNoLink">Continue without a link</label>
                    </div>
                </div>

                <div id="nofollowNotice" class="alert alert-warning d-none">
                    One or more selected websites accept <strong>nofollow</strong> links only.
                    Your placement will be published as nofollow on those sites.
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="acknowledge_nofollow" value="1" id="acknowledgeNofollow">
                        <label class="form-check-label" for="acknowledgeNofollow">I understand and want to continue</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Select websites for publication</label>
                    <div class="border rounded-3 p-3" style="max-height:260px;overflow:auto;">
                        @forelse($sites as $site)
                            @php
                                $siteCountries = $site->countryCodes();
                                $siteLanguages = $site->languageCodes();
                            @endphp
                            <div class="form-check mb-2">
                                <input class="form-check-input site-order-check" type="checkbox" name="site_ids[]"
                                       value="{{ $site->id }}" id="site_{{ $site->id }}"
                                       data-link-type="{{ $site->link_type ?? 'dofollow' }}"
                                       data-countries="{{ implode(',', $siteCountries) }}"
                                       data-languages="{{ implode(',', $siteLanguages) }}">
                                <label class="form-check-label" for="site_{{ $site->id }}">
                                    {{ $site->site_name }}
                                    <span class="text-muted small">· €{{ number_format((float)$site->price * 1.15, 2) }}</span>
                                    <span class="site-link-type">{{ strtoupper(implode('/', $siteCountries ?: ['any'])) }}/{{ strtoupper(implode('/', $siteLanguages ?: ['any'])) }}</span>
                                    <span class="site-link-type {{ ($site->link_type ?? '') === 'nofollow' ? 'is-nofollow' : '' }}">
                                        {{ ($site->link_type ?? 'dofollow') === 'nofollow' ? 'nofollow' : 'dofollow' }}
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
                        <label class="form-check-label" for="libPubScheduled">Schedule Publication (charged in advance; publisher notified now)</label>
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
document.getElementById('libPubScheduled')?.addEventListener('change', syncLibSchedule);
document.getElementById('libPubImmediate')?.addEventListener('change', syncLibSchedule);
function syncLibSchedule() {
    document.getElementById('libScheduleFields').classList.toggle('d-none', !document.getElementById('libPubScheduled').checked);
}

function openReplaceUpload(id) {
    document.getElementById('replaceIdInput').value = id;
    document.getElementById('uploadResultPreview').style.display = 'none';
    new bootstrap.Modal(document.getElementById('uploadContentModal')).show();
}

function openPreviewModal(title, html) {
    document.getElementById('articlePreviewTitle').textContent = title || 'Article preview';
    document.getElementById('articlePreviewBody').innerHTML = html || '';
    new bootstrap.Modal(document.getElementById('articlePreviewModal')).show();
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
    const checks = document.querySelectorAll('.site-order-check:checked');
    let hasNofollow = false;
    checks.forEach(function (el) {
        if ((el.dataset.linkType || '') === 'nofollow') hasNofollow = true;
    });
    const notice = document.getElementById('nofollowNotice');
    const ack = document.getElementById('acknowledgeNofollow');
    const hasLink = (document.getElementById('orderAnchor').value || '').trim() !== ''
        || (document.getElementById('orderTarget').value || '').trim() !== '';
    const show = hasNofollow && hasLink;
    notice.classList.toggle('d-none', !show);
    if (!show) ack.checked = false;
}

function openOrderModal(id, title, anchor, target, feature, hasLink, country, language) {
    document.getElementById('orderSubmissionId').value = id;
    document.getElementById('orderArticleTitle').textContent = title || 'article';
    document.getElementById('orderAnchor').value = anchor || '';
    document.getElementById('orderTarget').value = target || '';
    document.getElementById('orderFeature').value = feature || '';
    document.getElementById('allowNoLink').checked = false;
    document.getElementById('acknowledgeNofollow').checked = false;
    const c = (country || '').toLowerCase();
    const l = (language || '').toLowerCase();
    document.querySelectorAll('.site-order-check').forEach(function (el) {
        el.checked = false;
        const siteCountries = (el.dataset.countries || '').toLowerCase().split(',').filter(Boolean);
        const siteLanguages = (el.dataset.languages || '').toLowerCase().split(',').filter(Boolean);
        const countryOk = !c || siteCountries.length === 0 || siteCountries.includes(c);
        const languageOk = !l || siteLanguages.length === 0 || siteLanguages.includes(l);
        const wrap = el.closest('.form-check');
        if (wrap) wrap.style.display = (countryOk && languageOk) ? '' : 'none';
    });
    syncNoLinkNotice();
    syncNofollowNotice();
    new bootstrap.Modal(document.getElementById('orderContentModal')).show();
}

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
    const anchor = (document.getElementById('orderAnchor').value || '').trim();
    const target = (document.getElementById('orderTarget').value || '').trim();
    if (!anchor && !target && !document.getElementById('allowNoLink').checked) {
        e.preventDefault();
        syncNoLinkNotice();
        document.getElementById('noLinkNotice').classList.remove('d-none');
        document.getElementById('allowNoLink').focus();
        return;
    }
    const hasNofollow = Array.from(document.querySelectorAll('.site-order-check:checked'))
        .some(function (el) { return (el.dataset.linkType || '') === 'nofollow'; });
    if (hasNofollow && (anchor || target) && !document.getElementById('acknowledgeNofollow').checked) {
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
            if (data.has_link && data.submission.anchor_text) {
                linkInfo.textContent = 'Detected link: ' + data.submission.anchor_text + ' → ' + (data.submission.target_url || '');
            } else {
                linkInfo.textContent = 'No link detected in this article. You can add one when placing an order, or continue without a link.';
            }
            previewWrap.style.display = 'block';
        }
        setTimeout(function () { window.location.href = @json(route('advertiser.content-library')); }, 1600);
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
