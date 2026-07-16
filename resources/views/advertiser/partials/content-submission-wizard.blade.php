@php
    $placements = [];
    $placementNumber = 0;
    foreach ($cartItems as $item) {
        for ($i = 0; $i < $item['quantity']; $i++) {
            $placementNumber++;
            $placements[] = [
                'site_id' => $item['id'],
                'site_name' => $item['name'],
                'copy_index' => $i,
                'placement_number' => $placementNumber,
            ];
        }
    }
    $cartKey = 'cart_' . md5(json_encode(collect($placements)->map(fn ($p) => $p['site_id'] . ':' . $p['copy_index'])->values()));
@endphp

<div class="card border-0 shadow-sm mb-4" id="contentSubmissionWizard"
     data-cart-key="{{ $cartKey }}"
     data-upload-url="{{ route('advertiser.content-submissions.upload') }}"
     data-drafts-url="{{ route('advertiser.content-submissions.drafts', ['cart_key' => $cartKey]) }}"
     data-config-url="{{ route('advertiser.content-submissions.config') }}"
     data-csrf="{{ csrf_token() }}">
    <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="fa fa-file-alt me-2"></i> 2. Content Submission</span>
        <span class="badge text-bg-light border" id="wizardStepBadge">Step 1 of 5</span>
    </div>
    <div class="card-body">
        <div class="content-wizard-progress mb-4" role="list">
            @foreach(['Upload Article', 'Anchor & URL', 'Feature Image', 'Schedule', 'Review'] as $idx => $label)
                <button type="button" class="content-wizard-step {{ $idx === 0 ? 'is-active' : '' }}" data-step="{{ $idx + 1 }}" role="listitem">
                    <span class="step-index">{{ $idx + 1 }}</span>
                    <span class="step-label">{{ $label }}</span>
                </button>
            @endforeach
        </div>

        <div class="alert alert-warning small mb-3" id="wizardHelpPreferred">
            Please upload your article as a Microsoft Word (.docx) document only. Other formats are not accepted.
            Your article will be evaluated for uniqueness (minimum 50%), quality, and content compliance before you can place an order.
        </div>

        <div class="content-wizard-panels">
            {{-- Step 1: Upload --}}
            <div class="wizard-panel" data-panel="1">
                <div class="d-flex flex-column gap-3" id="uploadPlacementList">
                    @foreach($placements as $p)
                        <div class="placement-upload-card border rounded-3 p-3"
                             data-site-id="{{ $p['site_id'] }}"
                             data-copy-index="{{ $p['copy_index'] }}"
                             data-placement-number="{{ $p['placement_number'] }}"
                             data-site-name="{{ $p['site_name'] }}">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <div class="fw-semibold d-flex align-items-center gap-2">
                                        <span class="placement-number">{{ $p['placement_number'] }}</span>
                                        {{ $p['site_name'] }}
                                    </div>
                                    <div class="small text-muted upload-status-text">No file uploaded</div>
                                </div>
                                <span class="badge moderation-badge text-bg-secondary">Pending</span>
                            </div>
                            <div class="small text-muted mb-2">Supported format: <strong>.docx</strong> only</div>
                            <input type="file" class="form-control content-upload-input" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                            <div class="progress mt-2 d-none upload-progress" style="height:6px;">
                                <div class="progress-bar" style="width:0%"></div>
                            </div>
                            <div class="upload-feedback small mt-2" aria-live="polite"></div>
                            <div class="document-preview mt-3 d-none">
                                <div class="fw-semibold small mb-1">📄 Live Document Preview</div>
                                <div class="document-preview-body border rounded-3 p-3 bg-light small"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Step 2: Anchor + Target --}}
            <div class="wizard-panel d-none" data-panel="2">
                <div class="d-flex flex-column gap-3" id="linkFieldsList"></div>
            </div>

            {{-- Step 3: Feature image --}}
            <div class="wizard-panel d-none" data-panel="3">
                <div class="d-flex flex-column gap-3" id="featureImageList"></div>
            </div>

            {{-- Step 4: Schedule --}}
            <div class="wizard-panel d-none" data-panel="4">
                <div class="mb-3">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="publication_mode" id="pubImmediate" value="immediate" checked>
                        <label class="form-check-label" for="pubImmediate">Publish Immediately</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="publication_mode" id="pubScheduled" value="scheduled">
                        <label class="form-check-label" for="pubScheduled">Schedule Publication</label>
                    </div>
                </div>
                <div id="scheduleFields" class="d-none border rounded-3 p-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Publication Date</label>
                            <input type="date" class="form-control" id="scheduledDate" min="{{ now()->addDay()->toDateString() }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Publication Time <span class="text-muted">(optional)</span></label>
                            <input type="time" class="form-control" id="scheduledTime" value="09:00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Timezone</label>
                            <select class="form-select" id="scheduleTimezone">
                                <option value="UTC" selected>UTC</option>
                                <option value="Europe/London">Europe/London</option>
                                <option value="Europe/Berlin">Europe/Berlin</option>
                                <option value="America/New_York">America/New_York</option>
                                <option value="America/Los_Angeles">America/Los_Angeles</option>
                                <option value="Asia/Dubai">Asia/Dubai</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-text mt-2">You can schedule up to 3 months ahead. Past dates are not allowed.</div>
                </div>
            </div>

            {{-- Step 5: Review --}}
            <div class="wizard-panel d-none" data-panel="5">
                <div id="contentReviewSummary" class="d-flex flex-column gap-3"></div>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-4 gap-2">
            <button type="button" class="btn btn-outline-secondary" id="wizardPrevBtn" disabled>Back</button>
            <div class="small text-muted" id="autosaveStatus">Drafts auto-save as you go</div>
            <button type="button" class="btn btn-primary" id="wizardNextBtn">Continue</button>
        </div>
    </div>
