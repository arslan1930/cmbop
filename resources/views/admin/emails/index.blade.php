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
    ];
@endphp


<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
        <div>
            <h2 class="mb-1 fw-semibold">Email Center</h2>
            <p class="text-muted mb-0">Monitor delivery, preview templates, and send test emails — without changing live notification flows.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="#ec-tools" class="btn btn-outline-secondary btn-sm"><i class="fa fa-tools me-1"></i> Tools</a>
            <a href="#ec-templates" class="btn btn-primary btn-sm"><i class="fa fa-envelope-open-text me-1"></i> Templates</a>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">📧 Total Emails Sent Today</div>
                    <div class="value">{{ number_format($stats['sent_today']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">📬 Pending Emails</div>
                    <div class="value text-warning">{{ number_format($stats['pending']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">❌ Failed Emails</div>
                    <div class="value text-danger">{{ number_format($stats['failed']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card ec-kpi">
                <div class="card-body">
                    <div class="label">✅ Delivered Emails</div>
                    <div class="value text-success">{{ number_format($stats['delivered']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        {{-- Recent emails --}}
        <div class="col-lg-7">
            <div class="card ec-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Recent Emails</h5>
                        <span class="text-muted small">Last 25 logged sends</span>
                    </div>
                    @if($recentLogs->isEmpty())
                        <p class="text-muted mb-0">No emails logged yet. New sends are captured automatically. Use <strong>Send Test Email</strong> below to verify logging.</p>
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
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentLogs as $log)
                                        <tr>
                                            <td>
                                                <span class="badge bg-{{ $statusBadge[$log->status] ?? 'secondary' }}">
                                                    {{ ucfirst($log->status) }}
                                                </span>
                                            </td>
                                            <td class="small">{{ $log->template_key ?: '—' }}</td>
                                            <td class="small">{{ $log->to_email }}</td>
                                            <td class="small text-truncate" style="max-width:220px;">{{ $log->subject }}</td>
                                            <td class="small text-muted">{{ optional($log->sent_at ?? $log->created_at)->diffForHumans() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- SMTP + Queue --}}
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
                        <dt class="col-5">Username</dt><dd class="col-7">{{ $smtp['username'] ? \Illuminate\Support\Str::mask($smtp['username'], '*', 2) : '—' }}</dd>
                        <dt class="col-5">From</dt><dd class="col-7">{{ $smtp['from_name'] }} &lt;{{ $smtp['from_address'] }}&gt;</dd>
                        <dt class="col-5">Admin email</dt><dd class="col-7">{{ $smtp['admin_email'] ?: 'Not set (ADMIN_EMAIL)' }}</dd>
                    </dl>
                    <p class="small text-muted mt-3 mb-0">
                        <strong>Important:</strong> SMTP credentials stay in <code>.env</code> (MAIL_*), so production secrets are not editable from the browser.
                    </p>
                </div>
            </div>

            <div class="card ec-card" id="ec-tools">
                <div class="card-body">
                    <h5 class="mb-3">Queue & Tools</h5>
                    <div class="row g-2 mb-3 small">
                        <div class="col-6"><div class="border rounded-3 p-2">Queue: <strong>{{ $queue['connection'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Pending jobs: <strong>{{ $queue['pending_jobs'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Failed jobs: <strong>{{ $queue['failed_jobs'] }}</strong></div></div>
                        <div class="col-6"><div class="border rounded-3 p-2">Mail failed jobs: <strong>{{ $queue['mail_failed_jobs'] }}</strong></div></div>
                    </div>

                    <form method="post" action="{{ route('admin.emails.retry', [], false) }}" class="mb-3"
                          data-slb-confirm="Retry failed queue jobs and reset failed email logs?"
                          data-slb-confirm-title="Retry failed emails?"
                          data-slb-confirm-text="Retry now"
                          data-slb-confirm-danger="1">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm w-100" type="submit">
                            <i class="fa fa-redo me-1"></i> Retry Failed Emails
                        </button>
                    </form>

                    <form method="post" action="{{ route('admin.emails.test', [], false) }}" class="border rounded-3 p-3 bg-light">
                        @csrf
                        <h6 class="mb-2">Send Test Email</h6>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Template</label>
                            <select name="template" class="form-select form-select-sm" required>
                                @foreach($templates as $tpl)
                                    <option value="{{ $tpl['key'] }}">{{ $tpl['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small mb-1">Send to</label>
                            <input type="email" name="email" class="form-control form-control-sm" value="{{ auth()->user()->email }}" required>
                        </div>
                        <button class="btn btn-primary btn-sm w-100" type="submit">
                            <i class="fa fa-paper-plane me-1"></i> Send Test Email
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Templates --}}
    <div class="card ec-card mb-4" id="ec-templates">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Templates</h5>
                <span class="text-muted small">{{ $templates->count() }} registered</span>
            </div>
            <div class="row g-3">
                @foreach($templates as $tpl)
                    <div class="col-md-6 col-xl-4">
                        <div class="ec-template">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                <h6>{{ $tpl['name'] }}</h6>
                                <span class="badge bg-{{ $statusBadge[$tpl['status']] ?? 'secondary' }}">{{ $tpl['status'] }}</span>
                            </div>
                            <p>{{ $tpl['description'] }}</p>
                            <div class="small text-muted mt-2">
                                {{ $tpl['category'] }}
                                · Sent {{ number_format($tpl['sent_count']) }}x
                                @if($tpl['last_sent_at'])
                                    · Last {{ \Illuminate\Support\Carbon::parse($tpl['last_sent_at'])->diffForHumans() }}
                                @endif
                            </div>
                            @if(!empty($tpl['importance']))
                                <div class="ec-importance"><strong>Important:</strong> {{ $tpl['importance'] }}</div>
                            @endif
                            <div class="d-flex gap-2 mt-3">
                                @if($tpl['status'] !== 'framework' || $tpl['key'] === 'password_reset')
                                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', $tpl['key'], false) }}">
                                        <i class="fa fa-eye me-1"></i> Preview
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Admin notification toggles --}}
    <div class="card ec-card mb-4" id="ec-settings">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">Notification Settings</h5>
                    <p class="small text-muted mb-0">Enable or disable specific notification types globally. User preferences still apply on top.</p>
                </div>
            </div>
            <form method="post" action="{{ route('admin.emails.settings', [], false) }}">
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
                                <tr>
                                    <td class="fw-semibold">{{ $setting['name'] }}</td>
                                    <td><span class="badge bg-light text-dark">{{ $setting['audience'] }}</span></td>
                                    <td class="small text-muted">{{ $setting['preference'] ?: '—' }}</td>
                                    <td class="text-end">
                                        <div class="form-check form-switch d-inline-flex justify-content-end">
                                            <input class="form-check-input" type="checkbox" name="enabled[{{ $setting['type'] }}]" value="1" @checked($setting['enabled'])>
                                        </div>
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
                    </p>
                    <button class="btn btn-primary btn-sm" type="submit">Save settings</button>
                </div>
            </form>
        </div>
    </div>

    @if($failedLogs->isNotEmpty())
        <div class="card ec-card mb-4">
            <div class="card-body">
                <h5 class="mb-3">Failed Email Log</h5>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>To</th>
                                <th>Template</th>
                                <th>Error</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($failedLogs as $log)
                                <tr>
                                    <td>{{ $log->to_email }}</td>
                                    <td>{{ $log->template_key ?: '—' }}</td>
                                    <td class="small text-danger">{{ \Illuminate\Support\Str::limit($log->error, 120) }}</td>
                                    <td class="small text-muted">{{ $log->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
