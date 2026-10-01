@extends('admin.layouts.app')

@section('content')
@php
    $statusBadge = [
        'delivered' => 'success',
        'pending' => 'warning',
        'failed' => 'danger',
        'active' => 'success',
        'ready' => 'info',
        'framework' => 'secondary',
        'draft' => 'secondary',
        'sending' => 'info',
        'sent' => 'success',
    ];
    $adminEmail = auth()->user()->email;
    $kpiUrls = $kpiUrls ?? [];
@endphp

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
        <div>
            <h2 class="mb-1 fw-semibold">Email Center</h2>
            <p class="text-muted mb-0">Monitor delivery, preview templates, and send test emails — without changing live notification flows.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-outline-secondary btn-sm"><i class="fa fa-bullhorn me-1"></i> Campaigns</a>
            <a href="{{ route('admin.audiences.index') }}" class="btn btn-outline-secondary btn-sm"><i class="fa fa-users me-1"></i> Audiences</a>
            <a href="#ec-tools" class="btn btn-outline-secondary btn-sm"><i class="fa fa-tools me-1"></i> Tools</a>
            <a href="#ec-templates" class="btn btn-primary btn-sm"><i class="fa fa-envelope-open-text me-1"></i> Templates</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <a href="{{ $kpiUrls['sent_today'] ?? route('admin.emails.index').'#ec-recent' }}" class="text-decoration-none text-reset">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">📧 Logged today</div>
                    <div class="value">{{ number_format($stats['sent_today']) }}</div>
                    <p class="ec-kpi-note mb-0">Any status · created today</p>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ $kpiUrls['pending'] ?? route('admin.emails.index', ['status' => 'pending']).'#ec-recent' }}" class="text-decoration-none text-reset">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">📬 Open pending</div>
                    <div class="value text-warning">{{ number_format($stats['pending']) }}</div>
                    <p class="ec-kpi-note mb-0">{{ number_format($stats['pending_today'] ?? 0) }} today · all-time open</p>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ $kpiUrls['failed'] ?? route('admin.emails.index', ['status' => 'failed']).'#ec-recent' }}" class="text-decoration-none text-reset">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">❌ Open failed</div>
                    <div class="value text-danger">{{ number_format($stats['failed']) }}</div>
                    <p class="ec-kpi-note mb-0">{{ number_format($stats['failed_today'] ?? 0) }} today · all-time open</p>
                </div>
            </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="{{ $kpiUrls['delivered'] ?? route('admin.emails.index', ['status' => 'delivered']).'#ec-recent' }}" class="text-decoration-none text-reset">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">✅ Delivered today</div>
                    <div class="value text-success">{{ number_format($stats['delivered']) }}</div>
                    <p class="ec-kpi-note mb-0">SMTP accepted ≠ inbox</p>
                </div>
            </div>
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7" id="ec-recent">
            <div class="card ec-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent Emails</h5>
                        <span class="text-muted small">{{ $recentLogs->total() }} matching · 50 per page</span>
                    </div>
                    <form method="get" action="{{ route('admin.emails.index') }}#ec-recent" class="admin-deposits-filters admin-orders-filters mb-3">
                        <div class="admin-orders-filters__grid">
                        <div>
                            <label class="form-label" for="ecLogStatus">Status</label>
                            <select name="status" id="ecLogStatus" class="form-select">
                                <option value="">All statuses</option>
                                @foreach(['delivered', 'pending', 'failed'] as $status)
                                    <option value="{{ $status }}" @selected(($logFilters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="ecLogSource">Source</label>
                            <select name="source" id="ecLogSource" class="form-select">
                                <option value="">Live + tests</option>
                                <option value="live" @selected(($logFilters['source'] ?? '') === 'live')>Live only</option>
                                <option value="test" @selected(($logFilters['source'] ?? '') === 'test')>Tests only</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="ecLogTemplate">Template</label>
                            <select name="template_key" id="ecLogTemplate" class="form-select">
                                <option value="">All templates</option>
                                @foreach($templates as $tpl)
                                    <option value="{{ $tpl['key'] }}" @selected(($logFilters['template_key'] ?? '') === $tpl['key'])>{{ $tpl['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="ecLogEmail">To email</label>
                            <input type="text" id="ecLogEmail" name="to_email" class="form-control" placeholder="To email" value="{{ $logFilters['to_email'] ?? '' }}">
                        </div>
                        <div>
                            <label class="form-label" for="ecLogFrom">From</label>
                            <input type="date" id="ecLogFrom" name="date_from" class="form-control" value="{{ $logFilters['date_from'] ?? '' }}" aria-label="From date">
                        </div>
                        <div>
                            <label class="form-label" for="ecLogTo">To</label>
                            <input type="date" id="ecLogTo" name="date_to" class="form-control" value="{{ $logFilters['date_to'] ?? '' }}" aria-label="To date">
                        </div>
                        <div class="admin-deposits-filters__actions admin-orders-filters__actions">
                            <div class="d-flex gap-2">
                                <button class="btn btn-primary" type="submit">Filter</button>
                                @if(collect($logFilters)->filter()->isNotEmpty())
                                    <a href="{{ route('admin.emails.index') }}#ec-recent" class="btn btn-outline-secondary">Clear</a>
                                @endif
                            </div>
                        </div>
                        </div>
                    </form>
                    @if($recentLogs->isEmpty())
                        @if(collect($logFilters)->filter()->isNotEmpty())
                            <p class="text-muted mb-0">No emails match these filters.</p>
                        @else
                            <p class="text-muted mb-0">No emails logged yet. New sends are captured automatically. Use <strong>Send Test Email</strong> below to verify logging.</p>
                        @endif
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Template</th>
                                        <th>To</th>
                                        <th>Subject</th>
                                        <th>When</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentLogs as $log)
                                        <tr class="ec-log-row" onclick="window.location='{{ route('admin.emails.log', $log) }}'">
                                            <td>
                                                <span class="badge bg-{{ $statusBadge[$log->status] ?? 'secondary' }}">
                                                    {{ ucfirst($log->status) }}
                                                </span>
                                            </td>
                                            <td class="small">
                                                {{ \App\Support\AdminEmails::templateName($log->template_key) }}
                                                @if(\App\Support\AdminEmails::isTestLog($log))
                                                    <span class="badge bg-info text-dark">Test</span>
                                                @endif
                                            </td>
                                            <td class="small">{{ $log->to_email }}</td>
                                            <td class="small text-truncate" style="max-width:220px;">{{ $log->subject }}</td>
                                            <td class="small text-muted">{{ optional($log->sent_at ?? $log->created_at)->diffForHumans() }}</td>
                                            <td class="text-end" onclick="event.stopPropagation()">
                                                <div class="d-flex justify-content-end gap-2">
                                                    <a href="{{ route('admin.emails.log', $log) }}" class="small">View</a>
                                                    @if($log->status === \App\Models\EmailLog::STATUS_FAILED)
                                                        <form method="post" action="{{ route('admin.emails.retry') }}"
                                                              data-slb-confirm="Retry this failed email?"
                                                              data-slb-confirm-title="Retry email?"
                                                              data-slb-confirm-text="Retry">
                                                            @csrf
                                                            <input type="hidden" name="log_id" value="{{ $log->id }}">
                                                            <button class="btn btn-link btn-sm p-0 text-danger" type="submit">Retry</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">{{ $recentLogs->links() }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card ec-card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">SMTP Settings</h5>
                        <span class="ec-pill {{ $smtp['configured'] ? 'ok' : 'warn' }}">
                            {{ $smtp['configured'] ? 'Production mailer' : 'Log driver / setup needed' }}
                        </span>
                    </div>
                    <dl class="row ec-smtp mb-0 small">
                        <dt class="col-5">Mailer</dt><dd class="col-7">{{ $smtp['mailer'] }}</dd>
                        <dt class="col-5">Host</dt><dd class="col-7">{{ $smtp['host'] ?: '—' }}</dd>
                        <dt class="col-5">Port</dt><dd class="col-7">{{ $smtp['port'] ?: '—' }}</dd>
                        <dt class="col-5">Encryption</dt><dd class="col-7">{{ $smtp['encryption'] ?: '—' }}</dd>
                        <dt class="col-5">Username</dt><dd class="col-7">{{ $smtp['username'] ? \Illuminate\Support\Str::mask($smtp['username'], '*', 2) : '—' }}</dd>
                        <dt class="col-5">From</dt><dd class="col-7">{{ $smtp['from_name'] }} &lt;{{ $smtp['from_address'] }}&gt;</dd>
                        <dt class="col-5">Admin email</dt><dd class="col-7">{{ $smtp['admin_email'] ?: 'Not set (ADMIN_EMAIL)' }}</dd>
                    </dl>
                    <p class="small text-muted mt-3 mb-0">
                        <strong>Important:</strong> SMTP credentials stay in <code>.env</code> (MAIL_*), so production secrets are not editable from the browser.
                    </p>
                </div>
            </div>

            <div class="card ec-card mb-4" id="ec-tools">
                <div class="card-body">
                    <h5 class="mb-3">Queue health</h5>
                    <div class="row g-2 mb-3 small">
                        <div class="col-6"><div class="border rounded-3 p-2">App queue: <strong>{{ $queue['connection'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Mail connection: <strong>{{ $queue['mail_connection'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Mail queue name: <strong>{{ $queue['mail_queue'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Auto-drain: <strong>{{ $queue['auto_drain'] ? 'on' : 'off' }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Mail jobs pending: <strong>{{ $queue['mail_pending_jobs'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Mail jobs failed: <strong>{{ $queue['mail_failed_jobs'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">All failed jobs (any queue): <strong>{{ $queue['failed_jobs'] }}</strong></div></div>
                    </div>
                    <p class="small text-muted mb-3">Worker must include <code>emails</code> (<code>queue:work --queue=default,emails</code>) <strong>or</strong> leave auto-drain on. Retry below only requeues <strong>mail</strong> failed jobs.</p>

                    <form method="post" action="{{ route('admin.emails.retry') }}" class="mb-3"
                          data-slb-confirm="Retry failed mail queue jobs only? Email logs stay failed until a send succeeds. Other failed jobs are left untouched."
                          data-slb-confirm-title="Retry failed mail jobs?"
                          data-slb-confirm-text="Retry now"
                          data-slb-confirm-danger="1">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm w-100" type="submit">
                            <i class="fa fa-redo me-1"></i> Retry failed mail jobs
                        </button>
                    </form>

                    <form method="post" action="{{ route('admin.emails.test') }}" class="border rounded-3 p-3 bg-light admin-deposits-filters" data-admin-filter-live="1">
                        @csrf
                        <h6 class="mb-2">Send Test Email</h6>
                        <div class="mb-2">
                            <label class="form-label" for="ecTestTemplate">Template</label>
                            <select name="template" id="ecTestTemplate" class="form-select" required>
                                @foreach($templatesByCategory as $category => $group)
                                    <optgroup label="{{ $category }}">
                                        @foreach($group as $tpl)
                                            <option value="{{ $tpl['key'] }}">{{ $tpl['name'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2 d-none" id="ecTestAudienceWrap">
                            <label class="form-label" for="ecTestAudience">Variant</label>
                            <select name="audience" id="ecTestAudience" class="form-select">
                                <option value="">Default sample</option>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Send to</label>
                            <input type="email" name="email" class="form-control" value="{{ $adminEmail }}" readonly required>
                        </div>
                        <p class="small text-muted mb-2">Sends a synthetic preview to your admin inbox — not a live customer email. After send, Recent opens on that log.</p>
                        <button class="btn btn-primary w-100" type="submit">
                            <i class="fa fa-paper-plane me-1"></i> Send Test Email
                        </button>
                    </form>
                </div>
            </div>

            <div class="card ec-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Campaigns</h5>
                        <a href="{{ route('admin.campaigns.index') }}" class="small">Open Campaigns</a>
                    </div>
                    @if($recentCampaigns->isEmpty())
                        <p class="text-muted small mb-0">No campaigns yet.</p>
                    @else
                        <ul class="list-unstyled mb-0 small">
                            @foreach($recentCampaigns as $campaign)
                                <li class="d-flex justify-content-between gap-2 py-1 border-bottom">
                                    <span>
                                        <a href="{{ route('admin.campaigns.show', $campaign) }}"><strong>{{ $campaign->name }}</strong></a>
                                        <span class="text-muted">· {{ $campaign->audienceLabel() }}</span>
                                    </span>
                                    <span>
                                        {{ (int) $campaign->sent_count }} sent
                                        @if((int) ($campaign->failed_recipients_count ?? 0) > 0)
                                            · {{ (int) $campaign->failed_recipients_count }} failed
                                        @endif
                                        <span class="badge bg-{{ $statusBadge[$campaign->status] ?? 'secondary' }}">{{ $campaign->status }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card ec-card mb-4" id="ec-templates">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0">Templates</h5>
                <div class="d-flex align-items-center gap-2">
                    <input type="search" id="ec-template-search" class="form-control form-control-sm ec-search" placeholder="Search templates…" aria-label="Search templates">
                    <span class="text-muted small">{{ $templates->count() }} registered</span>
                </div>
            </div>
            @foreach($templatesByCategory as $category => $group)
                <h6 class="ec-group-title">{{ $category }}</h6>
                <div class="row g-3 mb-3">
                    @foreach($group as $tpl)
                        <div class="col-md-6 col-xl-4 ec-template-col"
                             data-name="{{ strtolower($tpl['name']) }}"
                             data-key="{{ strtolower($tpl['key']) }}"
                             data-description="{{ strtolower($tpl['description']) }}">
                            <div class="ec-template">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <h6>{{ $tpl['name'] }}</h6>
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        @if(!empty($tpl['framework']))
                                            <span class="badge bg-secondary">framework</span>
                                        @elseif(empty($tpl['enabled']))
                                            <span class="badge bg-warning text-dark">disabled</span>
                                        @else
                                            <span class="badge bg-{{ $statusBadge[$tpl['status']] ?? 'secondary' }}">{{ $tpl['status'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <p>{{ $tpl['description'] }}</p>
                                <div class="small text-muted mt-2">
                                    {{ $tpl['category'] }}
                                    · Sent {{ number_format($tpl['sent_count']) }}x
                                    @if($tpl['last_sent_at'] instanceof \DateTimeInterface)
                                        · Last {{ $tpl['last_sent_at']->diffForHumans() }}
                                    @endif
                                </div>
                                @if(!empty($tpl['importance']))
                                    <div class="ec-importance"><strong>Important:</strong> {{ $tpl['importance'] }}</div>
                                @endif
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    @if($tpl['status'] !== 'framework' || in_array($tpl['key'], ['password_reset', 'email_verification'], true))
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', $tpl['key']) }}">
                                            <i class="fa fa-eye me-1"></i> Preview
                                        </a>
                                    @endif
                                    @if($tpl['key'] === 'order_status_changed')
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', ['key' => $tpl['key'], 'audience' => 'publisher']) }}">Publisher</a>
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', ['key' => $tpl['key'], 'audience' => 'admin']) }}">Admin</a>
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', ['key' => $tpl['key'], 'audience' => 'completed']) }}">Completed</a>
                                    @endif
                                    @if($tpl['key'] === 'welcome')
                                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', ['key' => $tpl['key'], 'audience' => 'publisher']) }}">Publisher</a>
                                    @endif
                                    @if(($tpl['key'] ?? '') !== 'audience_campaign')
                                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.campaigns.index', ['template' => $tpl['key']]) }}">
                                            Use in Campaigns
                                        </a>
                                    @endif
                                    <form method="post" action="{{ route('admin.emails.test') }}"
                                          data-slb-confirm="Send a synthetic {{ $tpl['name'] }} preview to {{ $adminEmail }}?"
                                          data-slb-confirm-title="Send test to me?"
                                          data-slb-confirm-text="Send test">
                                        @csrf
                                        <input type="hidden" name="template" value="{{ $tpl['key'] }}">
                                        <input type="hidden" name="email" value="{{ $adminEmail }}">
                                        <button class="btn btn-sm btn-outline-primary" type="submit">
                                            <i class="fa fa-paper-plane me-1"></i> Send test to me
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
            <p class="small text-muted ec-search-empty d-none mb-0">No templates match that search.</p>
        </div>
    </div>

    <div class="card ec-card mb-4" id="ec-settings">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Notification Settings</h5>
                    <p class="small text-muted mb-0">Templates above are the catalog (preview / test). This table is the global on/off. User profile prefs still apply. Campaigns also honor marketing opt-out when “respect preferences” is on.</p>
                </div>
                <div class="admin-deposits-filters d-flex flex-wrap gap-2" data-admin-filter-live="1" style="min-width:12rem;max-width:28rem">
                <input type="search" id="ec-settings-search" class="form-control" placeholder="Search settings…" aria-label="Search notification settings">
                <select id="ec-settings-audience" class="form-select" aria-label="Filter settings by audience">
                    <option value="">All audiences</option>
                    @foreach($settings->pluck('audience')->unique()->sort() as $audience)
                        <option value="{{ $audience }}">{{ $audience }}</option>
                    @endforeach
                </select>
                </div>
            </div>
            <form method="post" action="{{ route('admin.emails.settings') }}" id="ec-settings-form" data-ec-critical="{{ implode(',', $criticalTypes) }}">
                @csrf
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Notification</th>
                                <th>Audience</th>
                                <th>User preference</th>
                                <th class="text-end">Enabled</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($settings as $setting)
                                <tr data-audience="{{ $setting['audience'] }}" data-name="{{ strtolower($setting['name'].' '.$setting['type']) }}">
                                    <td class="fw-semibold">
                                        {{ $setting['name'] }}
                                        @if(\App\Support\EmailCatalog::get($setting['type']))
                                            <a class="small fw-normal ms-1" target="_blank" href="{{ route('admin.emails.preview', $setting['type']) }}">Preview</a>
                                        @endif
                                    </td>
                                    <td><span class="badge bg-light text-dark">{{ $setting['audience'] }}</span></td>
                                    <td class="small text-muted">{{ $setting['preference_label'] ?: '—' }}</td>
                                    <td class="text-end">
                                        @if(!empty($setting['framework']))
                                            <div class="d-flex flex-column align-items-end gap-1">
                                                <div class="form-check form-switch m-0">
                                                    <input class="form-check-input" type="checkbox" checked disabled aria-label="{{ $setting['name'] }} (Laravel auth)">
                                                </div>
                                                <span class="small text-muted">Managed by Laravel auth — this toggle does not stop verify/reset mail.</span>
                                            </div>
                                        @else
                                            <div class="form-check form-switch d-inline-flex justify-content-end">
                                                <input type="hidden" name="enabled[{{ $setting['type'] }}]" value="0">
                                                <input class="form-check-input" type="checkbox" name="enabled[{{ $setting['type'] }}]" value="1" @checked($setting['enabled'])
                                                    @if(in_array($setting['type'], $criticalTypes, true)) data-ec-was-enabled="{{ $setting['enabled'] ? '1' : '0' }}" @endif>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <p class="small text-muted mb-0">
                        Sender: <strong>{{ $brand['sender_name'] ?? '' }}</strong> &lt;{{ $brand['sender_email'] ?? '' }}&gt;
                        · Reply-To: {{ $brand['reply_to'] ?? '—' }}
                        · Support: {{ $brand['support_email'] ?? '—' }}
                        <br><strong>Important:</strong> Change sender/reply-to/support via <code>.env</code> (<code>MAIL_*</code>, <code>MAIL_SUPPORT_EMAIL</code>, <code>MAIL_REPLY_TO_ADDRESS</code>).
                        <br>Ops notes in the repo: <code>docs/ops-mail-reminders.md</code>, <code>docs/admin-campaigns.md</code>.
                    </p>
                    <button class="btn btn-primary btn-sm" type="submit">Save settings</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card ec-card mb-4" id="ec-failed">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Latest failed logs</h5>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ \App\Support\AdminEmails::listUrl(['status' => 'failed']) }}" class="small">All failed in Recent</a>
                    @if(($queue['mail_failed_jobs'] ?? 0) > 0)
                        <a href="#ec-tools" class="small">Mail jobs failed ({{ $queue['mail_failed_jobs'] }})</a>
                    @endif
                </div>
            </div>
            @if($failedLogs->isEmpty())
                <p class="text-muted mb-0">No failed sends in the log — also check mail jobs failed in Queue health.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>To</th>
                                <th>Template</th>
                                <th>Error</th>
                                <th>When</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($failedLogs as $log)
                                <tr>
                                    <td>{{ $log->to_email }}</td>
                                    <td class="small">
                                        {{ \App\Support\AdminEmails::templateName($log->template_key) }}
                                        @if(\App\Support\AdminEmails::isTestLog($log))
                                            <span class="badge bg-info text-dark">Test</span>
                                        @endif
                                    </td>
                                    <td class="small text-danger">
                                        <span>{{ \Illuminate\Support\Str::limit($log->error, 120) }}</span>
                                        @if(strlen((string) $log->error) > 120)
                                            <details class="ec-error-expand">
                                                <summary>Expand</summary>
                                                <pre class="mb-0">{{ $log->error }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                    <td class="small text-muted">{{ $log->created_at?->diffForHumans() }}</td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-2">
                                            <a href="{{ route('admin.emails.log', $log) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                            <form method="post" action="{{ route('admin.emails.retry') }}"
                                                  data-slb-confirm="Retry this failed email?"
                                                  data-slb-confirm-title="Retry email?"
                                                  data-slb-confirm-text="Retry">
                                                @csrf
                                                <input type="hidden" name="log_id" value="{{ $log->id }}">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Retry</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
<script>
(function () {
    var testTemplate = document.getElementById('ecTestTemplate');
    var testAudienceWrap = document.getElementById('ecTestAudienceWrap');
    var testAudience = document.getElementById('ecTestAudience');
    var variantOptions = {
        welcome: [['', 'Advertiser (default)'], ['publisher', 'Publisher']],
        order_status_changed: [['', 'Advertiser processing'], ['publisher', 'Publisher'], ['admin', 'Admin'], ['completed', 'Advertiser completed']]
    };
    function syncTestAudience() {
        if (!testTemplate || !testAudienceWrap || !testAudience) return;
        var key = testTemplate.value;
        var opts = variantOptions[key] || [];
        testAudience.innerHTML = '';
        if (!opts.length) {
            testAudienceWrap.classList.add('d-none');
            testAudience.removeAttribute('name');
            testAudience.dispatchEvent(new Event('admin-select-refresh'));
            return;
        }
        testAudience.setAttribute('name', 'audience');
        opts.forEach(function (pair) {
            var option = document.createElement('option');
            option.value = pair[0];
            option.textContent = pair[1];
            testAudience.appendChild(option);
        });
        testAudienceWrap.classList.remove('d-none');
        testAudience.dispatchEvent(new Event('admin-select-refresh'));
    }
    if (testTemplate) {
        testTemplate.addEventListener('change', syncTestAudience);
        syncTestAudience();
    }

    var search = document.getElementById('ec-template-search');
    var empty = document.querySelector('.ec-search-empty');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var shown = 0;
            document.querySelectorAll('.ec-template-col').forEach(function (col) {
                var hay = (col.getAttribute('data-name') || '') + ' ' + (col.getAttribute('data-key') || '') + ' ' + (col.getAttribute('data-description') || '');
                var match = !q || hay.indexOf(q) !== -1;
                col.classList.toggle('d-none', !match);
                if (match) shown++;
            });
            document.querySelectorAll('.ec-group-title').forEach(function (title) {
                var row = title.nextElementSibling;
                var any = row && row.querySelector('.ec-template-col:not(.d-none)');
                title.classList.toggle('d-none', !any);
                if (row) row.classList.toggle('d-none', !any);
            });
            if (empty) empty.classList.toggle('d-none', shown > 0);
        });
    }

    var audience = document.getElementById('ec-settings-audience');
    var settingsSearch = document.getElementById('ec-settings-search');
    function filterSettingsRows() {
        var value = audience ? audience.value : '';
        var q = settingsSearch ? settingsSearch.value.trim().toLowerCase() : '';
        document.querySelectorAll('#ec-settings-form tbody tr').forEach(function (row) {
            var audienceOk = !value || row.getAttribute('data-audience') === value;
            var hay = row.getAttribute('data-name') || '';
            var searchOk = !q || hay.indexOf(q) !== -1;
            row.classList.toggle('d-none', !(audienceOk && searchOk));
        });
    }
    if (audience) {
        audience.addEventListener('change', filterSettingsRows);
    }
    if (settingsSearch) {
        settingsSearch.addEventListener('input', filterSettingsRows);
    }

    var form = document.getElementById('ec-settings-form');
    if (form) {
        var syncCriticalConfirm = function () {
            var critical = (form.getAttribute('data-ec-critical') || '').split(',').filter(Boolean);
            var disabling = [];
            critical.forEach(function (type) {
                var name = 'enabled[' + type + ']';
                var box = Array.prototype.find.call(form.querySelectorAll('input[type="checkbox"]'), function (el) {
                    return el.name === name;
                });
                if (box && !box.checked && box.getAttribute('data-ec-was-enabled') === '1') {
                    disabling.push(type);
                }
            });
            if (disabling.length) {
                form.setAttribute('data-slb-confirm', 'This will disable: ' + disabling.join(', ') + '. Continue?');
                form.setAttribute('data-slb-confirm-title', 'Disable critical notifications?');
                form.setAttribute('data-slb-confirm-text', 'Save settings');
                form.setAttribute('data-slb-confirm-danger', '1');
            } else {
                form.removeAttribute('data-slb-confirm');
                form.removeAttribute('data-slb-confirm-title');
                form.removeAttribute('data-slb-confirm-text');
                form.removeAttribute('data-slb-confirm-danger');
            }
        };

        form.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
            box.addEventListener('change', syncCriticalConfirm);
        });
        syncCriticalConfirm();

        // slb-confirm.js binds document capture on DOMContentLoaded. Register
        // this earlier (inline, during parse) so attributes exist first.
        document.addEventListener('submit', function (e) {
            if (e.target === form) {
                syncCriticalConfirm();
            }
        }, true);
    }
})();
</script>
@endsection
