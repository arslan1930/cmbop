@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <a href="{{ $listUrl ?? route('admin.moderation.index') }}" class="small text-muted text-decoration-none">← Moderation</a>
            <h1 class="h3 mb-1 mt-1">Scan #{{ $log->id }}</h1>
            <p class="text-muted mb-0 small">
                {{ $log->created_at?->toDayDateTimeString() }}
                ·
                @if($log->user)
                    <a href="{{ route('admin.users.show', $log->user) }}">{{ $log->user->email }}</a>
                @else
                    —
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($log->articleUrl())
                <a class="btn btn-sm btn-outline-secondary"
                   href="{{ $log->articleUrl() }}"
                   @if($log->articleUrlIsExternal()) target="_blank" rel="noopener" @endif>
                    {{ $log->articleUrlIsExternal() ? 'Open document' : 'Open article' }}
                </a>
            @endif
            @if($log->status === 'error')
                <form method="POST" action="{{ route('admin.moderation.rescan', $log) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary" type="submit">Re-scan</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Decision</strong></div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Result</dt>
                        <dd class="col-7">
                            @if($log->wasSkipped())
                                <span class="badge bg-warning text-dark">Not checked</span>
                            @elseif($log->status === 'approved')
                                <span class="badge bg-success">Approved</span>
                            @elseif($log->status === 'rejected')
                                <span class="badge bg-danger">Rejected</span>
                            @else
                                <span class="badge bg-secondary">Error</span>
                            @endif
                            @if($log->admin_override)
                                <span class="badge bg-warning text-dark">Override</span>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Category</dt>
                        <dd class="col-7">{{ $log->categoryLabel() }}</dd>
                        <dt class="col-5 text-muted">Confidence</dt>
                        <dd class="col-7">{{ $log->status === 'error' ? ($log->error_code ?: 'error') : $log->max_confidence.'%' }}</dd>
                        <dt class="col-5 text-muted">Words</dt>
                        <dd class="col-7">{{ $log->word_count ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Source</dt>
                        <dd class="col-7 text-break">{{ $log->document_url }}</dd>
                        @if($log->error_message)
                            <dt class="col-5 text-muted">Error</dt>
                            <dd class="col-7">{{ $log->error_message }}</dd>
                        @endif
                        @if($log->admin_override)
                            <dt class="col-5 text-muted">Overridden by</dt>
                            <dd class="col-7">{{ $log->overrider?->email ?? '—' }}<br><span class="text-muted">{{ optional($log->overridden_at)->toDayDateTimeString() }}</span></dd>
                            <dt class="col-5 text-muted">Notes</dt>
                            <dd class="col-7">{{ $log->admin_notes ?: '—' }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if($submission)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>Article</strong></div>
                    <div class="card-body small">
                        <div class="fw-semibold">{{ $submission->title ?: $submission->original_filename }}</div>
                        <div class="text-muted mb-2">
                            #{{ $submission->id }}
                            ·
                            @if($submission->user)
                                <a href="{{ route('admin.users.show', $submission->user) }}">{{ $submission->user->email }}</a>
                            @endif
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.content-library.show', $submission) }}">Open in Content Library</a>
                    </div>
                </div>
            @endif

            @if($log->isOverridable($submission))
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white"><strong>Override</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.moderation.override', $log) }}"
                              data-slb-confirm="Approve this submission via admin override?"
                              data-slb-confirm-title="Override moderation?"
                              data-slb-confirm-text="Approve override"
                              data-slb-confirm-icon="warning">
                            @csrf
                            <label class="form-label small" for="overrideNotes">Reason</label>
                            <textarea name="notes" id="overrideNotes" class="form-control mb-2" rows="3" required minlength="3" maxlength="2000" placeholder="Why this article is allowed">{{ old_text('notes') }}</textarea>
                            <button class="btn btn-primary btn-sm" type="submit">Approve override</button>
                        </form>
                    </div>
                </div>
            @endif

            @if($log->admin_override && $submission && (int) $submission->moderation_log_id === (int) $log->id)
                <form method="POST" action="{{ route('admin.moderation.revert', $log) }}"
                      data-slb-confirm="Re-check this article and drop the override?"
                      data-slb-confirm-title="Revert override?"
                      data-slb-confirm-text="Revert"
                      data-slb-confirm-icon="warning">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit">Revert override</button>
                </form>
            @endif
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><strong>Why</strong></div>
                <div class="card-body">
                    @if($log->wasSkipped())
                        <p class="text-muted mb-0">Moderation was switched off. Nothing was scanned.</p>
                    @else
                        @php
                            $terms = $report['matched_terms'] ?? [];
                            $urls = $report['blocked_urls'] ?? [];
                            $checks = $report['checks'] ?? [];
                        @endphp
                        @if($terms !== [])
                            <div class="mb-3">
                                <div class="fw-semibold mb-1">Matched terms</div>
                                <div>{{ implode(', ', $terms) }}</div>
                            </div>
                        @endif
                        @if($urls !== [])
                            <div class="mb-3">
                                <div class="fw-semibold mb-1">Blocked URLs</div>
                                <ul class="mb-0">
                                    @foreach($urls as $url)
                                        <li class="text-break">{{ $url }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if($checks !== [])
                            <div class="fw-semibold mb-1">Checks</div>
                            <ul class="mb-0">
                                @foreach($checks as $check)
                                    <li>
                                        <span class="text-muted">{{ $check['status'] ?? '—' }}</span>
                                        — {{ $check['label'] ?? 'Check' }}
                                        @if(! empty($check['detail']))
                                            <div class="small text-muted">{{ $check['detail'] }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @elseif($terms === [] && $urls === [] && $log->status !== 'error')
                            <p class="text-muted mb-0">No matched terms or blocked URLs were stored on this scan.</p>
                        @endif
                    @endif
                </div>
            </div>

            @if(is_array($log->category_scores) && $log->category_scores !== [])
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><strong>Category scores</strong></div>
                    <div class="card-body small">
                        <ul class="mb-0">
                            @foreach($log->category_scores as $key => $score)
                                <li>{{ $key === 'custom' ? 'Extra prohibited keywords' : (config('content_moderation.categories.'.$key.'.label') ?: $key) }}: {{ $score }}%</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
