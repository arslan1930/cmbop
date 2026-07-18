@php
    $approvedArticles = $approvedArticles ?? collect();
    $correctionArticles = $correctionArticles ?? collect();
    $marketplaceCountries = $marketplaceCountries ?? collect();
    $marketplaceLanguages = $marketplaceLanguages ?? collect();
    $librarySubmission = $librarySubmission ?? null;
    $checkoutSchedule = $checkoutSchedule ?? [];

    $placements = [];
    $n = 0;
    foreach ($cartItems as $item) {
        for ($i = 0; $i < ($item['quantity'] ?? 1); $i++) {
            $n++;
            $placements[] = [
                'site_id' => $item['id'],
                'site_name' => $item['name'],
                'copy_index' => $i,
                'placement_number' => $n,
                'country' => $item['country'] ?? null,
                'countries' => $item['countries'] ?? [],
                'language' => $item['language'] ?? null,
                'languages' => $item['languages'] ?? [],
                'link_type' => $item['link_type'] ?? 'dofollow',
                'preselected' => $item['content_submission_id'] ?? ($librarySubmission->id ?? null),
            ];
        }
    }
@endphp

<style>
    .content-assign-card {
        border: 1px solid var(--brand-primary-border, #b8e8e6);
        border-radius: 12px;
        padding: 16px;
        background: #fff;
    }
    .content-assign-preview {
        max-height: 160px;
        overflow: auto;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        padding: 12px;
        font-size: .88rem;
    }
    .market-pill {
        display: inline-flex;
        gap: 6px;
        align-items: center;
        background: var(--brand-primary-bg, #e8f8f7);
        color: var(--brand-primary, #0b6266);
        border-radius: 999px;
        padding: 3px 10px;
        font-size: .75rem;
        font-weight: 600;
    }
</style>

<div class="card border-0 shadow-sm mb-4" id="contentSubmissionWizard">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-file-word me-2"></i> 2. Approved article for this order</span>
        <span class="badge text-bg-light border" id="contentReadyBadge">Not ready</span>
    </div>
    <div class="card-body">
        <div class="alert alert-info border-0 small">
            Orders can proceed only with an <strong>approved</strong> Content Library article that matches each website’s country and language.
            Articles needing correction must be edited and resubmitted first. Approval is automatic after compliance and uniqueness checks.
        </div>

        <div class="d-flex flex-column gap-3" id="contentAssignmentList">
            @foreach($placements as $p)
                @php
                    $siteCountries = $p['countries'] ?: array_filter([$p['country']]);
                    $siteLanguages = $p['languages'] ?: array_filter([$p['language']]);
                    $matching = $approvedArticles->filter(function ($article) use ($siteCountries, $siteLanguages) {
                        $c = strtolower((string) $article->country);
                        $l = strtolower((string) $article->language);
                        $countryOk = $siteCountries === [] || in_array($c, array_map('strtolower', $siteCountries), true);
                        $languageOk = $siteLanguages === [] || in_array($l, array_map('strtolower', $siteLanguages), true);
                        return $countryOk && $languageOk;
                    });
                @endphp
                <div class="content-assign-card placement-assign"
                     data-site-id="{{ $p['site_id'] }}"
                     data-copy-index="{{ $p['copy_index'] }}"
                     data-countries='@json(array_values($siteCountries))'
                     data-languages='@json(array_values($siteLanguages))'
                     data-link-type="{{ $p['link_type'] }}">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                        <div>
                            <div class="fw-semibold">{{ $p['placement_number'] }}. {{ $p['site_name'] }}</div>
                            <div class="mt-1">
                                <span class="market-pill">
                                    <i class="fa fa-globe"></i>
                                    {{ strtoupper(implode('/', $siteCountries ?: ['any'])) }}
                                    ·
                                    {{ strtoupper(implode('/', $siteLanguages ?: ['any'])) }}
                                </span>
                                @if(($p['link_type'] ?? '') === 'nofollow')
                                    <span class="badge text-bg-warning ms-1">nofollow only</span>
                                @endif
                            </div>
                        </div>
                        <span class="badge text-bg-secondary assign-status">Select approved article</span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Approved article (same country + language)</label>
                        <select class="form-select article-select">
                            <option value="">— Select approved article —</option>
                            @forelse($matching as $article)
                                <option value="{{ $article->id }}"
                                    data-approved="1"
                                    data-anchor="{{ e($article->anchor_text) }}"
                                    data-target="{{ e($article->target_url) }}"
                                    data-preview="{{ e($article->preview_html) }}"
                                    data-country="{{ $article->country }}"
                                    data-language="{{ $article->language }}"
                                    @selected((int) $p['preselected'] === (int) $article->id)>
                                    {{ $article->title ?: $article->original_filename }}
                                    ({{ strtoupper($article->country) }}/{{ strtoupper($article->language) }})
                                </option>
                            @empty
                                <option value="" disabled>No approved articles for this market yet</option>
                            @endforelse
                        </select>
                    </div>

                    <div class="article-preview-box d-none mb-3">
                        <div class="small text-uppercase fw-semibold text-muted mb-1">Article preview</div>
                        <div class="content-assign-preview preview-body"></div>
                    </div>

                    <div class="border rounded-3 p-3 bg-light mb-3">
                        <div class="fw-semibold small mb-2">Or upload a new .docx for this market</div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label small">Country</label>
                                <select class="form-select form-select-sm upload-country" required>
                                    <option value="">Select country</option>
                                    @foreach($marketplaceCountries as $country)
                                        <option value="{{ strtolower($country->code) }}"
                                            @selected(in_array(strtolower($country->code), array_map('strtolower', $siteCountries), true))>
                                            {{ $country->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">Language</label>
                                <select class="form-select form-select-sm upload-language" required>
                                    <option value="">Select language</option>
                                    @foreach($marketplaceLanguages as $language)
                                        <option value="{{ strtolower($language->code) }}"
                                            @selected(in_array(strtolower($language->code), array_map('strtolower', $siteLanguages), true))>
                                            {{ $language->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <input type="file" class="form-control form-control-sm upload-file mb-2" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                        <div class="small text-muted mb-2">Select country and language before uploading. Order unlocks only after automatic approval.</div>
                        <button type="button" class="btn btn-sm btn-outline-primary upload-btn">Upload & check</button>
                        <div class="upload-feedback small mt-2" aria-live="polite"></div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">Anchor text</label>
                            <input type="text" class="form-control form-control-sm assign-anchor" maxlength="120" placeholder="Auto-filled when found">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Target URL</label>
                            <input type="url" class="form-control form-control-sm assign-target" placeholder="https://">
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if($correctionArticles->isNotEmpty())
            <div class="mt-4">
                <div class="fw-semibold mb-2">Articles needing correction</div>
                <div class="d-flex flex-column gap-2">
                    @foreach($correctionArticles as $article)
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 p-2">
                            <div class="small">
                                <strong>{{ $article->title ?: $article->original_filename }}</strong>
                                <span class="text-muted">· {{ strtoupper((string) $article->country) }}/{{ strtoupper((string) $article->language) }}</span>
                                <span class="badge text-bg-warning ms-1">{{ str_replace('_', ' ', $article->moderation_status) }}</span>
                            </div>
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('advertiser.content-library', ['edit' => $article->id, 'upload' => 1]) }}">
                                Edit & resubmit
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mt-4 border rounded-3 p-3">
            <div class="fw-semibold mb-2">Publication schedule</div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="checkout_publication_mode" id="assignPubImmediate" value="immediate"
                       @checked(($checkoutSchedule['mode'] ?? 'immediate') !== 'scheduled')>
                <label class="form-check-label" for="assignPubImmediate">Publish Immediately</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="checkout_publication_mode" id="assignPubScheduled" value="scheduled"
                       @checked(($checkoutSchedule['mode'] ?? '') === 'scheduled')>
                <label class="form-check-label" for="assignPubScheduled">Schedule Publication</label>
            </div>
            <div id="assignScheduleFields" class="row g-2 {{ ($checkoutSchedule['mode'] ?? '') === 'scheduled' ? '' : 'd-none' }}">
                <div class="col-md-4">
                    <input type="date" class="form-control" id="assignScheduleDate"
                           value="{{ $checkoutSchedule['date'] ?? '' }}"
                           min="{{ now()->toDateString() }}" max="{{ now()->addMonths(3)->toDateString() }}">
                </div>
                <div class="col-md-4">
                    <input type="time" class="form-control" id="assignScheduleTime" value="{{ $checkoutSchedule['time'] ?? '09:00' }}">
                </div>
                <div class="col-md-4">
                    <select class="form-select" id="assignScheduleTimezone">
                        @foreach(['UTC','Europe/London','Europe/Berlin','America/New_York'] as $tz)
                            <option value="{{ $tz }}" @selected(($checkoutSchedule['timezone'] ?? 'UTC') === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const uploadUrl = @json(route('advertiser.content-submissions.upload'));
    const csrf = @json(csrf_token());
    const cards = Array.from(document.querySelectorAll('.placement-assign'));

    function syncSchedule() {
        document.getElementById('assignScheduleFields').classList.toggle(
            'd-none',
            !document.getElementById('assignPubScheduled').checked
        );
    }
    document.getElementById('assignPubScheduled')?.addEventListener('change', syncSchedule);
    document.getElementById('assignPubImmediate')?.addEventListener('change', syncSchedule);

    function selectedSubmission(card) {
        const select = card.querySelector('.article-select');
        const opt = select.options[select.selectedIndex];
        if (!select.value) return null;
        return {
            id: parseInt(select.value, 10),
            approved: opt.dataset.approved === '1',
            anchor: opt.dataset.anchor || '',
            target: opt.dataset.target || '',
            preview: opt.dataset.preview || '',
        };
    }

    function syncOrderSummaryArticle(card, selected) {
        const siteId = card.dataset.siteId;
        const copyIndex = card.dataset.copyIndex || '0';
        const summary = document.querySelector(
            '.site-summary-card[data-site-id="' + siteId + '"][data-copy-index="' + copyIndex + '"]'
        );
        if (!summary) return;
        let box = summary.querySelector('.order-summary-article');
        if (!selected || !selected.preview) {
            if (box) box.remove();
            return;
        }
        if (!box) {
            box = document.createElement('div');
            box.className = 'order-summary-article mt-3';
            summary.appendChild(box);
        }
        const opt = card.querySelector('.article-select')?.selectedOptions?.[0];
        const title = opt ? opt.textContent.trim() : 'Selected article';
        box.innerHTML =
            '<div class="small text-uppercase text-muted fw-semibold">Article</div>' +
            '<div class="fw-semibold mb-2">' + title.replace(/</g, '&lt;') + '</div>' +
            '<div class="order-summary-article-preview"></div>';
        box.querySelector('.order-summary-article-preview').innerHTML = selected.preview;
    }

    function refreshCard(card) {
        const selected = selectedSubmission(card);
        const status = card.querySelector('.assign-status');
        const previewBox = card.querySelector('.article-preview-box');
        const previewBody = card.querySelector('.preview-body');
        if (!selected) {
            status.className = 'badge text-bg-secondary assign-status';
            status.textContent = 'Select approved article';
            previewBox.classList.add('d-none');
            syncOrderSummaryArticle(card, null);
            return;
        }
        card.querySelector('.assign-anchor').value = selected.anchor || card.querySelector('.assign-anchor').value;
        card.querySelector('.assign-target').value = selected.target || card.querySelector('.assign-target').value;
        if (selected.preview) {
            previewBody.innerHTML = selected.preview;
            previewBox.classList.remove('d-none');
        }
        status.className = 'badge text-bg-success assign-status';
        status.textContent = 'Approved article selected';
        syncOrderSummaryArticle(card, selected);
    }

    function allReady() {
        return cards.every(function (card) {
            const selected = selectedSubmission(card);
            return selected && selected.approved && selected.id;
        });
    }

    function syncReadyBadge() {
        const badge = document.getElementById('contentReadyBadge');
        if (!badge) return;
        if (allReady()) {
            badge.className = 'badge text-bg-success border';
            badge.textContent = 'Ready for payment';
        } else {
            badge.className = 'badge text-bg-light border';
            badge.textContent = 'Not ready';
        }
        if (typeof window.syncPlaceOrderForModeration === 'function') {
            window.syncPlaceOrderForModeration();
        }
    }

    cards.forEach(function (card) {
        card.querySelector('.article-select')?.addEventListener('change', function () {
            refreshCard(card);
            syncReadyBadge();
        });
        refreshCard(card);

        card.querySelector('.upload-btn')?.addEventListener('click', async function () {
            const fileInput = card.querySelector('.upload-file');
            const country = card.querySelector('.upload-country').value;
            const language = card.querySelector('.upload-language').value;
            const feedback = card.querySelector('.upload-feedback');
            const file = fileInput.files && fileInput.files[0];
            if (!country || !language) {
                feedback.innerHTML = '<span class="text-danger">Select country and language before uploading.</span>';
                return;
            }
            if (!file || !/\.docx$/i.test(file.name)) {
                feedback.innerHTML = '<span class="text-danger">Please upload a .docx file.</span>';
                return;
            }
            const fd = new FormData();
            fd.append('file', file);
            fd.append('country', country);
            fd.append('language', language);
            fd.append('site_id', card.dataset.siteId);
            fd.append('copy_index', card.dataset.copyIndex);
            feedback.textContent = 'Uploading and checking…';
            try {
                const res = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await res.json();
                if (!data.success || !data.submission) {
                    feedback.innerHTML = '<span class="text-danger">' + (data.message || 'Upload failed') + '</span>';
                    return;
                }
                const s = data.submission;
                if (s.moderation_status !== 'approved') {
                    feedback.innerHTML = '<span class="text-warning">' + (data.message || 'Needs correction') +
                        ' <a href="' + @json(route('advertiser.content-library')) + '?edit=' + s.id + '&upload=1">Edit & resubmit</a></span>';
                    syncReadyBadge();
                    return;
                }
                const select = card.querySelector('.article-select');
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.dataset.approved = '1';
                opt.dataset.anchor = s.anchor_text || '';
                opt.dataset.target = s.target_url || '';
                opt.dataset.preview = s.preview_html || '';
                opt.dataset.country = s.country || country;
                opt.dataset.language = s.language || language;
                opt.textContent = (s.title || s.original_filename) + ' (' + String(s.country || country).toUpperCase() + '/' + String(s.language || language).toUpperCase() + ')';
                select.appendChild(opt);
                select.value = String(s.id);
                feedback.innerHTML = '<span class="text-success">Approved. Article selected for this website.</span>';
                refreshCard(card);
                syncReadyBadge();
            } catch (e) {
                feedback.innerHTML = '<span class="text-danger">Network error while uploading.</span>';
            }
        });
    });

    window.ContentCheckout = {
        ready: function () { return allReady(); },
        payload: function () {
            const map = {};
            cards.forEach(function (card) {
                const selected = selectedSubmission(card);
                if (!selected) return;
                const siteId = card.dataset.siteId;
                const copyIndex = parseInt(card.dataset.copyIndex || '0', 10);
                if (!map[siteId]) map[siteId] = [];
                map[siteId][copyIndex] = selected.id;

                // Persist link edits onto submission via draft patch is optional; include for server map only.
            });
            return {
                content_submissions: map,
                publication_mode: document.getElementById('assignPubScheduled')?.checked ? 'scheduled' : 'immediate',
                scheduled_date: document.getElementById('assignScheduleDate')?.value || null,
                scheduled_time: document.getElementById('assignScheduleTime')?.value || null,
                timezone: document.getElementById('assignScheduleTimezone')?.value || 'UTC',
            };
        }
    };

    syncReadyBadge();
})();
</script>
