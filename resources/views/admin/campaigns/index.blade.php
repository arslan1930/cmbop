@extends('admin.layouts.app')

@section('content')
@php
    $preselect = request('audience', 'advertisers');
    if (!in_array($preselect, ['advertisers', 'publishers', 'both', 'selected'], true)) {
        $preselect = 'advertisers';
    }
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Updates &amp; Campaigns</h1>
            <p class="text-muted mb-0">Email promotions and platform updates to advertisers, publishers, or a custom selection.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.audiences.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-address-book me-1"></i> Audience Inventory
            </a>
            <a href="{{ route('admin.emails.index') }}" class="btn btn-sm btn-outline-secondary">
                Email Center
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertisers available</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Publishers available</div>
                    <h3 class="mb-0">{{ number_format($stats['publishers']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Unique combined</div>
                    <h3 class="mb-0">{{ number_format($stats['both_unique']) }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-paper-plane me-2 text-primary"></i>Compose campaign</strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.campaigns.send') }}" id="campaignForm">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Internal name (optional)</label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" maxlength="120" placeholder="BF25 advertiser blast">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Audience</label>
                                <select name="audience" id="campaignAudience" class="form-select" required>
                                    <option value="advertisers" @selected(old('audience', $preselect) === 'advertisers')>All Advertisers ({{ $stats['advertisers'] }})</option>
                                    <option value="publishers" @selected(old('audience', $preselect) === 'publishers')>All Publishers ({{ $stats['publishers'] }})</option>
                                    <option value="both" @selected(old('audience', $preselect) === 'both')>Advertisers + Publishers ({{ $stats['both_unique'] }} unique)</option>
                                    <option value="selected" @selected(old('audience', $preselect) === 'selected')>Select specific users…</option>
                                </select>
                            </div>

                            <div class="col-12" id="selectedUsersWrap" style="display:none;">
                                <label class="form-label">Select recipients</label>
                                <div class="border rounded-3 p-3" style="max-height:260px; overflow:auto;">
                                    <div class="mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllAdv">All advertisers</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllPub">All publishers</button>
                                        <button type="button" class="btn btn-sm btn-outline-dark" id="clearSelected">Clear</button>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <div class="fw-semibold small mb-1">Advertisers</div>
                                            @foreach($advertisers as $user)
                                                <div class="form-check">
                                                    <input class="form-check-input user-check adv-check" type="checkbox" name="user_ids[]" value="{{ $user->id }}" id="u{{ $user->id }}"
                                                        @checked(collect(old('user_ids', []))->contains($user->id))>
                                                    <label class="form-check-label small" for="u{{ $user->id }}">{{ $user->name }} <span class="text-muted">&lt;{{ $user->email }}&gt;</span></label>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="col-md-6">
                                            <div class="fw-semibold small mb-1">Publishers</div>
                                            @foreach($publishers as $user)
                                                @php $dup = $advertisers->contains('id', $user->id); @endphp
                                                @if(!$dup)
                                                    <div class="form-check">
                                                        <input class="form-check-input user-check pub-check" type="checkbox" name="user_ids[]" value="{{ $user->id }}" id="p{{ $user->id }}"
                                                            @checked(collect(old('user_ids', []))->contains($user->id))>
                                                        <label class="form-check-label small" for="p{{ $user->id }}">{{ $user->name }} <span class="text-muted">&lt;{{ $user->email }}&gt;</span></label>
                                                    </div>
                                                @else
                                                    <div class="form-check">
                                                        <input class="form-check-input user-check pub-check" type="checkbox" value="{{ $user->id }}" id="p{{ $user->id }}" disabled>
                                                        <label class="form-check-label small text-muted" for="p{{ $user->id }}">{{ $user->name }} (also advertiser)</label>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text"><span id="selectedCount">0</span> user(s) selected</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Subject</label>
                                <input type="text" name="subject" id="campaignSubject" class="form-control" value="{{ old('subject') }}" required maxlength="180" placeholder="Black Friday update for our partners">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message (HTML allowed: p, strong, em, lists, links)</label>
                                <textarea name="body_html" id="campaignBody" class="form-control" rows="8" required maxlength="20000" placeholder="<p>Share your update, discount, or promotion here.</p>">{{ old('body_html') }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA label (optional)</label>
                                <input type="text" name="cta_label" class="form-control" value="{{ old('cta_label') }}" maxlength="80" placeholder="View offer">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA URL (optional)</label>
                                <input type="url" name="cta_url" class="form-control" value="{{ old('cta_url') }}" maxlength="500" placeholder="https://">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="respect_preferences" value="1" id="respect_preferences"
                                        @checked(old('respect_preferences', true))>
                                    <label class="form-check-label" for="respect_preferences">
                                        Respect user “Marketing Emails” preference (recommended)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-primary" onclick="return confirm('Send this campaign to the selected audience now?')">
                                <i class="fa fa-paper-plane me-1"></i> Send campaign
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                                <i class="fa fa-eye me-1"></i> Preview email
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-eye me-2 text-primary"></i>Preview</strong>
                </div>
                <div class="card-body">
                    <iframe id="previewFrame" title="Campaign preview" style="width:100%; min-height:360px; border:1px solid #e2e8f0; border-radius:12px; background:#fff;"></iframe>
                    <div class="small text-muted mt-2">Click “Preview email” to render the branded message.</div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-history me-2 text-primary"></i>Recent campaigns</strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Subject</th>
                                    <th>Audience</th>
                                    <th>Sent</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($campaigns as $campaign)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold small">{{ \Illuminate\Support\Str::limit($campaign->subject, 36) }}</div>
                                            <div class="text-muted" style="font-size:.75rem;">{{ optional($campaign->sent_at)->format('M j, g:ia') ?: '—' }}</div>
                                        </td>
                                        <td class="small">{{ $campaign->audienceLabel() }}</td>
                                        <td class="small">
                                            {{ $campaign->sent_count }}/{{ $campaign->recipients_count }}
                                            @if($campaign->skipped_count)
                                                <span class="text-muted">({{ $campaign->skipped_count }} skip)</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No campaigns sent yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($campaigns->hasPages())
                    <div class="card-footer bg-white">{{ $campaigns->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const audience = document.getElementById('campaignAudience');
    const wrap = document.getElementById('selectedUsersWrap');
    const countEl = document.getElementById('selectedCount');

    function syncAudience() {
        wrap.style.display = audience.value === 'selected' ? '' : 'none';
        updateCount();
    }
    function updateCount() {
        countEl.textContent = document.querySelectorAll('.user-check:checked:not(:disabled)').length;
    }

    audience.addEventListener('change', syncAudience);
    document.querySelectorAll('.user-check').forEach(function (el) {
        el.addEventListener('change', updateCount);
    });
    document.getElementById('selectAllAdv').addEventListener('click', function () {
        document.querySelectorAll('.adv-check').forEach(function (el) { el.checked = true; });
        updateCount();
    });
    document.getElementById('selectAllPub').addEventListener('click', function () {
        document.querySelectorAll('.pub-check:not(:disabled)').forEach(function (el) { el.checked = true; });
        updateCount();
    });
    document.getElementById('clearSelected').addEventListener('click', function () {
        document.querySelectorAll('.user-check').forEach(function (el) { el.checked = false; });
        updateCount();
    });

    document.getElementById('previewBtn').addEventListener('click', async function () {
        const form = document.getElementById('campaignForm');
        const fd = new FormData();
        fd.append('_token', form.querySelector('[name=_token]').value);
        fd.append('subject', document.getElementById('campaignSubject').value || 'Preview');
        fd.append('body_html', document.getElementById('campaignBody').value || '<p>Preview</p>');
        const ctaLabel = form.querySelector('[name=cta_label]').value;
        const ctaUrl = form.querySelector('[name=cta_url]').value;
        if (ctaLabel) fd.append('cta_label', ctaLabel);
        if (ctaUrl) fd.append('cta_url', ctaUrl);

        const res = await fetch(@json(route('admin.campaigns.preview')), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
            body: fd,
        });
        const html = await res.text();
        const frame = document.getElementById('previewFrame');
        frame.srcdoc = html;
    });

    syncAudience();
})();
</script>
@endsection
