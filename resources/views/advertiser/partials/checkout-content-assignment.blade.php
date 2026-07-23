@php
    $approvedArticles = $approvedArticles ?? collect();
    $correctionArticles = $correctionArticles ?? collect();
    $marketplaceCountries = $marketplaceCountries ?? collect();
    $marketplaceLanguages = $marketplaceLanguages ?? collect();
    $librarySubmission = $librarySubmission ?? null;
    $checkoutSchedule = $checkoutSchedule ?? [];

    $placements = [];
    $n = 0;
    $usedPreselected = [];
    foreach ($cartItems as $item) {
        $lineIds = is_array($item['content_submission_ids'] ?? null) ? $item['content_submission_ids'] : [];
        for ($i = 0; $i < ($item['quantity'] ?? 1); $i++) {
            $n++;
            $preselected = (int) ($lineIds[$i] ?? 0);
            if ($preselected <= 0 && $i === 0) {
                $preselected = (int) ($item['content_submission_id'] ?? 0);
            }
            // Library session article only fills one empty placement — never every copy / every line.
            if ($preselected <= 0 && $i === 0 && $librarySubmission) {
                $libId = (int) $librarySubmission->id;
                if (! isset($usedPreselected[$libId])) {
                    $preselected = $libId;
                }
            }
            if ($preselected > 0) {
                if (isset($usedPreselected[$preselected])) {
                    $preselected = 0;
                } else {
                    $usedPreselected[$preselected] = true;
                }
            }
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
                'preselected' => $preselected > 0 ? $preselected : null,
            ];
        }
    }
@endphp

