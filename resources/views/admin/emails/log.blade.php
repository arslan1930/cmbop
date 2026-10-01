@extends('admin.layouts.app')

@section('content')
@php
    $statusBadge = [
        'delivered' => 'success',
        'pending' => 'warning',
        'failed' => 'danger',
    ];
@endphp

<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h2 class="mb-1 fw-semibold">Email log</h2>
            <p class="text-muted mb-0">Delivery record for ops — To and subject only, no stored HTML body.</p>
        </div>
        <a href="{{ $emailsBackUrl ?? route('admin.emails.index').'#ec-recent' }}" class="btn btn-outline-secondary btn-sm">Back to Email Center</a>
    </div>

    <div class="card ec-card">
        <div class="card-body">
            <dl class="row ec-smtp mb-0">
                <dt class="col-sm-3">Status</dt>
                <dd class="col-sm-9">
                    <span class="badge bg-{{ $statusBadge[$log->status] ?? 'secondary' }}">{{ ucfirst($log->status) }}</span>
                </dd>
                <dt class="col-sm-3">To</dt>
                <dd class="col-sm-9">{{ $log->to_email }}@if($log->to_name) <span class="text-muted">({{ $log->to_name }})</span>@endif</dd>
                <dt class="col-sm-3">Subject</dt>
                <dd class="col-sm-9">{{ $log->subject ?: '—' }}</dd>
                <dt class="col-sm-3">Template</dt>
                <dd class="col-sm-9">
                    {{ \App\Support\AdminEmails::templateName($log->template_key) }}
                    @if($log->template_key)
                        <code class="ms-1">{{ $log->template_key }}</code>
                    @endif
                    @if(\App\Support\AdminEmails::isTestLog($log))
                        <span class="badge bg-info text-dark">Test</span>
                    @endif
                </dd>
                <dt class="col-sm-3">Mailable</dt>
                <dd class="col-sm-9"><code>{{ $log->mailable ?: '—' }}</code></dd>
                <dt class="col-sm-3">Notification type</dt>
                <dd class="col-sm-9">{{ $log->notification_type ?: '—' }}</dd>
                <dt class="col-sm-3">Dedupe key</dt>
                <dd class="col-sm-9"><code>{{ $log->dedupe_key ?: '—' }}</code></dd>
                <dt class="col-sm-3">Audience</dt>
                <dd class="col-sm-9">{{ $log->audience ?: '—' }}</dd>
                <dt class="col-sm-3">Attempts</dt>
                <dd class="col-sm-9">{{ $log->attempts }}</dd>
                <dt class="col-sm-3">Sent</dt>
                <dd class="col-sm-9">{{ optional($log->sent_at)->toDateTimeString() ?: '—' }}</dd>
                <dt class="col-sm-3">Created</dt>
                <dd class="col-sm-9">{{ optional($log->created_at)->toDateTimeString() ?: '—' }}</dd>
                @if($relatedUser)
                    <dt class="col-sm-3">Related user</dt>
                    <dd class="col-sm-9">
                        <a href="{{ route('admin.users.show', $relatedUser) }}">{{ $relatedUser->name }} · #{{ $relatedUser->id }}</a>
                    </dd>
                @endif
                @if(!empty($relatedCampaignId))
                    <dt class="col-sm-3">Campaign</dt>
                    <dd class="col-sm-9"><a href="{{ route('admin.campaigns.show', $relatedCampaignId) }}">Campaign #{{ $relatedCampaignId }}</a></dd>
                @endif
                @if(!empty($relatedOrderId))
                    <dt class="col-sm-3">Order</dt>
                    <dd class="col-sm-9"><a href="{{ route('admin.orders.show', $relatedOrderId) }}">Order #{{ $relatedOrderId }}</a></dd>
                @endif
                <dt class="col-sm-3">Error</dt>
                <dd class="col-sm-9">
                    @if($log->error)
                        <pre class="ec-error-expand mb-0">{{ $log->error }}</pre>
                    @else
                        —
                    @endif
                </dd>
                <dt class="col-sm-3">Meta</dt>
                <dd class="col-sm-9">
                    @if($log->meta)
                        <pre class="ec-error-expand mb-0">{{ json_encode($log->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @else
                        —
                    @endif
                </dd>
            </dl>

            @if($log->template_key)
                @php
                    $logPreviewAudience = \App\Support\AdminEmails::previewAudience($log->audience);
                    $logPreviewQuery = array_filter([
                        'key' => $log->template_key,
                        'audience' => $logPreviewAudience,
                    ]);
                @endphp
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.emails.preview', $logPreviewQuery) }}">Preview sample</a>
                    <form method="post" action="{{ route('admin.emails.test') }}"
                          data-slb-confirm="Send a synthetic {{ \App\Support\AdminEmails::templateName($log->template_key) }} preview to {{ auth()->user()->email }}?"
                          data-slb-confirm-title="Send test to me?"
                          data-slb-confirm-text="Send test">
                        @csrf
                        <input type="hidden" name="template" value="{{ $log->template_key }}">
                        <input type="hidden" name="email" value="{{ auth()->user()->email }}">
                        @if($logPreviewAudience)
                            <input type="hidden" name="audience" value="{{ $logPreviewAudience }}">
                        @endif
                        <button class="btn btn-sm btn-outline-primary" type="submit">Send test to me</button>
                    </form>
                </div>
            @endif

            @if($log->status === \App\Models\EmailLog::STATUS_FAILED)
                <form method="post" action="{{ route('admin.emails.retry') }}" class="mt-3"
                      data-slb-confirm="Retry this failed email?"
                      data-slb-confirm-title="Retry email?"
                      data-slb-confirm-text="Retry">
                    @csrf
                    <input type="hidden" name="log_id" value="{{ $log->id }}">
                    <button class="btn btn-outline-danger btn-sm" type="submit">Retry</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
