@extends('admin.layouts.app')

@section('content')
@php
    $item = $order->items->first();
    $site = $item?->site;
    $publisher = $site?->publisher;
    $statusClass = match ($order->status) {
        'completed' => 'success',
        'cancelled' => 'danger',
        'review' => 'info',
        'processing' => 'primary',
        'scheduled' => 'warning',
        default => 'secondary',
    };
    $payClass = match ($order->payment_status) {
        'paid' => 'success',
        'failed' => 'danger',
        'refunded' => 'secondary',
        default => 'warning',
    };
@endphp
<div class="container-fluid">
    @include('admin.partials.page-header', [
        'title' => 'Order #' . $order->order_number,
        'subtitle' => 'Read-only ops view · payment changes use Order Payments',
        'actionUrl' => route('admin.orders.index'),
        'actionLabel' => 'All orders',
        'actionIcon' => 'fa-arrow-left',
    ])

    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge text-bg-{{ $statusClass }}">Status: {{ $order->status }}</span>
        <span class="badge text-bg-{{ $payClass }}">Payment: {{ $order->payment_status }}</span>
        <span class="badge text-bg-light text-dark border">{{ strtoupper((string) $order->payment_method) ?: '—' }}</span>
        <span class="badge text-bg-light text-dark border">€{{ number_format((float) $order->total_amount, 2) }}</span>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-overview-btn" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab">Overview</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-chat-btn" data-bs-toggle="tab" data-bs-target="#tab-chat" type="button" role="tab">
                Chat
                @if($messages->count())
                    <span class="badge bg-secondary">{{ $messages->count() }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-activity-btn" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab">
                Activity
                @if(count($activities))
                    <span class="badge bg-secondary">{{ count($activities) }}</span>
                @endif
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-payment-btn" data-bs-toggle="tab" data-bs-target="#tab-payment" type="button" role="tab">Payment</button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0"><strong>Parties</strong></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="small text-muted">Advertiser</div>
                                <div class="fw-semibold">{{ $order->user->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $order->user->email ?? '' }}</div>
                            </div>
                            <div>
                                <div class="small text-muted">Publisher</div>
                                <div class="fw-semibold">{{ $publisher->name ?? '—' }}</div>
                                <div class="small text-muted">{{ $publisher->email ?? '' }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0"><strong>Placement</strong></div>
                        <div class="card-body">
                            <div class="mb-2"><span class="text-muted small">Site</span><div class="fw-semibold">{{ $item->site_name ?? ($site->site_name ?? '—') }}</div></div>
                            @if($item?->site_url || $site?->site_url)
                                <div class="mb-2"><a href="{{ $item->site_url ?? $site->site_url }}" target="_blank" rel="noopener">{{ $item->site_url ?? $site->site_url }}</a></div>
                            @endif
                            <div class="mb-2"><span class="text-muted small">Live URL</span>
                                <div>
                                    @if($item?->live_url)
                                        <a class="live-url" href="{{ $item->live_url }}" target="_blank" rel="noopener">{{ $item->live_url }}</a>
                                    @else
                                        <span class="text-muted">Not submitted</span>
                                    @endif
                                </div>
                            </div>
                            <div class="mb-2"><span class="text-muted small">Modification requested</span>
                                <div>{{ $item?->modification_requested ?: 'no' }}</div>
                            </div>
                            @if($item?->content_link)
                                <div><span class="text-muted small">Content</span>
                                    <div><a href="{{ $item->content_link }}" target="_blank" rel="noopener">Open content link</a></div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-0"><strong>Order details</strong></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3"><span class="text-muted small">Reference</span><div>{{ $order->reference_code ?: '—' }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Created</span><div>{{ optional($order->created_at)->format('M j, Y g:i A') }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Paid at</span><div>{{ optional($order->paid_at)->format('M j, Y g:i A') ?: '—' }}</div></div>
                                <div class="col-md-3"><span class="text-muted small">Subtotal / Tax / Total</span>
                                    <div>€{{ number_format((float) $order->subtotal, 2) }} · €{{ number_format((float) $order->tax, 2) }} · €{{ number_format((float) $order->total_amount, 2) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-chat" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center">
                    <strong>Order chat</strong>
                    <span class="badge text-bg-light text-dark border">Read-only</span>
                </div>
                <div class="card-body" style="max-height: 520px; overflow-y: auto;">
                    @forelse($messages as $msg)
                        @php
                            $isAdvertiser = $msg->sender_type === 'advertiser';
                        @endphp
                        <div class="d-flex {{ $isAdvertiser ? 'justify-content-start' : 'justify-content-end' }} mb-3">
                            <div class="border rounded-3 px-3 py-2 {{ $isAdvertiser ? 'bg-light' : 'bg-primary-subtle' }}" style="max-width: 75%;">
                                <div class="small text-muted mb-1">
                                    {{ $msg->user->name ?? ucfirst($msg->sender_type) }}
                                    · {{ ucfirst($msg->sender_type) }}
                                    · {{ optional($msg->created_at)->format('M j, Y g:i A') }}
                                </div>
                                <div>{{ $msg->message }}</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-5">
                            <i class="fa fa-comments fa-2x mb-2"></i>
                            <p class="mb-0">No messages on this order yet.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-activity" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Activity timeline</strong></div>
                <div class="card-body">
                    @forelse($activities as $activity)
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="pt-1">
                                <span class="badge text-bg-{{ $activity['badge_color'] ?? 'secondary' }}">
                                    <i class="fa fa-{{ $activity['icon'] ?? 'circle' }}"></i>
                                </span>
                            </div>
                            <div>
                                <div class="fw-semibold">{{ $activity['title'] ?? 'Event' }}</div>
                                @if(!empty($activity['description']))
                                    <div class="small">{{ $activity['description'] }}</div>
                                @endif
                                <div class="small text-muted">
                                    {{ $activity['actor_name'] ?? 'System' }}
                                    @if(!empty($activity['actor_role'])) · {{ $activity['actor_role'] }} @endif
                                    · {{ $activity['exact_time'] ?? ($activity['relative_time'] ?? '') }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-muted py-4">No activity recorded yet.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="tab-payment" role="tabpanel">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0"><strong>Payment summary</strong></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><span class="text-muted small">Payment status</span><div class="fw-semibold">{{ $order->payment_status }}</div></div>
                        <div class="col-md-3"><span class="text-muted small">Method</span><div class="fw-semibold">{{ $order->payment_method ?: '—' }}</div></div>
                        <div class="col-md-3"><span class="text-muted small">Total</span><div class="fw-semibold">€{{ number_format((float) $order->total_amount, 2) }}</div></div>
                        <div class="col-md-3"><span class="text-muted small">Paid at</span><div>{{ optional($order->paid_at)->format('M j, Y g:i A') ?: '—' }}</div></div>
                    </div>
                    <p class="text-muted small mb-3">
                        To mark paid, failed, or refunded, use the Order Payments tools. This screen is inspection-only.
                    </p>
                    <a href="{{ route('admin.payments') }}" class="btn btn-primary btn-sm">
                        <i class="fa fa-money-bill me-1"></i> Open Order Payments
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
