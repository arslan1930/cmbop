@extends('publisher.layouts.app')

@section('content')
<style>
    .site-preview-desc-wrap { max-width: 100%; }
    .site-preview-desc {
        white-space: pre-wrap;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .site-preview-desc.is-clamped {
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 5;
        overflow: hidden;
    }
    .site-preview-desc-toggle {
        font-weight: 600;
        text-decoration: none;
    }
    .site-preview-desc-toggle:hover { text-decoration: underline; }
</style>
<div class="container-fluid">
    <div class="mb-3">
        <a href="{{ route('publisher.websites') }}" class="small text-muted text-decoration-none">← Websites</a>
        <h3 class="mt-2 mb-1">Review &amp; submit</h3>
        <p class="text-muted small mb-0">
            Check each site’s details. Edit anything that looks wrong, then submit for admin review.
            Sites stay hidden from the catalog until we approve.
        </p>
    </div>

    @if(($awaitingCount ?? 0) > 0)
        <div class="alert alert-warning border-0 shadow-sm">
            <strong>{{ $awaitingCount }}</strong> site(s) still need details.
            <a href="{{ route('publisher.bulk-sites.complete') }}" class="alert-link">Complete them first</a>
            before submitting everything.
        </div>
    @endif

    @if($sites->isEmpty())
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <h5>Nothing ready to review yet</h5>
                <p class="text-muted mb-3">Save details on your bulk sites first — they’ll show up here for a final check.</p>
                <a href="{{ route('publisher.bulk-sites.complete') }}" class="btn btn-primary">Complete details</a>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('publisher.bulk-sites.review.submit', absolute: false) }}" id="bulkReviewForm">
            @csrf
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="bulkReviewSelectAll" checked>
                    <label class="form-check-label" for="bulkReviewSelectAll">Select all ({{ $sites->count() }})</label>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="submit" name="submit_all" value="1" class="btn btn-outline-primary">
                        Submit all
                    </button>
                    <button type="submit" class="btn btn-primary" id="bulkReviewSubmitSelected">
                        Submit selected
                    </button>
                </div>
            </div>

            @foreach($sites as $site)
                @php
                    $niches = collect($site->nicheBadgeLabels())
                        ->filter(fn ($v) => $v !== '' && strtolower($v) !== 'pending')
                        ->values()
                        ->all();
                    $desc = \App\Support\SiteDescriptionRules::textareaValue((string) $site->description);
                    $tag = $site->tagLabel(\App\Support\SiteTag::NONE_LABEL);
                @endphp
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check mt-1">
                                <input class="form-check-input bulk-review-check" type="checkbox"
                                       name="site_ids[]" value="{{ $site->id }}" id="review-site-{{ $site->id }}" checked>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <label class="fw-semibold mb-0" for="review-site-{{ $site->id }}">{{ $site->site_name }}</label>
                                        <div class="small text-muted">
                                            <a href="{{ $site->site_url }}" target="_blank" rel="noopener">{{ $site->site_url }}</a>
                                            · {{ format_money($site->price) }}
                                        </div>
                                    </div>
                                    <a href="{{ route('publisher.bulk-sites.complete') }}#site-{{ $site->id }}"
                                       class="btn btn-sm btn-outline-secondary align-self-start">
                                        <i class="fa fa-pen me-1"></i> Edit
                                    </a>
                                </div>

                                <div class="row g-2 small">
                                    <div class="col-md-3"><span class="text-muted">DR / DA / Traffic</span><div>{{ $site->dr }} / {{ $site->da }} / {{ number_format((int) $site->traffic) }}</div></div>
                                    <div class="col-md-3"><span class="text-muted">Language / Country</span><div>{{ strtoupper((string) $site->language) }} / {{ strtoupper((string) $site->country) }}</div></div>
                                    <div class="col-md-3"><span class="text-muted">Turnaround</span><div>{{ $site->turnaround_time ?: '—' }}</div></div>
                                    <div class="col-md-3"><span class="text-muted">Publication</span><div>{{ $site->publication_time ?: '—' }}</div></div>
                                    <div class="col-md-3"><span class="text-muted">Link type</span><div>{{ $site->link_type ?: '—' }}</div></div>
                                    <div class="col-md-3"><span class="text-muted">Tag</span><div>{{ $tag }}</div></div>
                                    <div class="col-md-6"><span class="text-muted">Example URL</span>
                                        <div class="text-break">
                                            @if($site->example_url)
                                                <a href="{{ $site->example_url }}" target="_blank" rel="noopener">{{ $site->example_url }}</a>
                                            @else
                                                —
                                            @endif
                                        </div>
                                    </div>
                                    <div class="col-12"><span class="text-muted">Niches</span>
                                        <div>
                                            @forelse($niches as $niche)
                                                <span class="badge text-bg-light border me-1">{{ $niche }}</span>
                                            @empty
                                                <span class="text-danger">Missing</span>
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="col-12"><span class="text-muted">Description</span>
                                        @if($desc !== '')
                                            <div class="mt-1 p-2 rounded bg-light border site-preview-desc-wrap">
                                                <div class="site-preview-desc is-clamped">{{ $desc }}</div>
                                                <button type="button"
                                                        class="btn btn-link btn-sm px-0 mt-1 site-preview-desc-toggle d-none"
                                                        aria-expanded="false">Show more</button>
                                            </div>
                                        @else
                                            <div class="mt-1 p-2 rounded bg-light border">—</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </form>
    @endif
</div>

@if($sites->isNotEmpty())
<script>
(function () {
    const selectAll = document.getElementById('bulkReviewSelectAll');
    const checks = () => Array.from(document.querySelectorAll('.bulk-review-check'));
    const form = document.getElementById('bulkReviewForm');

    selectAll?.addEventListener('change', function () {
        checks().forEach(function (c) { c.checked = selectAll.checked; });
    });

    form?.addEventListener('click', function (e) {
        const btn = e.target.closest('#bulkReviewSubmitSelected');
        if (!btn) return;
        const any = checks().some(function (c) { return c.checked; });
        if (!any) {
            e.preventDefault();
            slbAlert({ icon: 'warning', title: 'Select at least one site', text: 'Pick the sites you want to submit, or use Submit all.' });
        }
    });

    function syncDescToggles(root) {
        (root || document).querySelectorAll('.site-preview-desc-wrap').forEach(function (wrap) {
            const desc = wrap.querySelector('.site-preview-desc');
            const btn = wrap.querySelector('.site-preview-desc-toggle');
            if (!desc || !btn) return;
            desc.classList.add('is-clamped');
            const needsToggle = desc.scrollHeight > desc.clientHeight + 1;
            if (!needsToggle) {
                desc.classList.remove('is-clamped');
                btn.classList.add('d-none');
                return;
            }
            btn.classList.remove('d-none');
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Show more';
        });
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.site-preview-desc-toggle');
        if (!btn || !form.contains(btn)) return;
        e.preventDefault();
        const wrap = btn.closest('.site-preview-desc-wrap');
        const desc = wrap ? wrap.querySelector('.site-preview-desc') : null;
        if (!desc) return;
        const expanded = desc.classList.toggle('is-clamped') === false;
        btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        btn.textContent = expanded ? 'Show less' : 'Show more';
    });

    syncDescToggles(form);
    window.addEventListener('resize', function () { syncDescToggles(form); });
})();
</script>
@endif
@endsection
