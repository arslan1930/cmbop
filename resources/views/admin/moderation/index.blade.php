@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Content Moderation</h1>
            <p class="text-muted mb-0">Policy settings, prohibited categories, and article scan logs.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Scans</div><h3 class="mb-0">{{ number_format($stats['total']) }}</h3></div></div></div>
        <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Approved</div><h3 class="mb-0 text-success">{{ number_format($stats['approved']) }}</h3></div></div></div>
        <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Rejected</div><h3 class="mb-0 text-danger">{{ number_format($stats['rejected']) }}</h3></div></div></div>
        <div class="col-6 col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Today</div><h3 class="mb-0">{{ number_format($stats['today']) }}</h3></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Moderation Settings</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.moderation.settings') }}">
                        @csrf
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="modEnabled" @checked($cfg['enabled'] ?? true)>
                            <label class="form-check-label" for="modEnabled">Enable content moderation</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confidence threshold ({{ $cfg['confidence_threshold'] ?? 70 }}%)</label>
                            <input type="number" name="confidence_threshold" class="form-control" min="1" max="99" value="{{ old('confidence_threshold', $cfg['confidence_threshold'] ?? 70) }}" required>
                            <div class="form-text">Reject when a restricted category score meets or exceeds this value.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Minimum recommended word count</label>
                            <input type="number" name="min_word_count" class="form-control" min="0" max="5000" value="{{ old('min_word_count', $cfg['quality']['min_word_count'] ?? 500) }}">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="block_on_quality_failure" value="1" id="blockQuality"
                                @checked($cfg['quality']['block_on_quality_failure'] ?? false)>
                            <label class="form-check-label" for="blockQuality">Block orders on quality failures (word count / placeholders)</label>
                        </div>

                        <hr class="my-3">
                        <h6 class="fw-semibold">Content Upload</h6>
                        <div class="mb-3">
                            <label class="form-label">Allowed file types</label>
                            <input type="text" name="allowed_extensions" class="form-control" value="docx" readonly>
                            <div class="form-text">Microsoft Word (.docx) only. Format guidance is shown to advertisers before upload.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Minimum uniqueness for approval (%)</label>
                            <input type="number" name="min_uniqueness" class="form-control" min="0" max="100"
                                   value="{{ old('min_uniqueness', $uploadCfg['evaluation']['min_uniqueness'] ?? 50) }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max upload size (KB)</label>
                            <input type="number" name="max_kilobytes" class="form-control" min="100" max="51200"
                                   value="{{ old('max_kilobytes', $uploadCfg['max_kilobytes'] ?? 5120) }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document retention (months)</label>
                            <input type="number" name="retention_months" class="form-control" min="1" max="24"
                                   value="{{ old('retention_months', $uploadCfg['retention_months'] ?? 6) }}">
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="scheduling_enabled" value="1" id="schedEnabled"
                                @checked($uploadCfg['scheduling']['enabled'] ?? true)>
                            <label class="form-check-label" for="schedEnabled">Enable publication scheduling</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Active prohibited categories</label>
                            <div class="border rounded-3 p-3" style="max-height:220px;overflow:auto;">
                                @foreach(config('content_moderation.categories', []) as $key => $cat)
                                    @php
                                        $isOn = !in_array($key, $disabledCategories, true)
                                            && (($cat['enabled'] ?? false) || in_array($key, $enabledCategories, true));
                                        // If never overridden, use config default
                                        if ($disabledCategories === [] && $enabledCategories === []) {
                                            $isOn = (bool) ($cat['enabled'] ?? false);
                                        }
                                    @endphp
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="categories[]" value="{{ $key }}" id="cat_{{ $key }}" @checked($isOn)>
                                        <label class="form-check-label" for="cat_{{ $key }}">{{ $cat['label'] }} <span class="text-muted small">({{ $key }})</span></label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Extra prohibited keywords (one per line)</label>
                            <textarea name="extra_keywords" class="form-control" rows="4" placeholder="keyword or phrase">{{ implode("\n", $extraKeywords) }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Allowed exceptions (one per line)</label>
                            <textarea name="exceptions" class="form-control" rows="3" placeholder="phrases to ignore">{{ collect($exceptions)->map(fn($e) => is_string($e) ? $e : '')->filter()->implode("\n") }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">Save settings</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Moderation Logs</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>User</th>
                                    <th>Result</th>
                                    <th>Confidence</th>
                                    <th>Category</th>
                                    <th>Words</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($logs as $log)
                                    <tr>
                                        <td class="small text-muted">{{ $log->created_at?->format('M j, g:ia') }}</td>
                                        <td class="small">{{ $log->user?->email ?? '—' }}</td>
                                        <td>
                                            @if($log->status === 'approved')
                                                <span class="badge bg-success">Approved</span>
                                            @elseif($log->status === 'rejected')
                                                <span class="badge bg-danger">Rejected</span>
                                            @else
                                                <span class="badge bg-secondary">Error</span>
                                            @endif
                                            @if($log->admin_override)
                                                <span class="badge bg-warning text-dark">Override</span>
                                            @endif
                                        </td>
                                        <td>{{ $log->max_confidence }}%</td>
                                        <td class="small">{{ $log->detected_category ?: '—' }}</td>
                                        <td>{{ $log->word_count }}</td>
                                        <td class="text-end">
                                            @if(!$log->passed && $log->status === 'rejected')
                                                <form method="POST" action="{{ route('admin.moderation.override', $log) }}" class="d-inline"
                                                      data-slb-confirm="Approve this submission via admin override?"
                                                      data-slb-confirm-title="Override moderation?"
                                                      data-slb-confirm-text="Approve override"
                                                      data-slb-confirm-icon="warning">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-primary" type="submit">Override</button>
                                                </form>
                                            @endif
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ $log->document_url }}" target="_blank" rel="noopener">Doc</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-4">No scans yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($logs->hasPages())
                    <div class="card-footer bg-white">{{ $logs->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
