@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Content Moderation</h1>
            <p class="text-muted mb-0">Policy settings, prohibited categories, and article scan logs.</p>
        </div>
        <a href="{{ route('admin.content-library.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="fa fa-folder-open me-1" aria-hidden="true"></i> Browse articles
        </a>
    </div>

    @php
        $moderationOn = (bool) ($cfg['enabled'] ?? true);
        $offCategories = collect($activeCategories ?? $cfg['categories'] ?? [])
            ->reject(fn ($cat) => (bool) ($cat['enabled'] ?? false))
            ->map(fn ($cat, $key) => $cat['label'] ?? $key)
            ->values();
        $extraKeywordsText = is_array($extraKeywords)
            ? collect($extraKeywords)->filter(fn ($e) => is_string($e))->implode("\n")
            : '';
        $exceptionsText = collect($exceptions ?? [])->map(fn ($e) => is_string($e) ? $e : '')->filter()->implode("\n");
        $oldCategories = old('categories');
        $policyErrors = $errors->getBag('policy');
        $uploadErrors = $errors->getBag('upload');
    @endphp

    @if(! $moderationOn)
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="fa fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                <strong>Content moderation is switched off.</strong>
                No article is being scanned — casino, adult and every other restricted
                category will pass straight through to checkout. Turn it back on below.
            </div>
        </div>
    @elseif($offCategories->isNotEmpty())
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="fa fa-triangle-exclamation mt-1" aria-hidden="true"></i>
            <div>
                <strong>{{ $offCategories->count() }} {{ $offCategories->count() === 1 ? 'category is' : 'categories are' }} not being checked:</strong>
                {{ $offCategories->implode(', ') }}.
                Articles in {{ $offCategories->count() === 1 ? 'that category' : 'those categories' }} will not be flagged.
            </div>
        </div>
    @endif

    @php
        $today = now()->toDateString();
        $kpiTiles = [
            ['label' => 'Needs decision', 'key' => 'needs', 'query' => ['status' => 'needs'], 'class' => 'text-warning'],
            ['label' => 'Approved', 'key' => 'approved', 'query' => ['status' => 'approved'], 'class' => 'text-success'],
            ['label' => 'Rejected', 'key' => 'rejected', 'query' => ['status' => 'rejected'], 'class' => 'text-danger'],
            ['label' => 'Errors', 'key' => 'errors', 'query' => ['status' => 'error'], 'class' => 'text-warning'],
            ['label' => 'Overridden', 'key' => 'overridden', 'query' => ['status' => 'overridden'], 'class' => ''],
            ['label' => 'Today', 'key' => 'today', 'query' => ['from' => $today, 'to' => $today], 'class' => ''],
        ];
    @endphp
    <div class="row g-3 mb-4">
        @foreach($kpiTiles as $tile)
            <div class="col-6 col-xl-2">
                <a href="{{ route('admin.moderation.index', $tile['query']) }}" class="text-decoration-none">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small">{{ $tile['label'] }}</div>
                        <h3 class="mb-0 {{ $tile['class'] }}">{{ number_format($stats[$tile['key']] ?? 0) }}</h3>
                    </div></div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Moderation Settings</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.moderation.settings') }}" id="moderation-settings-form"
                          data-was-enabled="{{ $moderationOn ? '1' : '0' }}">
                        @csrf
                        <h6 class="fw-semibold">Content policy</h6>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="enabled" value="1" id="modEnabled" @checked($policyErrors->isNotEmpty() ? old('enabled') : ($cfg['enabled'] ?? true))>
                            <label class="form-check-label" for="modEnabled">Enable content moderation</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confidence threshold ({{ $cfg['confidence_threshold'] ?? 70 }}%)</label>
                            <input type="number" name="confidence_threshold" class="form-control" min="1" max="99" value="{{ old_text('confidence_threshold', $cfg['confidence_threshold'] ?? 70) }}" required>
                            @error('confidence_threshold', 'policy')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Reject when a restricted category score meets or exceeds this value.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Minimum recommended word count</label>
                            <input type="number" name="min_word_count" class="form-control" min="0" max="5000" value="{{ old_text('min_word_count', $cfg['quality']['min_word_count'] ?? 500) }}">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="block_on_quality_failure" value="1" id="blockQuality"
                                @checked($policyErrors->isNotEmpty() ? old('block_on_quality_failure') : ($cfg['quality']['block_on_quality_failure'] ?? true))>
                            <label class="form-check-label" for="blockQuality">Block orders on too many outbound links or placeholder text</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Active prohibited categories</label>
                            <div class="border rounded-3 p-3" style="max-height:220px;overflow:auto;">
                                @foreach(config('content_moderation.categories', []) as $key => $cat)
                                    @php
                                        if (is_array($oldCategories)) {
                                            $isOn = in_array($key, $oldCategories, true);
                                        } else {
                                            $isOn = !in_array($key, $disabledCategories, true)
                                                && (($cat['enabled'] ?? false) || in_array($key, $enabledCategories, true));
                                            if ($disabledCategories === [] && $enabledCategories === []) {
                                                $isOn = (bool) ($cat['enabled'] ?? false);
                                            }
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
                            <textarea name="extra_keywords" class="form-control" rows="4" placeholder="keyword or phrase">{{ old_text('extra_keywords', $extraKeywordsText) }}</textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Allowed exceptions (one per line)</label>
                            <textarea name="exceptions" class="form-control" rows="3" placeholder="phrases to ignore">{{ old_text('exceptions', $exceptionsText) }}</textarea>
                            @if(($builtinExceptions ?? []) !== [])
                                <div class="form-text">
                                    Already ignored by default:
                                    {{ implode(', ', $builtinExceptions) }}.
                                </div>
                            @endif
                        </div>

                        <button type="submit" class="btn btn-primary">Save policy</button>
                    </form>

                    <hr class="my-4">
                    <form method="POST" action="{{ route('admin.moderation.upload-settings') }}" id="moderation-upload-form">
                        @csrf
                        <h6 class="fw-semibold">Upload / placement</h6>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="uploads_enabled" value="1" id="uploadsEnabled"
                                @checked($uploadErrors->isNotEmpty() ? old('uploads_enabled') : ($uploadCfg['enabled'] ?? true))>
                            <label class="form-check-label" for="uploadsEnabled">Allow new article uploads</label>
                            <div class="form-text">Kill-switch — advertisers can still browse and order existing approved articles when off.</div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="require_same_language" value="1" id="requireSameLanguage"
                                @checked($uploadErrors->isNotEmpty() ? old('require_same_language') : ($uploadCfg['placement']['require_same_language'] ?? false))>
                            <label class="form-check-label" for="requireSameLanguage">Require same language for placement</label>
                            <div class="form-text">Off (default): soft-prefer matching languages and warn in cart. On: hard-block mismatches.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Allowed file types</label>
                            <input type="text" name="allowed_extensions" class="form-control" value="docx" readonly>
                            <div class="form-text">Microsoft Word (.docx) only. Format guidance is shown to advertisers before upload.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Advisory uniqueness threshold (%)</label>
                            <input type="number" name="min_uniqueness" class="form-control" min="0" max="100"
                                   value="{{ old_text('min_uniqueness', $uploadCfg['evaluation']['min_uniqueness'] ?? 50) }}">
                            <div class="form-text">Warns in the evaluation report only — does not block approval or ordering.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Max upload size (KB)</label>
                            <input type="number" name="max_kilobytes" class="form-control" min="10240" max="10240"
                                   value="10240" readonly>
                            <div class="form-text">Fixed at 10 MB (10240 KB). Files up to 10 MB upload; anything larger is rejected. Admin cannot raise this limit.</div>
                            @if($phpBlocksArticleUploads ?? false)
                                <div class="alert alert-warning py-2 px-3 small mt-2 mb-0" role="status">
                                    PHP still allows only {{ max(1, (int) round(($phpUploadMaxKb ?? 0) / 1024)) }} MB
                                    (<code>upload_max_filesize</code> / <code>post_max_size</code>),
                                    so a 5 MB .docx is rejected even though the article cap is
                                    {{ max(1, (int) round(($articleUploadMaxKb ?? 10240) / 1024)) }} MB.
                                    In Hostinger hPanel → Advanced → PHP Configuration set
                                    <code>upload_max_filesize</code> to 64M and <code>post_max_size</code> to 64M,
                                    then wait a minute. <code>public/.user.ini</code> already asks for those values;
                                    Hostinger often ignores them until they are set in hPanel.
                                </div>
                            @endif
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document retention (months)</label>
                            <input type="number" name="retention_months" class="form-control" min="1" max="24"
                                   value="{{ old_text('retention_months', $uploadCfg['retention_months'] ?? 6) }}">
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="scheduling_enabled" value="1" id="schedEnabled"
                                @checked($uploadErrors->isNotEmpty() ? old('scheduling_enabled') : ($uploadCfg['scheduling']['enabled'] ?? true))>
                            <label class="form-check-label" for="schedEnabled">Enable publication scheduling</label>
                        </div>

                        <button type="submit" class="btn btn-primary">Save upload settings</button>
                    </form>

                    <hr class="my-4">
                    <h6 class="fw-semibold">Test scan</h6>
                    <p class="small text-muted">Scores text or a public URL against the saved policy. Nothing is stored and checkout is not touched.</p>
                    <form method="POST" action="{{ route('admin.moderation.test-scan') }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label" for="moderationTestUrl">Public URL</label>
                            <input type="text" name="url" id="moderationTestUrl" class="form-control" value="{{ old_text('url') }}" maxlength="2000" placeholder="https://">
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="moderationTestText">Or paste text</label>
                            <textarea name="text" id="moderationTestText" class="form-control" rows="4" maxlength="200000" placeholder="Article body">{{ old_text('text') }}</textarea>
                            @error('text')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">Run test scan</button>
                    </form>
                    @if(session('moderation_test'))
                        @php $preview = session('moderation_test'); @endphp
                        <div class="alert {{ ($preview['passed'] ?? false) ? 'alert-success' : 'alert-warning' }} small mt-3 mb-0" role="status">
                            <strong>{{ $preview['status'] ?? 'error' }}</strong>
                            — {{ $preview['message'] ?? '' }}
                            @if(! empty($preview['word_count']))
                                · {{ $preview['word_count'] }} words
                            @endif
                            @if(! empty($preview['max_confidence']))
                                · {{ $preview['max_confidence'] }}%
                            @endif
                            @if(! empty($preview['matched_terms']))
                                <div class="mt-1">Matched: {{ implode(', ', $preview['matched_terms']) }}</div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm admin-deposits-filter-card">
                <div class="card-header bg-white border-0">
                    <strong>Moderation Logs</strong>
                </div>
                <div class="card-body border-bottom py-3">
                    <form method="GET" action="{{ route('admin.moderation.index') }}" class="admin-deposits-filters admin-orders-filters" data-admin-filter-live="1">
                        <div class="admin-orders-filters__grid">
                        <div class="admin-orders-filters__search">
                            <x-slb-search-field name="q" id="adminModerationSearch" :value="$search ?? ''" placeholder="Title, email, upload id, URL" input-class="form-control" label-class="form-label" />
                        </div>
                        <div>
                            <label class="form-label" for="adminModerationStatus">Status</label>
                            <select name="status" id="adminModerationStatus" class="form-select">
                                <option value="all" @selected(($status ?? 'all') === 'all')>All ({{ (int) ($stats['total'] ?? 0) }})</option>
                                <option value="needs" @selected(($status ?? '') === 'needs')>Needs decision ({{ (int) ($stats['needs'] ?? 0) }})</option>
                                <option value="approved" @selected(($status ?? '') === 'approved')>Approved ({{ (int) ($stats['approved'] ?? 0) }})</option>
                                <option value="rejected" @selected(($status ?? '') === 'rejected')>Rejected ({{ (int) ($stats['rejected'] ?? 0) }})</option>
                                <option value="error" @selected(($status ?? '') === 'error')>Errors ({{ (int) ($stats['errors'] ?? 0) }})</option>
                                <option value="skipped" @selected(($status ?? '') === 'skipped')>Not checked ({{ (int) ($stats['skipped'] ?? 0) }})</option>
                                <option value="overridden" @selected(($status ?? '') === 'overridden')>Overridden ({{ (int) ($stats['overridden'] ?? 0) }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="adminModerationCategory">Category</label>
                            <select name="category" id="adminModerationCategory" class="form-select">
                                <option value="all" @selected(($category ?? 'all') === 'all')>All</option>
                                @foreach(config('content_moderation.categories', []) as $key => $cat)
                                    <option value="{{ $key }}" @selected(($category ?? '') === $key)>{{ $cat['label'] ?? $key }}</option>
                                @endforeach
                                <option value="custom" @selected(($category ?? '') === 'custom')>Extra prohibited keywords</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="adminModerationFrom">From</label>
                            <input type="date" id="adminModerationFrom" name="from" class="form-control" value="{{ $from ?? '' }}">
                        </div>
                        <div>
                            <label class="form-label" for="adminModerationTo">To</label>
                            <input type="date" id="adminModerationTo" name="to" class="form-control" value="{{ $to ?? '' }}">
                        </div>
                        <div class="admin-deposits-filters__actions admin-orders-filters__actions">
                            <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">Apply</button>
                            <a href="{{ route('admin.moderation.index') }}" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </div>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>Article</th>
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
                                        <td class="small">
                                            <div class="fw-semibold">{{ $log->displayTitle() }}</div>
                                        </td>
                                        <td class="small">
                                            @if($log->user)
                                                <a href="{{ route('admin.users.show', $log->user) }}">{{ $log->user->email }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if($log->wasSkipped())
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fa fa-triangle-exclamation me-1" aria-hidden="true"></i>Not checked
                                                </span>
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
                                        </td>
                                        <td>
                                            @if($log->status === 'error')
                                                <span class="small text-muted">{{ $log->error_code ?: 'error' }}</span>
                                            @else
                                                {{ $log->max_confidence }}%
                                            @endif
                                        </td>
                                        <td class="small">{{ $log->categoryLabel() }}</td>
                                        <td>{{ $log->word_count }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.moderation.show', $log) }}">View</a>
                                            @if($log->articleUrl())
                                                <a class="btn btn-sm btn-outline-secondary"
                                                   href="{{ $log->articleUrl() }}"
                                                   @if($log->articleUrlIsExternal()) target="_blank" rel="noopener" @endif>
                                                    {{ $log->articleUrlIsExternal() ? 'Doc' : 'Article' }}
                                                </a>
                                            @endif
                                            @if($log->admin_override && $log->submission && (int) $log->submission->moderation_log_id === (int) $log->id)
                                                <form method="POST" action="{{ route('admin.moderation.revert', $log) }}" class="d-inline"
                                                      data-slb-confirm="Re-check this article and drop the override?"
                                                      data-slb-confirm-title="Revert override?"
                                                      data-slb-confirm-text="Revert"
                                                      data-slb-confirm-icon="warning">
                                                    @csrf
                                                    <button class="btn btn-sm btn-outline-danger" type="submit">Revert</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted py-4">No scans match these filters.</td></tr>
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

@push('scripts')
<script>
(function () {
    var form = document.getElementById('moderation-settings-form');
    if (!form || typeof slbConfirm !== 'function') return;
    form.addEventListener('submit', function (e) {
        if (form.dataset.slbAllowSubmit === '1') return;
        var enabled = !!(document.getElementById('modEnabled') && document.getElementById('modEnabled').checked);
        var cats = form.querySelectorAll('input[name="categories[]"]:checked');
        var wasEnabled = form.dataset.wasEnabled === '1';
        var turningOff = wasEnabled && !enabled;
        var noneOn = cats.length === 0;
        if (!turningOff && !noneOn) return;
        e.preventDefault();
        slbConfirm({
            title: turningOff ? 'Turn off content moderation?' : 'Disable every category?',
            text: turningOff
                ? 'No article will be scanned. Casino, adult and every other restricted category will pass through to checkout.'
                : 'Every prohibited category is unticked. Restricted articles will not be flagged.',
            confirmText: 'Save anyway',
            danger: true,
            icon: 'warning'
        }).then(function (ok) {
            if (!ok) return;
            form.dataset.slbAllowSubmit = '1';
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        });
    });
})();
</script>
@endpush
