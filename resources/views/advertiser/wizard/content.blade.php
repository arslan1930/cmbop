@extends('advertiser.layouts.app')

@section('content')
@php
    $usedIds = collect($cart)->pluck('content_submission_id')->filter()->map(fn ($id) => (int) $id)->all();
@endphp
<div class="container-fluid">
    <div class="wizard-chrome">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1>Place a guest post</h1>
                <p class="muted">Step 3 — Assign an approved article to each website (or upload one). Each site needs its own article.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('advertiser.wizard.publishers') }}" class="btn btn-sm btn-outline-secondary">Back to publishers</a>
                <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-sm btn-outline-primary">Open Content Library</a>
                <form method="POST" action="{{ route('advertiser.wizard.exit') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-link text-muted">Exit guided flow</button>
                </form>
            </div>
        </div>
    </div>

    @include('advertiser.wizard._stepper', ['step' => 3])

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex flex-column gap-3" id="wizardContentLines">
                @foreach($cart as $index => $line)
                    @php
                        $siteLang = strtolower((string) ($line['language'] ?? ''));
                        $selectedId = (int) ($line['content_submission_id'] ?? 0);
                        $options = $approvedArticles->filter(function ($article) use ($usedIds, $selectedId) {
                            $id = (int) $article->id;
                            if ($id !== $selectedId && in_array($id, $usedIds, true)) {
                                return false;
                            }
                            return true;
                        });
                    @endphp
                    <div class="card border-0 shadow-sm wizard-line"
                         data-site-id="{{ $line['id'] }}"
                         data-sensitive-type="{{ $line['sensitive_type'] ?? '' }}">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                                <div>
                                    <div class="fw-semibold">{{ $line['name'] ?? ('Site #'.$line['id']) }}</div>
                                    <div class="small text-muted">
                                        {{ strtoupper((string) ($line['language'] ?? '—')) }}
                                        @if(!empty($line['country']))
                                            · preferred {{ strtoupper($line['country']) }}
                                        @endif
                                        · €{{ number_format((float) ($line['price'] ?? 0), 2) }}
                                    </div>
                                </div>
                                <span class="badge {{ $selectedId ? 'text-bg-success' : 'text-bg-secondary' }} line-status">
                                    {{ $selectedId ? 'Article assigned' : 'Needs article' }}
                                </span>
                            </div>

                            <label class="form-label small">Approved article</label>
                            <select class="form-select form-select-sm wizard-article-select mb-2">
                                <option value="">— Select approved article —</option>
                                @forelse($options as $article)
                                    <option value="{{ $article->id }}" @selected($selectedId === (int) $article->id)>
                                        {{ $article->title ?: $article->original_filename }}
                                        ({{ strtoupper($article->language) }}{{ $article->country ? '/'.strtoupper($article->country) : '' }})
                                    </option>
                                @empty
                                    <option value="" disabled>No approved articles yet</option>
                                @endforelse
                            </select>
                            <div class="small text-muted line-feedback" aria-live="polite"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-2">Upload article</h6>
                    <p class="small text-muted mb-3">Need a new .docx? Upload in Content Library (language first), wait for approval, then assign it here.</p>
                    <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-outline-primary w-100">
                        <i class="fa fa-upload me-1"></i> Upload in Content Library
                    </a>
                </div>
            </div>
            <div class="card border-0 shadow-sm mb-3" style="opacity:.85;">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">Write for me</h6>
                    <p class="small text-muted mb-2">Publisher or platform writing — coming later.</p>
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100" disabled title="Coming later">
                        Coming later
                    </button>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="small text-muted mb-3" id="wizardReadyNote">
                        @if($cartReady)
                            Every website has an article. Continue to pay.
                        @else
                            Assign an approved article to each website to continue.
                        @endif
                    </p>
                    <a href="{{ route('advertiser.wizard.pay') }}"
                       id="wizardContinuePay"
                       class="btn btn-primary w-100 {{ $cartReady ? '' : 'disabled' }}"
                       @if(!$cartReady) aria-disabled="true" tabindex="-1" @endif>
                        Continue to pay <i class="fa fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const assignUrl = @json(route('advertiser.cart.assign-article'));
    const csrf = @json(csrf_token());
    const payBtn = document.getElementById('wizardContinuePay');
    const readyNote = document.getElementById('wizardReadyNote');

    function setReady(ready) {
        if (!payBtn) return;
        if (ready) {
            payBtn.classList.remove('disabled');
            payBtn.removeAttribute('aria-disabled');
            payBtn.removeAttribute('tabindex');
            if (readyNote) readyNote.textContent = 'Every website has an article. Continue to pay.';
        } else {
            payBtn.classList.add('disabled');
            payBtn.setAttribute('aria-disabled', 'true');
            payBtn.setAttribute('tabindex', '-1');
            if (readyNote) readyNote.textContent = 'Assign an approved article to each website to continue.';
        }
    }

    document.querySelectorAll('.wizard-article-select').forEach((select) => {
        select.addEventListener('change', async function () {
            const card = this.closest('.wizard-line');
            const feedback = card.querySelector('.line-feedback');
            const status = card.querySelector('.line-status');
            const siteId = card.getAttribute('data-site-id');
            const sensitiveType = card.getAttribute('data-sensitive-type') || '';
            const submissionId = this.value || '';

            feedback.textContent = 'Saving…';
            try {
                const res = await fetch(assignUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: parseInt(siteId, 10),
                        sensitive_type: sensitiveType,
                        content_submission_id: submissionId ? parseInt(submissionId, 10) : null,
                    }),
                });
                const data = await res.json();
                if (!data.success) {
                    feedback.innerHTML = '<span class="text-danger">' + (data.error || data.message || 'Could not assign') + '</span>';
                    return;
                }
                feedback.innerHTML = '<span class="text-success">' + (data.message || 'Saved') + '</span>';
                if (submissionId) {
                    status.textContent = 'Article assigned';
                    status.className = 'badge text-bg-success line-status';
                } else {
                    status.textContent = 'Needs article';
                    status.className = 'badge text-bg-secondary line-status';
                }
                const cart = Array.isArray(data.cart) ? data.cart : [];
                const ready = cart.length > 0 && cart.every((row) => parseInt(row.content_submission_id || 0, 10) > 0);
                setReady(ready);
                // Reload so option availability stays unique across lines.
                if (ready || submissionId) {
                    setTimeout(() => window.location.reload(), 400);
                }
            } catch (e) {
                feedback.innerHTML = '<span class="text-danger">Network error</span>';
            }
        });
    });
})();
</script>
@endsection
