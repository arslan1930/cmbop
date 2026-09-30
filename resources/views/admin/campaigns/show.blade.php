@extends('admin.layouts.app')

@section('content')
@php
    $statusFilter = $status ?? '';
    $search = $search ?? '';
    $filterUrl = fn (?string $statusSlug) => route('admin.campaigns.show', array_filter([
        'campaign' => $campaign->id,
        'status' => $statusSlug ?: null,
        'q' => $search !== '' ? $search : null,
    ]));
    $statusBadge = function (string $rowStatus): string {
        return match ($rowStatus) {
            'delivered' => 'bg-success',
            'failed' => 'bg-danger',
            'skipped' => 'bg-warning text-dark',
            'queued' => 'bg-info text-dark',
            default => 'bg-secondary',
        };
    };
@endphp
<div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $campaign->name ?: $campaign->subject }}</h1>
            <p class="text-muted mb-0">Recipient delivery status for this campaign. Failed rows open Email Center — there is no resend-all from here.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ $campaignsBackUrl ?? route('admin.campaigns.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-arrow-left me-1"></i> Back to campaigns
            </a>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="{{ route('admin.campaigns.letter', $campaign) }}">View sent HTML</a>
            <form method="post" action="{{ route('admin.campaigns.clone', $campaign) }}">
                @csrf
                <button class="btn btn-sm btn-outline-primary" type="submit">Use as new draft</button>
            </form>
            <a href="{{ route('admin.emails.index') }}" class="btn btn-sm btn-outline-secondary">
                Email Center
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Subject</div>
                    <div class="fw-semibold">{{ $campaign->subject }}</div>
                    <div class="small text-muted mt-2">{{ $campaign->audienceLabel() }}</div>
                    @if($campaign->inventoryFilterSummary() !== '')
                        <div class="small text-muted">Filtered: {{ $campaign->inventoryFilterSummary() }}</div>
                    @endif
                    <div class="small text-muted">{{ ucfirst($campaign->status) }} · {{ optional($campaign->sent_at)->format('M j, Y g:ia') ?: '—' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="row text-center g-2">
                        <div class="col">
                            <a href="{{ $filterUrl(null) }}" class="text-decoration-none text-reset">
                            <div class="text-muted small">Recipients</div>
                            <div class="fs-5 fw-semibold">{{ number_format($campaign->recipients_count) }}</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="{{ $filterUrl('delivered') }}" class="text-decoration-none text-reset">
                            <div class="text-muted small">Delivered</div>
                            <div class="fs-5 fw-semibold">{{ number_format($counts['delivered'] ?? 0) }}</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="{{ $filterUrl('failed') }}" class="text-decoration-none text-reset">
                            <div class="text-muted small">Failed</div>
                            <div class="fs-5 fw-semibold">{{ number_format($counts['failed'] ?? 0) }}</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="{{ $filterUrl('skipped') }}" class="text-decoration-none text-reset">
                            <div class="text-muted small">Skipped</div>
                            <div class="fs-5 fw-semibold">{{ number_format($counts['skipped'] ?? 0) }}</div>
                            </a>
                        </div>
                        <div class="col">
                            <a href="{{ $filterUrl('pending') }}" class="text-decoration-none text-reset">
                            <div class="text-muted small">Pending / queued</div>
                            <div class="fs-5 fw-semibold">{{ number_format(($counts['pending'] ?? 0) + ($counts['queued'] ?? 0)) }}</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Recipients</strong>
            <form method="get" action="{{ route('admin.campaigns.show', $campaign) }}" class="d-flex flex-wrap gap-2">
                @if($statusFilter !== '')
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                @endif
                <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search name or email" aria-label="Search recipients" style="min-width:12rem">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Search</button>
            </form>
            <div class="d-flex flex-wrap gap-1">
                <a href="{{ $filterUrl(null) }}" class="btn btn-sm {{ $statusFilter === '' ? 'btn-primary' : 'btn-outline-secondary' }}">All</a>
                @foreach(['delivered' => 'Delivered', 'failed' => 'Failed', 'skipped' => 'Skipped', 'queued' => 'Queued', 'pending' => 'Pending'] as $slug => $label)
                    <a href="{{ $filterUrl($slug) }}" class="btn btn-sm {{ $statusFilter === $slug ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Reason</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recipients as $recipient)
                            <tr>
                                <td>
                                    @if($recipient->user)
                                        <a href="{{ route('admin.users.show', $recipient->user_id) }}" class="link-dark">{{ $recipient->user->name }}</a>
                                    @else
                                        <span class="text-muted">User #{{ $recipient->user_id }}</span>
                                    @endif
                                </td>
                                <td class="small">{{ $recipient->email }}</td>
                                <td>
                                    <span class="badge {{ $statusBadge((string) $recipient->status) }}">{{ ucfirst($recipient->status) }}</span>
                                </td>
                                <td class="small">
                                    @if($recipient->status === 'skipped' || $recipient->status === 'failed')
                                        {{ $recipient->skipReasonLabel() }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($recipient->status === 'failed')
                                        @if($recipient->email_log_id)
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.emails.log', $recipient->email_log_id) }}">Email Center</a>
                                        @else
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.emails.index', ['to_email' => $recipient->email, 'template_key' => 'audience_campaign', 'status' => 'failed']) }}#ec-recent">Email Center</a>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No recipients match this filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($recipients->hasPages())
            <div class="card-footer bg-white">{{ $recipients->links() }}</div>
        @endif
    </div>
</div>
@endsection