</div>

<style>
.content-wizard-progress{display:flex;gap:.5rem;flex-wrap:wrap}
.content-wizard-step{border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:.35rem .7rem;display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;color:#6b7280}
.content-wizard-step.is-active{border-color:#0b6266;color:#0b6266;background:#f0fbfb;font-weight:600}
.content-wizard-step.is-done{border-color:#86efac;color:#166534;background:#f0fdf4}
.content-wizard-step .step-index{width:1.25rem;height:1.25rem;border-radius:50%;background:#e5e7eb;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700}
.content-wizard-step.is-active .step-index{background:#0b6266;color:#fff}
.document-preview-body{max-height:220px;overflow:auto;line-height:1.5}
.placement-number{display:inline-flex;align-items:center;justify-content:center;width:1.5rem;height:1.5rem;border-radius:999px;background:#0b6266;color:#fff;font-size:.75rem;font-weight:700}
.feature-image-preview{max-width:180px;max-height:100px;object-fit:cover;border-radius:.5rem;border:1px solid #e5e7eb}
</style>

<script>
window.ContentCheckout = (function () {
    const root = document.getElementById('contentSubmissionWizard');
    if (!root) return { ready: () => false, payload: () => null };

    const state = {
        step: 1,
        placements: [],
        submissions: {}, // key siteId:copyIndex -> submission object
        config: null,
        schedule: { mode: 'immediate', date: '', time: '09:00', timezone: 'UTC' },
    };

    const cartKey = root.dataset.cartKey;
    const csrf = root.dataset.csrf;

    function keyFor(siteId, copyIndex) {
        return String(siteId) + ':' + String(copyIndex);
    }

    function initPlacements() {
        state.placements = Array.from(root.querySelectorAll('.placement-upload-card')).map(function (card) {
            return {
                site_id: Number(card.dataset.siteId),
                copy_index: Number(card.dataset.copyIndex),
                placement_number: Number(card.dataset.placementNumber),
                site_name: card.dataset.siteName,
                el: card,
            };
        });
    }

    async function loadConfig() {
        const res = await fetch(root.dataset.configUrl, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        state.config = data.config || {};
        if (state.config.help && state.config.help.preferred_format) {
            document.getElementById('wizardHelpPreferred').textContent = state.config.help.preferred_format;
        }
        if (!state.config.scheduling_enabled) {
            document.getElementById('pubScheduled').disabled = true;
        }
        if (state.config.max_schedule_at) {
            const max = new Date(state.config.max_schedule_at);
            const min = new Date(); min.setDate(min.getDate() + 0);
            const dateInput = document.getElementById('scheduledDate');
            dateInput.min = new Date().toISOString().slice(0, 10);
            dateInput.max = max.toISOString().slice(0, 10);
        }
    }

    async function restoreDrafts() {
        try {
            const res = await fetch(root.dataset.draftsUrl, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            (data.drafts || []).forEach(function (sub) {
                const k = keyFor(sub.site_id, sub.copy_index);
                state.submissions[k] = sub;
                paintUploadCard(k, sub);
            });
            buildDynamicSteps();
            maybeRestoreScheduleFromFirst();
        } catch (e) {}
    }

    function paintUploadCard(k, sub) {
        const card = root.querySelector('.placement-upload-card[data-site-id="' + k.split(':')[0] + '"][data-copy-index="' + k.split(':')[1] + '"]');
        if (!card || !sub) return;
        const badge = card.querySelector('.moderation-badge');
        const status = card.querySelector('.upload-status-text');
        const feedback = card.querySelector('.upload-feedback');
        const preview = card.querySelector('.document-preview');
        const previewBody = card.querySelector('.document-preview-body');

        status.textContent = sub.original_filename + ' · ' + (sub.word_count || 0) + ' words';
        badge.className = 'badge moderation-badge';
        if (sub.moderation_status === 'approved') {
            badge.classList.add('text-bg-success');
            badge.textContent = 'Approved';
            feedback.innerHTML = '<span class="text-success">Content validation passed.</span>';
        } else if (sub.moderation_status === 'rejected') {
            badge.classList.add('text-bg-danger');
            badge.textContent = 'Rejected';
            feedback.innerHTML = '<span class="text-danger">This article contains content that violates our publishing guidelines.<br>Please upload a revised document before continuing.</span>';
        } else {
            badge.classList.add('text-bg-warning');
            badge.textContent = sub.moderation_status || 'Processing';
        }

        if (sub.preview_html) {
            preview.classList.remove('d-none');
            previewBody.innerHTML = sub.preview_html;
        }
    }

    function buildDynamicSteps() {
        const linkList = document.getElementById('linkFieldsList');
        const imageList = document.getElementById('featureImageList');
        linkList.innerHTML = '';
        imageList.innerHTML = '';

        state.placements.forEach(function (p) {
            const k = keyFor(p.site_id, p.copy_index);
            const sub = state.submissions[k] || {};

            const linkCard = document.createElement('div');
            linkCard.className = 'border rounded-3 p-3';
            linkCard.innerHTML =
                '<div class="fw-semibold mb-2"><span class="placement-number me-1">' + p.placement_number + '</span> ' + escapeHtml(p.site_name) + '</div>' +
                '<div class="mb-3"><label class="form-label">Anchor Text</label>' +
                '<input type="text" class="form-control js-anchor" data-key="' + k + '" maxlength="' + ((state.config && state.config.anchor_max) || 120) + '" value="' + escapeAttr(sub.anchor_text || '') + '">' +
                '<div class="form-text">' + escapeHtml((state.config && state.config.help && state.config.help.anchor_text) || 'Enter the exact anchor text that should appear in the article.') + '</div></div>' +
                '<div><label class="form-label">Target URL</label>' +
                '<input type="url" class="form-control js-target" data-key="' + k + '" placeholder="https://" value="' + escapeAttr(sub.target_url || '') + '">' +
                '<div class="form-text">' + escapeHtml((state.config && state.config.help && state.config.help.target_url) || 'Enter the website URL that the anchor text should link to.') + '</div></div>';
            linkList.appendChild(linkCard);

            const imgCard = document.createElement('div');
            imgCard.className = 'border rounded-3 p-3';
            imgCard.innerHTML =
                '<div class="fw-semibold mb-2"><span class="placement-number me-1">' + p.placement_number + '</span> ' + escapeHtml(p.site_name) + '</div>' +
                '<label class="form-label">Feature Image URL <span class="text-muted">(optional)</span></label>' +
                '<input type="url" class="form-control js-feature" data-key="' + k + '" placeholder="https://..." value="' + escapeAttr(sub.feature_image_url || '') + '">' +
                '<div class="form-text">' + escapeHtml((state.config && state.config.help && state.config.help.feature_image) || '') + '</div>' +
                '<div class="mt-2 feature-preview-wrap">' + (sub.feature_image_url ? '<img class="feature-image-preview" src="' + escapeAttr(sub.feature_image_url) + '" alt="Feature preview">' : '') + '</div>';
            imageList.appendChild(imgCard);
        });

        linkList.querySelectorAll('.js-anchor, .js-target').forEach(function (el) {
            el.addEventListener('change', function () { persistField(el.dataset.key); });
            el.addEventListener('blur', function () { persistField(el.dataset.key); });
        });
        imageList.querySelectorAll('.js-feature').forEach(function (el) {
            el.addEventListener('input', function () {
                const wrap = el.parentElement.querySelector('.feature-preview-wrap');
                const url = el.value.trim();
                wrap.innerHTML = url ? '<img class="feature-image-preview" src="' + escapeAttr(url) + '" alt="Feature preview" onerror="this.remove()">' : '';
            });
            el.addEventListener('change', function () { persistField(el.dataset.key); });
        });
    }

    function maybeRestoreScheduleFromFirst() {
        const first = Object.values(state.submissions)[0];
        if (!first) return;
        if (first.publication_mode === 'scheduled') {
            document.getElementById('pubScheduled').checked = true;
            document.getElementById('scheduleFields').classList.remove('d-none');
            state.schedule.mode = 'scheduled';
            if (first.scheduled_publish_at) {
                const d = new Date(first.scheduled_publish_at);
                document.getElementById('scheduledDate').value = d.toISOString().slice(0, 10);
                document.getElementById('scheduledTime').value = d.toISOString().slice(11, 16);
            }
            if (first.timezone) {
                document.getElementById('scheduleTimezone').value = first.timezone;
                state.schedule.timezone = first.timezone;
            }
        }
    }

    async function persistField(k) {
        const sub = state.submissions[k];
        if (!sub || !sub.id) return;
        const anchorEl = root.querySelector('.js-anchor[data-key="' + k + '"]');
        const targetEl = root.querySelector('.js-target[data-key="' + k + '"]');
        const featureEl = root.querySelector('.js-feature[data-key="' + k + '"]');
        const body = {
            anchor_text: anchorEl ? anchorEl.value : sub.anchor_text,
            target_url: targetEl ? targetEl.value : sub.target_url,
            feature_image_url: featureEl ? (featureEl.value || null) : sub.feature_image_url,
            wizard_step: state.step,
            publication_mode: state.schedule.mode,
            scheduled_date: state.schedule.date || null,
            scheduled_time: state.schedule.time || null,
            timezone: state.schedule.timezone,
        };
        setAutosave('Saving draft…');
        try {
            const res = await fetch('/advertiser/content-submissions/' + sub.id, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (data.success && data.submission) {
                state.submissions[k] = data.submission;
                setAutosave('Draft saved');
            } else {
                setAutosave(data.message || 'Draft save failed', true);
            }
        } catch (e) {
            setAutosave('Draft save failed', true);
        }
    }

    function setAutosave(msg, isError) {
        const el = document.getElementById('autosaveStatus');
        el.textContent = msg;
        el.classList.toggle('text-danger', !!isError);
        el.classList.toggle('text-muted', !isError);
    }

    function bindUploads() {
        root.querySelectorAll('.content-upload-input').forEach(function (input) {
            input.addEventListener('change', async function () {
                const card = input.closest('.placement-upload-card');
                const file = input.files && input.files[0];
                if (!file) return;
                const siteId = card.dataset.siteId;
                const copyIndex = card.dataset.copyIndex;
                const k = keyFor(siteId, copyIndex);
                const progress = card.querySelector('.upload-progress');
                const bar = progress.querySelector('.progress-bar');
                const feedback = card.querySelector('.upload-feedback');
                progress.classList.remove('d-none');
                bar.style.width = '15%';
                feedback.textContent = 'Uploading & processing…';

                const fd = new FormData();
                fd.append('file', file);
                fd.append('site_id', siteId);
                fd.append('copy_index', copyIndex);
                fd.append('cart_key', cartKey);
                if (state.submissions[k] && state.submissions[k].id) {
                    fd.append('replace_id', state.submissions[k].id);
                }

                try {
                    bar.style.width = '55%';
                    const res = await fetch(root.dataset.uploadUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: fd,
                    });
                    bar.style.width = '90%';
                    const data = await res.json();
                    bar.style.width = '100%';
                    if (data.submission) {
                        state.submissions[k] = data.submission;
                        paintUploadCard(k, data.submission);
                        buildDynamicSteps();
                    }
                    if (!data.success) {
                        feedback.innerHTML = '<span class="text-danger">' + escapeHtml(data.message || 'Upload failed') + '</span>';
                    }
                } catch (e) {
                    feedback.innerHTML = '<span class="text-danger">Network error while uploading.</span>';
                } finally {
                    setTimeout(function () { progress.classList.add('d-none'); bar.style.width = '0%'; }, 600);
                }
            });
        });
    }

    function allUploadsApproved() {
        return state.placements.every(function (p) {
            const sub = state.submissions[keyFor(p.site_id, p.copy_index)];
            return sub && sub.moderation_status === 'approved';
        });
    }

    function allLinksValid() {
        return state.placements.every(function (p) {
            const k = keyFor(p.site_id, p.copy_index);
            const anchorEl = root.querySelector('.js-anchor[data-key="' + k + '"]');
            const targetEl = root.querySelector('.js-target[data-key="' + k + '"]');
            const anchor = (anchorEl ? anchorEl.value : '').trim();
            const target = (targetEl ? targetEl.value : '').trim();
            return anchor.length > 0 && /^https:\/\//i.test(target);
        });
    }

    function syncScheduleFromUi() {
        state.schedule.mode = document.getElementById('pubScheduled').checked ? 'scheduled' : 'immediate';
        state.schedule.date = document.getElementById('scheduledDate').value;
        state.schedule.time = document.getElementById('scheduledTime').value || '09:00';
        state.schedule.timezone = document.getElementById('scheduleTimezone').value || 'UTC';
    }

    async function persistScheduleToAll() {
        syncScheduleFromUi();
        const keys = Object.keys(state.submissions);
        for (const k of keys) {
            await persistField(k);
        }
    }

    function renderReview() {
        syncScheduleFromUi();
        const box = document.getElementById('contentReviewSummary');
        box.innerHTML = '';
        state.placements.forEach(function (p) {
            const sub = state.submissions[keyFor(p.site_id, p.copy_index)] || {};
            const card = document.createElement('div');
            card.className = 'border rounded-3 p-3';
            card.innerHTML =
                '<div class="fw-semibold mb-2"><span class="placement-number me-1">' + p.placement_number + '</span> ' + escapeHtml(p.site_name) + '</div>' +
                '<div class="small"><strong>Document:</strong> ' + escapeHtml(sub.original_filename || '—') + '</div>' +
                '<div class="small"><strong>Compliance:</strong> ' + escapeHtml(sub.moderation_status || '—') + '</div>' +
                '<div class="small"><strong>Anchor Text:</strong> ' + escapeHtml(sub.anchor_text || '—') + '</div>' +
                '<div class="small"><strong>Target URL:</strong> ' + escapeHtml(sub.target_url || '—') + '</div>' +
                '<div class="small"><strong>Feature Image:</strong> ' + escapeHtml(sub.feature_image_url || 'Publisher may choose') + '</div>';
            box.appendChild(card);
        });
        const sched = document.createElement('div');
        sched.className = 'border rounded-3 p-3 bg-light';
        if (state.schedule.mode === 'scheduled') {
            sched.innerHTML = '<strong>Publication Schedule:</strong> ' + escapeHtml(state.schedule.date) + ' ' + escapeHtml(state.schedule.time) + ' (' + escapeHtml(state.schedule.timezone) + ')';
        } else {
            sched.innerHTML = '<strong>Publication Schedule:</strong> Publish immediately after payment confirmation';
        }
        box.appendChild(sched);
    }

    function showStep(step) {
        state.step = step;
        root.querySelectorAll('.wizard-panel').forEach(function (panel) {
            panel.classList.toggle('d-none', Number(panel.dataset.panel) !== step);
        });
        root.querySelectorAll('.content-wizard-step').forEach(function (btn) {
            const s = Number(btn.dataset.step);
            btn.classList.toggle('is-active', s === step);
            btn.classList.toggle('is-done', s < step);
        });
        document.getElementById('wizardStepBadge').textContent = 'Step ' + step + ' of 5';
        document.getElementById('wizardPrevBtn').disabled = step === 1;
        document.getElementById('wizardNextBtn').textContent = step === 5 ? 'Ready for payment' : 'Continue';
        if (step === 2 || step === 3) buildDynamicSteps();
        if (step === 5) renderReview();
    }

    async function next() {
        if (state.step === 1) {
            if (!allUploadsApproved()) {
                Swal.fire('Content check required', 'Upload and approve a document for every placement before continuing.', 'warning');
                return;
            }
            buildDynamicSteps();
            showStep(2);
            return;
        }
        if (state.step === 2) {
            if (!allLinksValid()) {
                Swal.fire('Missing details', 'Enter anchor text and a valid HTTPS target URL for each placement.', 'warning');
                return;
            }
            for (const p of state.placements) {
                await persistField(keyFor(p.site_id, p.copy_index));
            }
            showStep(3);
            return;
        }
        if (state.step === 3) {
            for (const p of state.placements) {
                await persistField(keyFor(p.site_id, p.copy_index));
            }
            showStep(4);
            return;
        }
        if (state.step === 4) {
            syncScheduleFromUi();
            if (state.schedule.mode === 'scheduled' && !state.schedule.date) {
                Swal.fire('Schedule required', 'Choose a future publication date or select Publish Immediately.', 'warning');
                return;
            }
            await persistScheduleToAll();
            showStep(5);
            return;
        }
        if (state.step === 5) {
            document.getElementById('wizardNextBtn').classList.add('btn-success');
            setAutosave('Content ready — choose a payment method below');
            document.getElementById('paymentSectionCard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function prev() {
        if (state.step > 1) showStep(state.step - 1);
    }

    function ready() {
        syncScheduleFromUi();
        if (!allUploadsApproved() || !allLinksValid()) return false;
        if (state.schedule.mode === 'scheduled' && !state.schedule.date) return false;
        return state.placements.every(function (p) {
            const sub = state.submissions[keyFor(p.site_id, p.copy_index)];
            return sub && sub.id && sub.ready !== false && sub.anchor_text && sub.target_url;
        });
    }

    function payload() {
        const content_submissions = {};
        state.placements.forEach(function (p) {
            const sub = state.submissions[keyFor(p.site_id, p.copy_index)];
            if (!content_submissions[p.site_id]) content_submissions[p.site_id] = [];
            content_submissions[p.site_id][p.copy_index] = sub.id;
        });
        syncScheduleFromUi();
        return {
            content_submissions: content_submissions,
            publication_mode: state.schedule.mode,
            scheduled_date: state.schedule.date || null,
            scheduled_time: state.schedule.time || null,
            timezone: state.schedule.timezone,
        };
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function escapeAttr(str) { return escapeHtml(str).replace(/`/g, ''); }

    document.getElementById('wizardNextBtn').addEventListener('click', next);
    document.getElementById('wizardPrevBtn').addEventListener('click', prev);
    document.querySelectorAll('input[name="publication_mode"]').forEach(function (el) {
        el.addEventListener('change', function () {
            document.getElementById('scheduleFields').classList.toggle('d-none', !document.getElementById('pubScheduled').checked);
            syncScheduleFromUi();
        });
    });
    root.querySelectorAll('.content-wizard-step').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = Number(btn.dataset.step);
            if (target < state.step) showStep(target);
        });
    });

    // Persist draft when the tab is hidden / user navigates away
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            syncScheduleFromUi();
            Object.keys(state.submissions).forEach(function (k) { persistField(k); });
        }
    });

    initPlacements();
    bindUploads();
    loadConfig().then(restoreDrafts);

    return { ready: ready, payload: payload, state: state };
})();
</script>
