@extends('advertiser.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h2 class="mb-1 fw-semibold">Content Library</h2>
            <p class="text-muted mb-0">Upload Microsoft Word (.docx) articles, review the report for each article, then select websites and place orders.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadContentModal">
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

    <div class="mb-3 d-flex flex-wrap gap-2">
        @foreach(['all' => 'All', 'approved' => 'Approved', 'needs_improvement' => 'Needs improvement', 'rejected' => 'Rejected', 'processing' => 'Processing'] as $key => $label)
            <a href="{{ route('advertiser.content-library', ['status' => $key]) }}"
               class="btn btn-sm {{ ($statusFilter ?? 'all') === $key ? 'btn-dark' : 'btn-outline-secondary' }}">{{ $label }}</a>
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
                        <div class="small text-muted mb-2">{{ $submission->original_filename }} · {{ number_format($submission->word_count) }} words</div>
                        @if($submission->evaluated_at)
                            <div class="border rounded-3 p-2 bg-light small mb-3">
                                <div class="fw-semibold mb-1">Article report</div>
                                <div class="d-flex gap-3 mb-2">
                                    <div><strong>Uniqueness</strong><br>{{ $submission->uniqueness_score !== null ? $submission->uniqueness_score.'%' : '—' }}</div>
                                    <div><strong>Quality</strong><br>{{ $submission->quality_score !== null ? $submission->quality_score.'%' : '—' }}</div>
                                </div>
                                @if(!empty($submission->evaluation_report['summary'] ?? null))
                                    <div class="text-muted">{{ $submission->evaluation_report['summary'] }}</div>
                                @elseif(is_string(data_get($submission->evaluation_report, 'ai_notes')))
                                    <div class="text-muted">{{ data_get($submission->evaluation_report, 'ai_notes') }}</div>
                                @endif
                            </div>
                        @endif
                        @if($submission->preview_html)
                            <div class="border rounded-3 p-2 bg-light small mb-3" style="max-height:120px;overflow:auto;">{!! $submission->preview_html !!}</div>
                        @endif
                        <div class="mt-auto d-flex flex-wrap gap-2">
                            <a href="{{ route('advertiser.content-submissions.download', $submission) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-download"></i> Document
                            </a>
                            @if(in_array($status, ['needs_improvement', 'rejected', 'error'], true))
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="openReplaceUpload({{ $submission->id }})">Resubmit .docx</button>
                            @endif
                            @if($submission->canBeOrdered())
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="openOrderModal({{ $submission->id }}, @js($submission->title ?: $submission->original_filename), @js($submission->anchor_text), @js($submission->target_url), @js($submission->feature_image_url))">
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
    <div class="modal-dialog">
        <form class="modal-content" id="libraryUploadForm">
            <div class="modal-header">
                <h5 class="modal-title">Upload article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small">
                    {{ $uploadCfg['help']['preferred_format'] ?? 'Please upload your article as a Microsoft Word (.docx) document only.' }}
                </div>
                <div class="mb-3">
                    <label class="form-label">Title <span class="text-muted">(optional)</span></label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="Article title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Microsoft Word document (.docx)</label>
                    <input type="file" name="file" id="libraryFileInput" class="form-control" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                    <div class="form-text">Other formats are not accepted.</div>
                </div>
                <input type="hidden" name="replace_id" id="replaceIdInput" value="">
                <div id="libraryUploadFeedback" class="small" aria-live="polite"></div>
                <div class="progress d-none mt-2" id="libraryUploadProgress" style="height:6px;"><div class="progress-bar" style="width:0%"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="libraryUploadBtn">Upload article</button>
            </div>
        </form>
    </div>
</div>

{{-- Order modal --}}
<div class="modal fade" id="orderContentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" action="{{ route('advertiser.content-library.order') }}">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Place order with <span id="orderArticleTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="content_submission_id" id="orderSubmissionId">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Anchor Text</label>
                        <input type="text" name="anchor_text" id="orderAnchor" class="form-control" maxlength="120" required>
                        <div class="form-text">{{ $uploadCfg['help']['anchor_text'] ?? '' }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Target URL (HTTPS)</label>
                        <input type="url" name="target_url" id="orderTarget" class="form-control" placeholder="https://" required>
                        <div class="form-text">{{ $uploadCfg['help']['target_url'] ?? '' }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Feature Image URL <span class="text-muted">(optional)</span></label>
                        <input type="url" name="feature_image_url" id="orderFeature" class="form-control" placeholder="https://...">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Select websites for publication</label>
                    <div class="border rounded-3 p-3" style="max-height:260px;overflow:auto;">
                        @forelse($sites as $site)
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="site_ids[]" value="{{ $site->id }}" id="site_{{ $site->id }}">
                                <label class="form-check-label" for="site_{{ $site->id }}">
                                    {{ $site->site_name }}
                                    <span class="text-muted small">· €{{ number_format((float)$site->price * 1.15, 2) }}</span>
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
    new bootstrap.Modal(document.getElementById('uploadContentModal')).show();
}

function openOrderModal(id, title, anchor, target, feature) {
    document.getElementById('orderSubmissionId').value = id;
    document.getElementById('orderArticleTitle').textContent = title || 'article';
    document.getElementById('orderAnchor').value = anchor || '';
    document.getElementById('orderTarget').value = target || '';
    document.getElementById('orderFeature').value = feature || '';
    new bootstrap.Modal(document.getElementById('orderContentModal')).show();
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
        feedback.innerHTML = '<span class="text-success">' + (data.message || 'Uploaded') + '</span>';
        setTimeout(function () { window.location.reload(); }, 700);
    } catch (err) {
        feedback.innerHTML = '<span class="text-danger">Network error while uploading.</span>';
        btn.disabled = false;
    }
});
</script>
@endsection
