@extends('advertiser.layouts.app')

@section('title', 'Assign articles')

@section('content')
@php
    $articleCopy = $articleCopy ?? app(\App\Services\Marketplace\CheckoutAddOnService::class)->cartArticleCopy();
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

    $unreadyLines = 0;
    foreach ($cart as $row) {
        if (\App\Services\Marketplace\CheckoutAddOnService::isWrittenSource($row)) {
            continue;
        }
        $ids = is_array($row['content_submission_ids'] ?? null) ? $row['content_submission_ids'] : [];
        $hasArticle = (int) ($row['content_submission_id'] ?? 0) > 0;
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $hasArticle = true;
                break;
            }
        }
        if (! $hasArticle) {
            $unreadyLines++;
        }
    }

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
    $unreadyCount = 0;
    foreach ($placements as $placement) {
        $line = $placement['line'];
        $written = \App\Services\Marketplace\CheckoutAddOnService::isWrittenSource($line);
        if (! $written && (int) $placement['selected_id'] <= 0) {
            $unreadyCount++;
        }
    }
@endphp
<div class="container-fluid">
    <div class="wizard-chrome">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div>
                <h1>Place a guest post</h1>
                <p class="muted">Step 3 — Choose how you'll get the article for each placement. One uploaded article can be published on one site only.</p>
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

    <div class="row g-4">
        <div class="col-lg-8">
            @if($unreadyCount >= 2)
                <button type="button" class="cart-write-all is-visible mb-3" id="wizardWriteAll">{{ $articleCopy['write_all'] }}</button>
            @endif
            <div class="d-flex flex-column gap-3" id="wizardContentLines">
                @foreach($placements as $placement)
                    @php
                        $line = $placement['line'];
                        $selectedId = $placement['selected_id'];
                        $copyIndex = $placement['copy_index'];
                        $written = \App\Services\Marketplace\CheckoutAddOnService::isWrittenSource($line);
                        $writingFee = (float) ($line['writing_fee'] ?? 0);
                        $withWritingLabel = format_money_pay(((float) ($line['price'] ?? 0)) + $writingFee);
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
                         data-homepage-days="{{ $line['homepage_days'] ?? 'none' }}"
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
                                        · {{ format_money($line['price'] ?? 0) }}
                                    </div>
                                    @unless($written)
                                        <div class="cart-item-with-writing">
                                            {{ $articleCopy['with_writing'] }}
                                            {{ $writingTotalLabel !== '' ? $writingTotalLabel : format_money_pay(($line['price'] ?? 0) + ($line['writing_fee'] ?? 0)) }}
                                        </div>
                                    @endunless
                                </div>
                                <span class="badge {{ ($selectedId || $written) ? 'text-bg-success' : 'text-bg-secondary' }} line-status">
                                    {{ $written ? 'Writing ordered' : ($selectedId ? 'Article assigned' : 'Choose an option') }}
                                </span>
                            </div>

                            <div class="cart-item-order-label mb-2">
                                <span class="cart-item-order-kicker">Choose how you'll get the article</span>
                            </div>
                            <div class="cart-source-choices mb-3" role="radiogroup" aria-label="Choose how you'll get the article">
                                <button type="button" class="cart-source-card wizard-write-cta{{ $written ? ' is-selected' : '' }}" aria-pressed="{{ $written ? 'true' : 'false' }}">
                                    <span class="cart-source-card__title">Have the article written</span>
                                    <span class="cart-source-card__price">With writing: {{ $withWritingLabel }}</span>
                                    <span class="cart-source-card__explainer">{{ $writingExplainer }}</span>
                                    <span class="cart-source-card__turnaround">Typical turnaround: {{ $writingTurnaroundText }}</span>
                                </button>
                                <button type="button" class="cart-source-card wizard-upload-cta{{ $selectedId ? ' is-selected' : '' }}" aria-pressed="{{ $selectedId ? 'true' : 'false' }}">
                                    <span class="cart-source-card__title">Upload article</span>
                                    <span class="cart-source-card__price">Use your own approved article</span>
                                </button>
                            </div>

                            <label class="form-label small wizard-article-label">Approved article</label>
                            <select class="form-select form-select-sm wizard-article-select mb-2" @disabled($written)>
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
                            <div class="small text-muted line-feedback" aria-live="polite">
                                @if($written)
                                    Writing selected. Target URL and anchor can be edited at checkout.
                                @endif
                            </div>
                            @if(!empty($line['guarantee_offered']))
                                <p class="cart-guarantee-note mb-0">{{ $writingGuaranteeNote }}</p>
                            @endif
                            <p class="cart-source-steps mb-0">{{ $written ? $writingStepsWrite : ($selectedId ? $writingStepsUpload : $writingStepsWrite) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="col-lg-4">
            <div class="upload-zone mb-3">
                <h6 class="upload-zone-title">Upload article</h6>
                <p class="upload-zone-copy">Need a new .docx? Upload in Content Library (country first, then language), wait for approval, then assign it here.</p>
                <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}" class="btn btn-upload w-100">
                    <i class="fa fa-upload me-1"></i> Upload in Content Library
                </a>
            </div>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1">How it works</h6>
                    <p class="cart-source-steps mb-0" id="wizardSourceSteps">{{ $writingStepsWrite }}</p>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="small text-muted mb-3" id="wizardReadyNote">
                        @if($cartFullyAssigned)
                            Every placement has an article. Continue to pay.
                        @elseif($cartReady)
                            Ready lines can be paid now — unassigned sites stay in your cart.
                        @else
                            Choose an article option above to continue
                        @endif
                    </p>
                    @if($unreadyLines >= 2)
                        <button type="button" class="btn btn-outline-primary w-100 mb-2" id="wizardWriteAll">Have all articles written</button>
                    @endif
                    <a href="{{ route('advertiser.wizard.pay') }}"
                       id="wizardContinuePay"
                       class="btn btn-primary w-100 {{ $cartReady ? '' : 'disabled' }}"
                       @if(!$cartReady) aria-disabled="true" tabindex="-1" @endif>
                        Continue to pay <i class="fa fa-arrow-right ms-1"></i>
                    </a>
                    @if(!$cartReady)
                        <p class="small text-muted mt-2 mb-0" id="wizardProceedHint">Choose an article option above to continue</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const assignUrl = @json(route('advertiser.cart.assign-article'));
    const configureUrl = @json(route('advertiser.cart.configure'));
    const writeAllUrl = @json(route('advertiser.cart.write-all'));
    const libraryUrl = @json(route('advertiser.content-library', ['upload' => 1]));
    const csrf = @json(csrf_token());
    const copy = @json($articleCopy);
    const payBtn = document.getElementById('wizardContinuePay');
    const readyNote = document.getElementById('wizardReadyNote');
    const proceedHint = document.getElementById('wizardProceedHint');
    const stepsEl = document.getElementById('wizardSourceSteps');
    const stepsWrite = @json($writingStepsWrite);
    const stepsUpload = @json($writingStepsUpload);

    function lineIsWritten(row) {
        const source = String(row?.content_source || '').toLowerCase();
        return source === 'publisher' || source === 'slb' || source === 'written';
    }

    function lineFullyAssigned(row) {
        if (lineIsWritten(row)) return true;
        const qty = Math.max(1, parseInt(row.quantity, 10) || 1);
        const raw = Array.isArray(row.content_submission_ids) ? row.content_submission_ids : [];
        for (let i = 0; i < qty; i++) {
            const id = parseInt(raw[i] || (i === 0 ? row.content_submission_id : 0) || 0, 10) || 0;
            if (!id) return false;
        }
        return true;
    }

    function setReady(cart) {
        if (!payBtn) return;
        const lines = Array.isArray(cart) ? cart : [];
        const readyCount = lines.filter(lineFullyAssigned).length;
        const missing = Math.max(0, lines.length - readyCount);
        const ready = readyCount > 0;

        if (ready) {
            payBtn.classList.remove('disabled');
            payBtn.removeAttribute('aria-disabled');
            payBtn.removeAttribute('tabindex');
            if (readyNote) {
                readyNote.textContent = missing === 0
                    ? 'Every placement has an article. Continue to pay.'
                    : (readyCount + ' ready to pay. Unassigned sites stay in your cart.');
            }
            if (proceedHint) proceedHint.classList.add('d-none');
        } else {
            payBtn.classList.add('disabled');
            payBtn.setAttribute('aria-disabled', 'true');
            payBtn.setAttribute('tabindex', '-1');
            if (readyNote) readyNote.textContent = 'Choose an article option above to continue';
            if (proceedHint) proceedHint.classList.remove('d-none');
        }
        if (stepsEl) {
            const anyWritten = lines.some(lineIsWritten);
            const anyUpload = lines.some((row) => !lineIsWritten(row) && lineFullyAssigned(row));
            stepsEl.textContent = (anyUpload && !anyWritten) ? stepsUpload : stepsWrite;
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
                    status.textContent = 'Choose an option';
                    status.className = 'badge text-bg-secondary line-status';
                }
                const cart = Array.isArray(data.cart) ? data.cart : [];
                const ready = cart.some(lineFullyAssigned);
                setReady(cart);
                if (ready || submissionId) {
                    setTimeout(() => window.location.reload(), 400);
                }
            } catch (e) {
                feedback.innerHTML = '<span class="text-danger">Network error</span>';
            }
        });
    });

    document.querySelectorAll('.wizard-upload-cta').forEach((button) => {
        button.addEventListener('click', async function () {
            const card = this.closest('.wizard-line');
            const select = card.querySelector('.wizard-article-select');
            const feedback = card.querySelector('.line-feedback');
            if (card.querySelector('.wizard-write-cta.is-selected')) {
                feedback.textContent = 'Saving…';
                try {
                    const res = await fetch(configureUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            id: parseInt(card.getAttribute('data-site-id'), 10),
                            sensitive_type: card.getAttribute('data-sensitive-type') || '',
                            homepage_days: card.getAttribute('data-homepage-days') || 'none',
                            content_source: 'buyer',
                        }),
                    });
                    const data = await res.json();
                    if (!data.success) {
                        feedback.innerHTML = '<span class="text-danger">' + (data.error || data.message || 'Could not save') + '</span>';
                        return;
                    }
                } catch (e) {
                    feedback.innerHTML = '<span class="text-danger">Network error</span>';
                    return;
                }
            }
            if (select && select.options.length > 1) {
                select.focus();
                return;
            }
            window.location.href = libraryUrl;
        });
    });

    document.getElementById('wizardWriteAll')?.addEventListener('click', async function () {
        this.disabled = true;
        try {
            const res = await fetch(writeAllUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({}),
            });
            const data = await res.json();
            if (!data.success) {
                this.disabled = false;
                return;
            }
            window.location.reload();
        } catch (e) {
            this.disabled = false;
        }
    });

    document.querySelectorAll('.wizard-write-cta').forEach((button) => {
        button.addEventListener('click', async function () {
            const card = this.closest('.wizard-line');
            const feedback = card.querySelector('.line-feedback');
            const status = card.querySelector('.line-status');
            feedback.textContent = 'Saving…';
            try {
                const res = await fetch(configureUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: parseInt(card.getAttribute('data-site-id'), 10),
                        sensitive_type: card.getAttribute('data-sensitive-type') || '',
                        homepage_days: card.getAttribute('data-homepage-days') || 'none',
                        content_source: 'written',
                    }),
                });
                const data = await res.json();
                if (!data.success) {
                    feedback.innerHTML = '<span class="text-danger">' + (data.error || data.message || 'Could not save') + '</span>';
                    return;
                }
                if (status) {
                    status.textContent = 'Writing ordered';
                    status.className = 'badge text-bg-success line-status';
                }
                setReady(Array.isArray(data.cart) ? data.cart : []);
                window.location.reload();
            } catch (e) {
                feedback.innerHTML = '<span class="text-danger">Network error</span>';
            }
        });
    });

    document.querySelectorAll('.wizard-upload-cta').forEach((button) => {
        button.addEventListener('click', async function () {
            const card = this.closest('.wizard-line');
            const panel = card.querySelector('.wizard-upload-panel');
            const feedback = card.querySelector('.line-feedback');
            const status = card.querySelector('.line-status');
            feedback.textContent = 'Saving…';
            try {
                const res = await fetch(configureUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        id: parseInt(card.getAttribute('data-site-id'), 10),
                        sensitive_type: card.getAttribute('data-sensitive-type') || '',
                        homepage_days: card.getAttribute('data-homepage-days') || 'none',
                        content_source: 'buyer',
                    }),
                });
                const data = await res.json();
                if (!data.success) {
                    feedback.innerHTML = '<span class="text-danger">' + (data.error || data.message || 'Could not save') + '</span>';
                    return;
                }
                this.classList.add('is-selected');
                this.setAttribute('aria-pressed', 'true');
                card.querySelector('.wizard-write-cta')?.classList.remove('is-selected');
                if (panel) panel.classList.remove('d-none');
                if (status && !card.querySelector('.wizard-article-select')?.value) {
                    status.textContent = copy.heading;
                    status.className = 'badge text-bg-secondary line-status';
                }
                const steps = card.querySelector('.cart-item-steps');
                if (steps) steps.textContent = copy.upload_steps;
                feedback.textContent = '';
                setReady(Array.isArray(data.cart) ? data.cart : []);
            } catch (e) {
                feedback.innerHTML = '<span class="text-danger">Network error</span>';
            }
        });
    });

    const writeAll = document.getElementById('wizardWriteAll');
    if (writeAll) {
        writeAll.addEventListener('click', async function () {
            writeAll.disabled = true;
            try {
                const res = await fetch(writeAllUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();
                if (!data.success) {
                    writeAll.disabled = false;
                    return;
                }
                window.location.reload();
            } catch (e) {
                writeAll.disabled = false;
            }
        });
    }
})();
</script>
@endsection
