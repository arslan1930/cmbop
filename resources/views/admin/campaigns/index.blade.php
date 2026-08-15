@extends('admin.layouts.app')

@section('content')
@php
    $preselect = \App\Services\AudienceInventoryService::canonicalAudienceKey((string) request('audience', 'advertisers'))
        ?? 'advertisers';
    if (!in_array($preselect, \App\Services\AudienceInventoryService::audienceKeys(), true)) {
        $preselect = 'advertisers';
    }
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Updates &amp; Campaigns</h1>
            <p class="text-muted mb-0">Email promotions and platform updates to advertisers, publishers, or a custom selection.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.audiences.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="fa fa-address-book me-1"></i> Audience Inventory
            </a>
            <a href="{{ route('admin.emails.index') }}" class="btn btn-sm btn-outline-secondary">
                Email Center
            </a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertisers available (verified)</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Publishers available (verified)</div>
                    <h3 class="mb-0">{{ number_format($stats['publishers']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Unique combined (verified)</div>
                    <h3 class="mb-0">{{ number_format($stats['both_unique']) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertisers: never checked out</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers_never_checked_out'] ?? $stats['advertisers_no_orders'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertisers: no paid orders</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers_no_paid_orders'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Publishers: no sites</div>
                    <h3 class="mb-0">{{ number_format($stats['publishers_no_sites'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Advertisers: never deposited</div>
                    <h3 class="mb-0">{{ number_format($stats['advertisers_never_deposited'] ?? 0) }}</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-paper-plane me-2 text-primary"></i>Compose campaign</strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.campaigns.send') }}" id="campaignForm">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Internal name (optional)</label>
                                <input type="text" name="name" class="form-control" value="{{ old_text('name') }}" maxlength="120" placeholder="BF25 advertiser blast">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Audience</label>
                                <select name="audience" id="campaignAudience" class="form-select" required>
                                    <option value="advertisers" @selected(old('audience', $preselect) === 'advertisers')>All Advertisers ({{ $stats['advertisers'] }})</option>
                                    <option value="publishers" @selected(old('audience', $preselect) === 'publishers')>All Publishers ({{ $stats['publishers'] }})</option>
                                    <option value="both" @selected(old('audience', $preselect) === 'both')>Advertisers + Publishers ({{ $stats['both_unique'] }} unique)</option>
                                    <option value="advertisers_no_orders" @selected(old('audience', $preselect) === 'advertisers_no_orders')>
                                        Advertisers: never checked out ({{ $stats['advertisers_never_checked_out'] ?? $stats['advertisers_no_orders'] ?? 0 }})
                                    </option>
                                    <option value="advertisers_no_paid_orders" @selected(old('audience', $preselect) === 'advertisers_no_paid_orders')>
                                        Advertisers: no paid orders ({{ $stats['advertisers_no_paid_orders'] ?? 0 }})
                                    </option>
                                    <option value="publishers_no_sites" @selected(old('audience', $preselect) === 'publishers_no_sites')>
                                        Publishers: no sites ({{ $stats['publishers_no_sites'] ?? 0 }})
                                    </option>
                                    <option value="advertisers_never_deposited" @selected(old('audience', $preselect) === 'advertisers_never_deposited')>
                                        Advertisers: never deposited ({{ $stats['advertisers_never_deposited'] ?? 0 }})
                                    </option>
                                    <option value="advertisers_paid_orders" @selected(old('audience', $preselect) === 'advertisers_paid_orders')>
                                        Advertisers: paid customers ({{ $stats['advertisers_paid_orders'] ?? 0 }})
                                    </option>
                                    <option value="advertisers_deposited_no_orders" @selected(old('audience', $preselect) === 'advertisers_deposited_no_orders')>
                                        Advertisers: deposited, no paid orders ({{ $stats['advertisers_deposited_no_orders'] ?? 0 }})
                                    </option>
                                    <option value="publishers_no_active_sites" @selected(old('audience', $preselect) === 'publishers_no_active_sites')>
                                        Publishers: no active sites ({{ $stats['publishers_no_active_sites'] ?? 0 }})
                                    </option>
                                    <option value="selected" @selected(old('audience', $preselect) === 'selected')>Select specific users…</option>
                                </select>
                            </div>

                            <div class="col-12" id="selectedUsersWrap" style="display:none;">
                                <label class="form-label">Select recipients</label>
                                <div class="border rounded-3 p-3" style="max-height:260px; overflow:auto;">
                                    <div class="mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllAdv">All advertisers</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllPub">All publishers</button>
                                        <button type="button" class="btn btn-sm btn-outline-dark" id="clearSelected">Clear</button>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <div class="fw-semibold small mb-1">Advertisers</div>
                                            @foreach($advertisers as $user)
                                                <div class="form-check">
                                                    <input class="form-check-input user-check adv-check" type="checkbox" name="user_ids[]" value="{{ $user->id }}" id="u{{ $user->id }}"
                                                        @checked(collect(old('user_ids', []))->contains($user->id))>
                                                    <label class="form-check-label small" for="u{{ $user->id }}">{{ $user->name }} <span class="text-muted">&lt;{{ $user->email }}&gt;</span></label>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="col-md-6">
                                            <div class="fw-semibold small mb-1">Publishers</div>
                                            @foreach($publishers as $user)
                                                @php $dup = $advertisers->contains('id', $user->id); @endphp
                                                @if(!$dup)
                                                    <div class="form-check">
                                                        <input class="form-check-input user-check pub-check" type="checkbox" name="user_ids[]" value="{{ $user->id }}" id="p{{ $user->id }}"
                                                            @checked(collect(old('user_ids', []))->contains($user->id))>
                                                        <label class="form-check-label small" for="p{{ $user->id }}">{{ $user->name }} <span class="text-muted">&lt;{{ $user->email }}&gt;</span></label>
                                                    </div>
                                                @else
                                                    <div class="form-check">
                                                        <input class="form-check-input user-check pub-check" type="checkbox" value="{{ $user->id }}" id="p{{ $user->id }}" disabled>
                                                        <label class="form-check-label small text-muted" for="p{{ $user->id }}">{{ $user->name }} (also advertiser)</label>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text">
                                    <span id="selectedCount">0</span> user(s) selected
                                    @if(!empty($pickerCapped))
                                        <span class="d-block">Showing the first {{ \App\Services\AudienceInventoryService::PICKER_LIMIT }} per role. Use Audience Inventory to email a full segment.</span>
                                    @endif
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Quick templates</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary campaign-template"
                                        data-subject="🎉 20% OFF this week"
                                        data-body="<p><strong>Limited-time offer:</strong> Save 20% on guest posts until Sunday. Inventory is limited — claim yours while it lasts.</p>"
                                        data-cta="Shop the offer"
                                        data-url="{{ url('/advertiser/catalog') }}">Limited-Time Offer</button>
                                    <button type="button" class="btn btn-sm btn-outline-success campaign-template"
                                        data-subject="🚀 New Spending Analytics is now live!"
                                        data-body="<p>You can now track spend by <strong>order</strong>, <strong>day</strong>, and <strong>month</strong> from your advertiser dashboard.</p>"
                                        data-cta="Open analytics"
                                        data-url="{{ url('/advertiser/analytics') }}">New Feature</button>
                                    <button type="button" class="btn btn-sm btn-outline-warning campaign-template"
                                        data-subject="📢 Scheduled maintenance notice"
                                        data-body="<p>We will perform scheduled maintenance this weekend. Some services may be briefly unavailable. Thanks for your patience.</p>"
                                        data-cta=""
                                        data-url="">Maintenance Notice</button>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Subject</label>
                                <input type="text" name="subject" id="campaignSubject" class="form-control" value="{{ old_text('subject', request('subject')) }}" required maxlength="180" placeholder="Black Friday update for our partners">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Message (HTML allowed: p, strong, em, lists, links)</label>
                                <textarea name="body_html" id="campaignBody" class="form-control" rows="8" required maxlength="20000" placeholder="<p>Share your update, discount, or promotion here.</p>">{{ old_text('body_html') }}</textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA label (optional)</label>
                                <input type="text" name="cta_label" class="form-control" value="{{ old_text('cta_label', request('cta_label')) }}" maxlength="80" placeholder="View offer">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CTA URL (optional)</label>
                                @php
                                    $prefillCta = old_text('cta_url', request('cta_url'));
                                    if ($prefillCta !== '' && str_starts_with($prefillCta, '/') && ! str_starts_with($prefillCta, '//')) {
                                        $prefillCta = rtrim((string) config('app.url'), '/').$prefillCta;
                                    }
                                @endphp
                                <input type="url" name="cta_url" class="form-control" value="{{ $prefillCta }}" maxlength="500" placeholder="https://">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="respect_preferences" value="0">
                                    <input class="form-check-input" type="checkbox" name="respect_preferences" value="1" id="respect_preferences"
                                        @checked(filter_var(old('respect_preferences', true), FILTER_VALIDATE_BOOLEAN))>
                                    <label class="form-check-label" for="respect_preferences">
                                        Respect user “Marketing Emails” preference (recommended)
                                    </label>
                                </div>
                                <div class="form-check mt-2">
                                    <input type="hidden" name="include_unverified" value="0">
                                    <input class="form-check-input" type="checkbox" name="include_unverified" value="1" id="include_unverified"
                                        @checked(filter_var(old('include_unverified', false), FILTER_VALIDATE_BOOLEAN))>
                                    <label class="form-check-label" for="include_unverified">
                                        Include unverified email addresses
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            {{-- Copy is kept for ActionConfirmDialogsTest; do not put data-slb-confirm on the
                                 submitter/form — slb-confirm.js capture would fire before the live count. --}}
                            <span id="campaignConfirmFallback" class="d-none" hidden
                                  data-slb-confirm="Send this campaign to the selected audience now?"></span>
                            <button type="submit" class="btn btn-primary" id="campaignSendBtn">
                                <i class="fa fa-paper-plane me-1"></i> Send campaign
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="previewBtn">
                                <i class="fa fa-eye me-1"></i> Preview email
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-eye me-2 text-primary"></i>Preview</strong>
                </div>
                <div class="card-body">
                    <iframe id="previewFrame" title="Campaign preview" sandbox referrerpolicy="no-referrer" style="width:100%; min-height:360px; border:1px solid #e2e8f0; border-radius:12px; background:#fff;"></iframe>
                    <div class="small text-muted mt-2" id="previewStatus">Click “Preview email” to render the branded message.</div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <strong><i class="fa fa-history me-2 text-primary"></i>Recent campaigns</strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Subject</th>
                                    <th>Audience</th>
                                    <th>Sent</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($campaigns as $campaign)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold small">{{ \Illuminate\Support\Str::limit($campaign->subject, 36) }}</div>
                                            <div class="text-muted" style="font-size:.75rem;">
                                                {{ ucfirst($campaign->status) }}
                                                ·
                                                {{ optional($campaign->sent_at)->format('M j, g:ia') ?: '—' }}
                                            </div>
                                        </td>
                                        <td class="small">{{ $campaign->audienceLabel() }}</td>
                                        <td class="small">
                                            {{ $campaign->sent_count }}/{{ $campaign->recipients_count }}
                                            @if($campaign->skipped_count)
                                                <span class="text-muted">({{ $campaign->skipped_count }} skip)</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">No campaigns sent yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($campaigns->hasPages())
                    <div class="card-footer bg-white">{{ $campaigns->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('campaignForm');
    const audience = document.getElementById('campaignAudience');
    const wrap = document.getElementById('selectedUsersWrap');
    const countEl = document.getElementById('selectedCount');

    function syncAudience() {
        wrap.style.display = audience.value === 'selected' ? '' : 'none';
        updateCount();
    }
    function updateCount() {
        countEl.textContent = document.querySelectorAll('.user-check:checked:not(:disabled)').length;
    }

    audience.addEventListener('change', syncAudience);
    document.querySelectorAll('.user-check').forEach(function (el) {
        el.addEventListener('change', updateCount);
    });
    document.getElementById('selectAllAdv').addEventListener('click', function () {
        document.querySelectorAll('.adv-check').forEach(function (el) { el.checked = true; });
        updateCount();
    });
    document.getElementById('selectAllPub').addEventListener('click', function () {
        document.querySelectorAll('.pub-check:not(:disabled)').forEach(function (el) { el.checked = true; });
        updateCount();
    });
    document.getElementById('clearSelected').addEventListener('click', function () {
        document.querySelectorAll('.user-check').forEach(function (el) { el.checked = false; });
        updateCount();
    });

    document.querySelectorAll('.campaign-template').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('campaignSubject').value = btn.dataset.subject || '';
            document.getElementById('campaignBody').value = btn.dataset.body || '';
            form.querySelector('[name=cta_label]').value = btn.dataset.cta || '';
            form.querySelector('[name=cta_url]').value = btn.dataset.url || '';
        });
    });

    const previewStatus = document.getElementById('previewStatus');
    const previewFrame = document.getElementById('previewFrame');
    const sendBtn = document.getElementById('campaignSendBtn');
    const countUrl = @json(route('admin.campaigns.recipient-count'));

    function setPreviewStatus(message, isError) {
        previewStatus.textContent = message;
        previewStatus.classList.toggle('text-danger', !!isError);
        previewStatus.classList.toggle('text-muted', !isError);
    }

    function selectedUserIds() {
        return Array.from(document.querySelectorAll('.user-check:checked:not(:disabled)')).map(function (el) {
            return el.value;
        });
    }

    function includeUnverified() {
        const box = document.getElementById('include_unverified');
        return !!(box && box.checked);
    }

    async function fetchRecipientCount() {
        const params = new URLSearchParams();
        params.set('audience', audience.value);
        params.set('include_unverified', includeUnverified() ? '1' : '0');
        if (audience.value === 'selected') {
            selectedUserIds().forEach(function (id) {
                params.append('user_ids[]', id);
            });
        }

        params.set('_token', form.querySelector('[name=_token]').value);
        const res = await fetch(countUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-TOKEN': form.querySelector('[name=_token]').value,
            },
            body: params.toString(),
        });
        if (!res.ok) {
            throw new Error('count-failed');
        }

        return res.json();
    }

    function confirmSend(text) {
        if (typeof window.slbConfirm === 'function') {
            return window.slbConfirm({
                title: 'Send campaign?',
                text: text,
                confirmText: 'Send now',
                icon: 'question',
            });
        }

        return Promise.resolve(window.confirm(text));
    }

    function alertSend(title) {
        if (typeof window.slbAlert === 'function') {
            return window.slbAlert({ icon: 'error', title: title, toast: false });
        }

        window.alert(title);
        return Promise.resolve();
    }

    form.addEventListener('submit', function (e) {
        if (form.dataset.slbAllowSubmit === '1') {
            delete form.dataset.slbAllowSubmit;
            e.stopImmediatePropagation();
            if (sendBtn) {
                sendBtn.disabled = true;
                sendBtn.setAttribute('aria-busy', 'true');
            }
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        if (sendBtn) {
            sendBtn.disabled = true;
        }

        fetchRecipientCount()
            .then(function (data) {
                const count = Number(data.count || 0);
                const label = data.label || 'the selected audience';
                if (count < 1) {
                    return alertSend('No recipients found for that audience.').then(function () {
                        return false;
                    });
                }

                let text = 'Send to ' + count.toLocaleString() + ' recipient' + (count === 1 ? '' : 's') + ' (' + label + ')?';
                const excluded = Number(data.unverified_excluded || 0);
                if (excluded > 0) {
                    text += ' ' + excluded.toLocaleString() + ' unverified excluded.';
                }
                if (sendBtn) {
                    sendBtn.disabled = false;
                }

                return confirmSend(text);
            })
            .then(function (ok) {
                if (!ok) {
                    return;
                }
                form.dataset.slbAllowSubmit = '1';
                // requestSubmit() throws if the submitter is disabled.
                if (sendBtn) {
                    sendBtn.disabled = false;
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(sendBtn || undefined);
                } else {
                    HTMLFormElement.prototype.submit.call(form);
                }
            })
            .catch(function () {
                return alertSend('Could not count recipients — refresh and retry.');
            })
            .finally(function () {
                if (form.dataset.slbAllowSubmit !== '1' && sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.removeAttribute('aria-busy');
                }
            });
    }, true);

    document.getElementById('previewBtn').addEventListener('click', async function () {
        const fd = new FormData();
        fd.append('_token', form.querySelector('[name=_token]').value);
        fd.append('subject', document.getElementById('campaignSubject').value);
        fd.append('body_html', document.getElementById('campaignBody').value);
        const ctaLabel = form.querySelector('[name=cta_label]').value;
        const ctaUrl = form.querySelector('[name=cta_url]').value;
        if (ctaLabel) fd.append('cta_label', ctaLabel);
        if (ctaUrl) fd.append('cta_url', ctaUrl);

        setPreviewStatus('Rendering preview…', false);

        try {
            const res = await fetch(@json(route('admin.campaigns.preview')), {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json, text/html',
                },
                body: fd,
                redirect: 'manual',
            });

            if (res.status === 422) {
                previewFrame.removeAttribute('srcdoc');
                setPreviewStatus('Fix subject/body and try again.', true);
                return;
            }

            if (res.type === 'opaqueredirect' || res.status === 419 || !res.ok) {
                previewFrame.removeAttribute('srcdoc');
                setPreviewStatus('Preview failed — refresh and retry.', true);
                return;
            }

            const html = await res.text();
            previewFrame.srcdoc = html;
            setPreviewStatus('Click “Preview email” to render the branded message.', false);
        } catch (err) {
            previewFrame.removeAttribute('srcdoc');
            setPreviewStatus('Preview failed — refresh and retry.', true);
        }
    });

    syncAudience();
})();
</script>
@endsection