<style>
    .content-assign-card {
        border: 1px solid var(--brand-primary-border, #b8e4e4);
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
    .content-assign-preview img,
    .order-summary-article-preview img {
        max-width: 100%;
        height: auto;
        max-height: 120px;
        display: block;
        border-radius: 6px;
        margin: .35rem 0;
        object-fit: contain;
    }
    .market-pill {
        display: inline-flex;
        gap: 6px;
        align-items: center;
        background: var(--brand-primary-bg, #e6f5f5);
        color: var(--brand-primary, #185054);
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
            Each placement needs its own <strong>approved</strong> Content Library article
            (one article can be published on one site only).
            Upload or fix articles in the <a href="{{ route('advertiser.content-library', ['upload' => 1]) }}">Content Library</a> first — approval is automatic after compliance and uniqueness checks.
        </div>

        <div class="d-flex flex-column gap-3" id="contentAssignmentList">
            @foreach($placements as $p)
                @php
                    $siteCountries = $p['countries'] ?: array_filter([$p['country']]);
                    $siteLanguages = $p['languages'] ?: array_filter([$p['language']]);
                    $matching = $approvedArticles;
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
                        <label class="form-label">Approved article</label>
                        <select class="form-select article-select">
                            <option value="">— Select approved article —</option>
                            @forelse($matching as $article)
                                <option value="{{ $article->id }}"
                                    data-approved="1"
                                    data-anchor="{{ e($article->anchor_text) }}"
                                    data-target="{{ e($article->target_url) }}"
                                    data-preview-b64="{{ base64_encode(\App\Services\ContentUpload\ArticlePreviewHtml::normalize((string) $article->preview_html)) }}"
                                    data-history-b64="{{ base64_encode(json_encode($article->articleHistory(), JSON_UNESCAPED_UNICODE)) }}"
                                    data-country="{{ $article->country }}"
                                    data-language="{{ $article->language }}"
                                    data-word-count="{{ (int) $article->word_count }}"
                                    data-title="{{ e($article->title ?: $article->original_filename) }}"
                                    @selected((int) $p['preselected'] === (int) $article->id)>
                                    {{ $article->title ?: $article->original_filename }}
                                    ({{ strtoupper($article->country) }}/{{ strtoupper($article->language) }})
                                </option>
                            @empty
                                <option value="" disabled>No approved articles yet</option>
                            @endforelse
                        </select>
                        <div class="language-mismatch-hint small text-warning mt-1 d-none" role="status"></div>
                    </div>

                    <div class="article-preview-box d-none mb-3">
                        <div class="small text-uppercase fw-semibold text-muted mb-1">Article preview</div>
                        <div class="content-assign-preview preview-body"></div>
                    </div>

                    <div class="mb-3">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ route('advertiser.content-library', ['upload' => 1]) }}">
                            <i class="fa fa-upload me-1"></i> Upload in Content Library
                        </a>
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
    const cards = Array.from(document.querySelectorAll('.placement-assign'));

    function siteLanguageCodes(card) {
        try {
            return JSON.parse(card.dataset.languages || '[]')
                .map(function (code) { return String(code || '').toLowerCase(); })
                .filter(Boolean);
        } catch (e) {
            return [];
        }
    }

    function languageMismatchMessage(siteLangs, articleLang) {
        const article = String(articleLang || '').toLowerCase();
        if (!article || !siteLangs.length) return '';
        if (siteLangs.indexOf(article) !== -1) return '';
        const siteLabel = siteLangs.map(function (code) { return code.toUpperCase(); }).join('/');
        return 'Site is ' + siteLabel + ', article is ' + article.toUpperCase() + ' — continue?';
    }

    function confirmLanguageMismatch(message) {
        if (!message) return Promise.resolve(true);
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return window.Swal.fire({
                title: 'Language differs',
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Continue',
                cancelButtonText: 'Choose another',
                confirmButtonColor: '#185054',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then(function (result) { return !!result.isConfirmed; });
        }
        return Promise.resolve(window.confirm(message));
    }

    function setMismatchHint(card, message) {
        const hint = card.querySelector('.language-mismatch-hint');
        if (!hint) return;
        if (message) {
            hint.textContent = message.replace(' — continue?', '. You can still place this article.');
            hint.classList.remove('d-none');
        } else {
            hint.textContent = '';
            hint.classList.add('d-none');
        }
    }

    function syncSchedule() {
        document.getElementById('assignScheduleFields').classList.toggle(
            'd-none',
            !document.getElementById('assignPubScheduled').checked
        );
    }
    document.getElementById('assignPubScheduled')?.addEventListener('change', syncSchedule);
    document.getElementById('assignPubImmediate')?.addEventListener('change', syncSchedule);

    function decodeB64(value) {
        if (!value) return '';
        try {
            return atob(value);
        } catch (e) {
            return '';
        }
    }

    function selectedSubmission(card) {
        const select = card.querySelector('.article-select');
        const opt = select.options[select.selectedIndex];
        if (!select.value) return null;
        let history = [];
        try {
            history = JSON.parse(decodeB64(opt.dataset.historyB64 || '') || '[]');
        } catch (e) {
            history = [];
        }
        return {
            id: parseInt(select.value, 10),
            approved: opt.dataset.approved === '1',
            anchor: opt.dataset.anchor || '',
            target: opt.dataset.target || '',
            preview: decodeB64(opt.dataset.previewB64 || ''),
            history: Array.isArray(history) ? history : [],
            title: opt.dataset.title || (opt.textContent || '').trim(),
            wordCount: opt.dataset.wordCount || '',
            country: opt.dataset.country || '',
            language: opt.dataset.language || '',
        };
    }

    function formatHistoryAt(at) {
        if (!at) return '';
        try {
            const d = new Date(at);
            if (Number.isNaN(d.getTime())) return '';
            return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    function syncOrderSummaryArticle(card, selected) {
        const siteId = card.dataset.siteId;
        const copyIndex = card.dataset.copyIndex || '0';
        const summary = document.querySelector(
            '.site-summary-card[data-site-id="' + siteId + '"][data-copy-index="' + copyIndex + '"]'
        );
        if (!summary) return;
        let box = summary.querySelector('.order-summary-article');
        if (!selected || (!selected.preview && !selected.title)) {
            if (box) box.remove();
            return;
        }
        if (!box) {
            box = document.createElement('div');
            box.className = 'order-summary-article mt-3';
            summary.appendChild(box);
        }
        const market = ((selected.country || '') + '/' + (selected.language || '')).toUpperCase();
        const metaBits = [market, selected.wordCount ? (selected.wordCount + ' words') : ''].filter(Boolean).join(' · ');
        const history = (selected.history || []).slice(-6);
        let historyHtml = '';
        if (history.length) {
            historyHtml = '<div class="order-summary-article-history mt-2">' +
                '<div class="small text-uppercase text-muted fw-semibold mb-1">Article history</div>' +
                '<ul class="order-summary-history-list">' +
                history.map(function (event) {
                    return '<li>' +
                        '<span class="history-label">' + (event.label || '').replace(/</g, '&lt;') + '</span>' +
                        '<span class="history-detail">' + (event.detail ? String(event.detail).replace(/</g, '&lt;') : '') + '</span>' +
                        '<span class="history-at">' + formatHistoryAt(event.at) + '</span>' +
                        '</li>';
                }).join('') +
                '</ul></div>';
        }
        box.innerHTML =
            '<div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><div>' +
            '<div class="small text-uppercase text-muted fw-semibold">Article</div>' +
            '<div class="fw-semibold">' + String(selected.title || 'Selected article').replace(/</g, '&lt;') + '</div>' +
            (metaBits ? '<div class="small text-muted">' + metaBits.replace(/</g, '&lt;') + '</div>' : '') +
            '</div></div>' +
            (selected.preview ? '<div class="order-summary-article-preview"></div>' : '') +
            historyHtml;
        const previewEl = box.querySelector('.order-summary-article-preview');
        if (previewEl && selected.preview) {
            previewEl.innerHTML = selected.preview;
            fixAssignPreviewImages(previewEl);
        }
    }

    function refreshArticleAvailability() {
        const selectedByCard = new Map();
        cards.forEach(function (card) {
            const selected = selectedSubmission(card);
            if (selected && selected.id) {
                selectedByCard.set(card, selected.id);
            }
        });
        cards.forEach(function (card) {
            const select = card.querySelector('.article-select');
            if (!select) return;
            const current = select.value ? parseInt(select.value, 10) : 0;
            Array.from(select.options).forEach(function (opt) {
                if (!opt.value) return;
                const id = parseInt(opt.value, 10);
                let usedElsewhere = false;
                selectedByCard.forEach(function (sid, otherCard) {
                    if (otherCard !== card && sid === id) {
                        usedElsewhere = true;
                    }
                });
                const hide = usedElsewhere && id !== current;
                opt.disabled = hide;
                opt.hidden = hide;
            });
        });
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
            refreshArticleAvailability();
            return;
        }
        card.querySelector('.assign-anchor').value = selected.anchor || card.querySelector('.assign-anchor').value;
        card.querySelector('.assign-target').value = selected.target || card.querySelector('.assign-target').value;
        if (selected.preview) {
            previewBody.innerHTML = selected.preview;
            fixAssignPreviewImages(previewBody);
            previewBox.classList.remove('d-none');
        }
        status.className = 'badge text-bg-success assign-status';
        status.textContent = 'Approved article selected';
        syncOrderSummaryArticle(card, selected);
        refreshArticleAvailability();
    }

    function fixAssignPreviewImages(root) {
        if (!root) return;
        root.querySelectorAll('img').forEach(function (img) {
            const src = img.getAttribute('src') || '';
            const match = src.match(/^(?:https?:)?\/\/[^/]+(\/storage\/.+)$/i);
            if (match) {
                img.setAttribute('src', match[1]);
            }
        });
    }

    function allReady() {
        // Checkout may include not-ready sites; payment only needs at least one ready line.
        return cards.some(function (card) {
            const selected = selectedSubmission(card);
            return selected && selected.approved && selected.id;
        });
    }

    function syncReadyBadge() {
        const badge = document.getElementById('contentReadyBadge');
        if (!badge) return;
        const readyCount = cards.filter(function (card) {
            const selected = selectedSubmission(card);
            return selected && selected.approved && selected.id;
        }).length;
        if (readyCount > 0) {
            badge.className = 'badge text-bg-success border';
            badge.textContent = readyCount === cards.length
                ? 'Ready for payment'
                : (readyCount + ' of ' + cards.length + ' ready for payment');
        } else {
            badge.className = 'badge text-bg-light border';
            badge.textContent = 'Not ready';
        }
        if (typeof window.syncPlaceOrderForModeration === 'function') {
            window.syncPlaceOrderForModeration();
        }
    }

    cards.forEach(function (card) {
        const select = card.querySelector('.article-select');
        if (select) {
            select.dataset.prevValue = select.value || '';
            select.addEventListener('change', function () {
                const previous = select.dataset.prevValue || '';
                const selected = selectedSubmission(card);
                if (!selected) {
                    select.dataset.prevValue = '';
                    setMismatchHint(card, '');
                    refreshCard(card);
                    syncReadyBadge();
                    return;
                }
                // Block selecting an article already chosen on another placement.
                const duplicate = cards.some(function (other) {
                    if (other === card) return false;
                    const otherSelected = selectedSubmission(other);
                    return otherSelected && otherSelected.id === selected.id;
                });
                if (duplicate) {
                    select.value = previous;
                    if (window.Swal && typeof window.Swal.fire === 'function') {
                        window.Swal.fire({
                            title: 'Article already used',
                            text: 'Each article can only be published on one site. Choose a different article for this placement.',
                            icon: 'warning',
                            confirmButtonColor: '#185054',
                        });
                    } else {
                        window.alert('Each article can only be published on one site. Choose a different article.');
                    }
                    return;
                }
                const warn = languageMismatchMessage(siteLanguageCodes(card), selected.language);
                confirmLanguageMismatch(warn).then(function (ok) {
                    if (!ok) {
                        select.value = previous;
                        return;
                    }
                    select.dataset.prevValue = select.value || '';
                    setMismatchHint(card, warn);
                    refreshCard(card);
                    syncReadyBadge();
                });
            });
        }
        const initiallySelected = selectedSubmission(card);
        if (initiallySelected) {
            const warn = languageMismatchMessage(siteLanguageCodes(card), initiallySelected.language);
            setMismatchHint(card, warn);
        }
        refreshCard(card);
    });

    window.ContentCheckout = {
        ready: function () { return allReady(); },
        payload: function () {
            const map = {};
            const seen = {};
            cards.forEach(function (card) {
                const selected = selectedSubmission(card);
                if (!selected) return;
                if (seen[selected.id]) return;
                seen[selected.id] = true;
                const siteId = card.dataset.siteId;
                const copyIndex = parseInt(card.dataset.copyIndex || '0', 10);
                if (!map[siteId]) map[siteId] = [];
                map[siteId][copyIndex] = selected.id;
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

    refreshArticleAvailability();
    syncReadyBadge();
})();
</script>
