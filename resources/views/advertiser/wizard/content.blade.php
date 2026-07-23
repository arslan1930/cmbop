@extends('advertiser.layouts.app')

@section('content')
@php
    $usedIds = [];
    foreach ($cart as $row) {
        $ids = is_array($row['content_submission_ids'] ?? null) ? $row['content_submission_ids'] : [];
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $usedIds[] = (int) $id;
            }
        }
        if ((int) ($row['content_submission_id'] ?? 0) > 0) {
            $usedIds[] = (int) $row['content_submission_id'];
        }
    }
    $usedIds = array_values(array_unique($usedIds));

    $placements = [];
    foreach ($cart as $line) {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $lineIds = is_array($line['content_submission_ids'] ?? null) ? $line['content_submission_ids'] : [];
        for ($i = 0; $i < $qty; $i++) {
            $selectedId = (int) ($lineIds[$i] ?? 0);
            if ($selectedId <= 0 && $i === 0) {
                $selectedId = (int) ($line['content_submission_id'] ?? 0);
            }
            $placements[] = [
                'line' => $line,
                'copy_index' => $i,
                'selected_id' => $selectedId,
                'qty' => $qty,
            ];
        }
    }
@endphp
<div class="container-fluid">
    <div class="wizard-chrome">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1>Place a guest post</h1>
                <p class="muted">Step 3 — Assign an approved article to each placement. One article can be published on one site only — upload extras in Content Library.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('advertiser.wizard.publishers') }}" class="btn btn-sm btn-outline-secondary">Back to publishers</a>
                <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-sm btn-upload">Open Content Library</a>
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
                @foreach($placements as $placement)
                    @php
                        $line = $placement['line'];
                        $selectedId = $placement['selected_id'];
                        $copyIndex = $placement['copy_index'];
                        $options = $approvedArticles->filter(function ($article) use ($usedIds, $selectedId) {
                            $id = (int) $article->id;
                            if ($id !== $selectedId && in_array($id, $usedIds, true)) {
                                return false;
                            }
                            return true;
                        });
                        $placementLabel = $placement['qty'] > 1
                            ? ($line['name'] ?? ('Site #'.$line['id'])).' — placement '.($copyIndex + 1).' of '.$placement['qty']
                            : ($line['name'] ?? ('Site #'.$line['id']));
                    @endphp
                    <div class="card border-0 shadow-sm wizard-line"
                         data-site-id="{{ $line['id'] }}"
                         data-sensitive-type="{{ $line['sensitive_type'] ?? '' }}"
                         data-copy-index="{{ $copyIndex }}">
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                                <div>
                                    <div class="fw-semibold">{{ $placementLabel }}</div>
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
            <div class="upload-zone mb-3">
                <h6 class="upload-zone-title">Upload article</h6>
                <p class="upload-zone-copy">Need a new .docx? Upload in Content Library (language first), wait for approval, then assign it here.</p>
                <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-upload w-100">
                    <i class="fa fa-upload me-1"></i> Upload in Content Library
                </a>
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
                            Every placement has an article. Continue to pay.
                        @else
                            Assign an approved article to each placement to continue.
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

    function lineFullyAssigned(row) {
        const qty = Math.max(1, parseInt(row.quantity, 10) || 1);
        const raw = Array.isArray(row.content_submission_ids) ? row.content_submission_ids : [];
        for (let i = 0; i < qty; i++) {
            const id = parseInt(raw[i] || (i === 0 ? row.content_submission_id : 0) || 0, 10) || 0;
            if (!id) return false;
        }
        return true;
    }

    function setReady(ready) {
        if (!payBtn) return;
        if (ready) {
            payBtn.classList.remove('disabled');
            payBtn.removeAttribute('aria-disabled');
            payBtn.removeAttribute('tabindex');
            if (readyNote) readyNote.textContent = 'Every placement has an article. Continue to pay.';
        } else {
            payBtn.classList.add('disabled');
            payBtn.setAttribute('aria-disabled', 'true');
            payBtn.setAttribute('tabindex', '-1');
            if (readyNote) readyNote.textContent = 'Assign an approved article to each placement to continue.';
        }
    }

    document.querySelectorAll('.wizard-article-select').forEach((select) => {
        select.addEventListener('change', async function () {
            const card = this.closest('.wizard-line');
            const feedback = card.querySelector('.line-feedback');
            const status = card.querySelector('.line-status');
            const siteId = card.getAttribute('data-site-id');
            const sensitiveType = card.getAttribute('data-sensitive-type') || '';
            const copyIndex = parseInt(card.getAttribute('data-copy-index') || '0', 10) || 0;
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
                        copy_index: copyIndex,
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
                const ready = cart.length > 0 && cart.every(lineFullyAssigned);
                setReady(ready);
                // Reload so option availability stays unique across placements.
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
